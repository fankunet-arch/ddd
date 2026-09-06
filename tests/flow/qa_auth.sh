#!/bin/bash
# 体检 5：登录与会话
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# 复用 drinkflow.sh 里的数据库桩，按脚本自己的位置找，别依赖当前工作目录
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets,tests} "$T"/
sed -n '/^cat > "\$T\/lib\/db.php"/,/^PHP$/p' "$HERE/drinkflow.sh" | sed '1d;$d' > "$T/lib/db.php"
PORT=8214
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
fails=0
chk(){ if [ "$2" = "$3" ]; then echo "  ✓ $1"; else echo "  ✗ $1（得到 $2，期望 $3）"; fails=$((fails+1)); fi }

echo "体检 5：登录与会话"
# 1. 每个页面未登录都必须跳转
for p in index.php dish.php station.php open.php tests/checkdb.php; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$p")
  chk "未登录访问 $p 被拦截" "$code" "302"
done
# env.php 是排障刚需，必须不要求登录
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/tests/env.php")
chk "env.php 不要求登录（排障刚需）" "$code" "200"
# env.php 不得泄漏任何业务/连接信息
env=$(curl -s "http://127.0.0.1:$PORT/tests/env.php")
if echo "$env" | grep -qi '192\.168\|report_ro\|coolroid\|password'; then
  echo "  ✗ env.php 泄漏了连接信息"; fails=$((fails+1))
else echo "  ✓ env.php 未泄漏连接信息"; fi

J="$T/c.txt"
# 2. 密码错误必须失败，且不能透露密码是否存在
wrongtok=$(curl -s -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
bad=$(curl -s -b "$J" -c "$J" -d "csrf=$wrongtok&password=错的" "http://127.0.0.1:$PORT/login.php")
if echo "$bad" | grep -q '密码'; then echo "  ✓ 错误密码被拒绝"; else echo "  ✗ 错误密码未被拒绝"; fails=$((fails+1)); fi
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
chk "错误密码后仍未登录" "$code" "302"

# 3. 无 CSRF 的登录必须失败
nocsrf=$(curl -s -c "$J.2" -d "password=pw" "http://127.0.0.1:$PORT/login.php")
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J.2" "http://127.0.0.1:$PORT/open.php")
chk "无 CSRF 的登录不生效" "$code" "302"

# 4. 正常登录
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
# 会话 Cookie 名是自定义的，别写死 PHPSESSID —— 直接取 jar 里最后一条
sid(){ awk -F'\t' 'NF>=7{print $7}' "$1" | tail -1; }
before=$(sid "$J")
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw" "http://127.0.0.1:$PORT/login.php"
after=$(sid "$J")
if [ -n "$before" ] && [ "$before" != "$after" ]; then echo "  ✓ 登录后重建会话 ID（防会话固定）"
else echo "  ✗ 登录后未重建会话 ID（before=$before after=$after）"; fails=$((fails+1)); fi
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "http://127.0.0.1:$PORT/open.php")
chk "登录后可访问" "$code" "200"

# 5. Cookie 必须是 HttpOnly
if grep -q '#HttpOnly_' "$J"; then echo "  ✓ 会话 Cookie 是 HttpOnly"
else echo "  ✗ 会话 Cookie 缺 HttpOnly"; fails=$((fails+1)); fi
# 会话 Cookie 不叫 PHPSESSID，少暴露一点技术栈
if [ "$(awk -F'\t' 'NF>=7{print $6}' "$J" | tail -1)" != "PHPSESSID" ]; then
  echo "  ✓ 会话 Cookie 用了自定义名"
else echo "  ✗ 会话 Cookie 仍是默认的 PHPSESSID"; fails=$((fails+1)); fi
# SameSite=Lax：手机上 POST 后跳转仍能带上 Cookie，同时挡掉跨站请求
hdr=$(curl -s -D - -o /dev/null "http://127.0.0.1:$PORT/login.php" | grep -i 'set-cookie')
if echo "$hdr" | grep -qi 'samesite'; then echo "  ✓ 会话 Cookie 设了 SameSite"
else echo "  ✗ 会话 Cookie 缺 SameSite"; fails=$((fails+1)); fi

# 6. 退出后立即失效
curl -s -o /dev/null -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php?action=logout"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "http://127.0.0.1:$PORT/open.php")
chk "退出后不能再访问" "$code" "302"

# 7. 伪造的 session cookie 不能通过
name=$(awk -F'\t' 'NF>=7{print $6}' "$J" | tail -1)
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: ${name}=deadbeefdeadbeefdeadbeef" "http://127.0.0.1:$PORT/open.php")
chk "伪造 session 无效" "$code" "302"

# 8. 登录页本身不能把密码回显出去
lp=$(curl -s "http://127.0.0.1:$PORT/login.php")
if echo "$lp" | grep -q 'value="pw"'; then echo "  ✗ 登录页回显了密码"; fails=$((fails+1))
else echo "  ✓ 登录页未回显密码"; fi

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
