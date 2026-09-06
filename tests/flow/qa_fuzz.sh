#!/bin/bash
# 体检 3：所有页面的所有参数，塞入畸形值后不得 500、不得泄漏报错、不得跑飞
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# 复用 drinkflow.sh 里的数据库桩，按脚本自己的位置找，别依赖当前工作目录
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/
sed -n '/^cat > "\$T\/lib\/db.php"/,/^PHP$/p' "$HERE/drinkflow.sh" | sed '1d;$d' > "$T/lib/db.php"

PORT=8213
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
J="$T/c.txt"; fails=0; n=0
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw" "http://127.0.0.1:$PORT/login.php"

BAD=( "" "0" "-1" "abc" "9999-99-99" "2026-13-45" "1970-01-01" "2099-12-31"
      "0000-00-00" "%27%20OR%201%3D1" "%3Cscript%3E" "../../etc/passwd"
      "99999999999999999999" "-99999999999" "1e308" "NaN" "null" "%00"
      "2026-01-01%00" "'\''" "%22" "a%0Ab" "%EF%BC%847%2A7" "%7B%7B7%2A7%7D%7D" )

probe() {   # $1=url  $2=说明
  n=$((n+1))
  code=$(curl -s -o "$T/out.html" -w '%{http_code}' -b "$J" -c "$J" --max-time 20 "http://127.0.0.1:$PORT/$1")
  body=$(cat "$T/out.html")
  if [ "$code" != "200" ] && [ "$code" != "302" ]; then
    echo "  ✗ HTTP $code : $1"; fails=$((fails+1)); return; fi
  if echo "$body" | grep -qi 'Fatal error\|Parse error\|Uncaught\|Warning:\|Notice:\|Deprecated:'; then
    echo "  ✗ 泄漏 PHP 错误: $1"; echo "$body" | grep -io 'fatal error[^<]*\|warning:[^<]*' | head -1; fails=$((fails+1)); return; fi
  if ! echo "$body" | grep -q '</body>'; then
    echo "  ✗ 渲染中断: $1"; fails=$((fails+1)); return; fi
}

echo "体检 3：参数畸形值"
D=$(date +%F)
for v in "${BAD[@]}"; do
  probe "index.php?start=$v&end=$D"
  probe "index.php?start=$D&end=$v"
  probe "index.php?start=$v&end=$v&eat=$v&with_live=$v&exclude_zero=$v"
  probe "dish.php?start=$D&end=$D&item=$v"
  probe "dish.php?start=$D&end=$D&pc=$v&seg=$v"
  probe "station.php?start=$D&end=$D&sort=$v&seg=$v"
  probe "open.php?scope=$v&issues=$v&ask=$v"
done
# 参数缺失 / 重复 / 数组形式
probe "index.php"
probe "dish.php"
probe "station.php"
probe "open.php"
probe "index.php?start=$D&start=$D&end=$D"
probe "index.php?start[]=1&end[]=2"
probe "dish.php?item[]=1"
probe "open.php?ask[]=1"
probe "index.php?start=$D&end=2026-12-31"      # 超过 92 天上限
probe "index.php?start=2026-12-31&end=$D"      # 结束早于开始
echo "  —— 共 $n 次请求"

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
