#!/bin/bash
# 肉类采购：真实 HTTP 流程 —— 三种录入时机、校验、改、软删、恢复、估算
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/. "$T"/prog 2>/dev/null
mkdir -p "$T/data"
# 数据文件放在程序目录之外（模拟真实部署）
php -r '$f=$argv[1];$s=file_get_contents($f);$s=preg_replace("/^return \[/m","return [\n    \x27store_path\x27 => \x27".$argv[2]."\x27,",$s,1);file_put_contents($f,$s);' \
    "$T/prog/config.php" "$T/data/app.db"
# 设登录密码（真实 config 里是占位串，不设的话所有请求都会被弹回登录页）
python3 - "$T/prog/config.php" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
s = s.replace("'在这里设置登录密码'", "'pw'")
assert "'pw'" in s, '密码没替换成功'
open(p, 'w', encoding='utf-8').write(s)
PYEOF

PORT=8250
php -S 127.0.0.1:$PORT -t "$T/prog" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
# 全部用相对今天的日期。第一版写死了 $D5，跑到今天之后就被
# 「日期在未来」的校验拦下，测试自己失败 —— 日期要跟着今天走。
D1=$(date -d '-5 day' +%F); D2=$(date -d '-4 day' +%F); D3=$(date -d '-3 day' +%F)
D4=$(date -d '-2 day' +%F); D5=$(date -d '-1 day' +%F); D0=$(date +%F)
chk(){ if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else
  echo "  ✗ $1  —— 找不到: $3"
  echo "$2" | grep -oE 'class="(okmsg|err)">[^<]*' | head -2 | sed 's/^/      提示: /'
  fails=$((fails+1)); fi }
nochk(){ if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }
tok(){ curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/meat.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
post(){
  local out; out=$(curl -s -b "$J" -c "$J" -L -d "csrf=$(tok)&$1" "http://127.0.0.1:$PORT/meat.php")
  # 页面中途 fatal 会返回 200 但内容被截断，必须确认渲染到底 ——
  # meat.php 第一版就是漏 require 导致渲染到第一行就断了，只查关键词没抓到
  if ! echo "$out" | grep -q '</body>'; then
    echo "  ✗✗ 页面渲染中断（多半是 PHP fatal）: $1" >&2; fails=$((fails+1))
  fi
  echo "$out"
}

# 登录
lt=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$lt&password=pw" "http://127.0.0.1:$PORT/login.php"

echo "肉类采购录入："
p=$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php")
chk "页面打得开" "$p" '记一笔采购'
chk "导航有入口" "$p" 'meat.php'
chk "四个品类都在" "$p" '三文鱼'
chk "品类含西语名" "$p" 'Lubina'

echo "— 场景1：到货当天，只有条数 —"
r=$(post "act=save&purchase_date=$D1&kind=salmon&unit_count=3&unit_type=piece&supplier=Pescados%20SL")
chk "只填条数能存下" "$r" '已记录'
chk "标为待补发票" "$r" '待补发票'
chk "重量列显示为空" "$r" '<span class="dim">—</span>'

echo "— 场景2：一次填完 —"
r=$(post "act=save&purchase_date=$D2&kind=beef&weight_kg=25,5&unit_count=2&unit_type=pack&unit_price=8.4&price_basis=kg&total_price=214.2&supplier=Carnes%20Ruiz")
chk "一次填完能存下" "$r" '已记录'
chk "西语逗号小数被识别" "$r" '25.5'
chk "完整记录标为完整" "$r" '>完整<'

echo "— 场景3：校验 —"
r=$(post "act=save&purchase_date=$D0&kind=salmon")
chk "重量件数都空被拒" "$r" '重量和件数至少要填一个'
r=$(post "act=save&purchase_date=&kind=salmon&weight_kg=5")
chk "缺日期被拒" "$r" '请填日期'
r=$(post "act=save&purchase_date=$D0&kind=&weight_kg=5")
chk "缺品类被拒" "$r" '请选品类'
r=$(post "act=save&purchase_date=$D0&kind=NOTEXIST&weight_kg=5")
chk "品类不在清单里被拒" "$r" '不在清单里'
r=$(post "act=save&purchase_date=2030-01-01&kind=salmon&weight_kg=5")
chk "未来日期被拒" "$r" '日期在未来'
r=$(post "act=save&purchase_date=$D0&kind=salmon&unit_count=3")
chk "填了件数没选单位被拒" "$r" '请选「条」还是「包」'
r=$(post "act=save&purchase_date=$D0&kind=salmon&unit_count=3&unit_type=piece&unit_price=10&price_basis=kg")
chk "按公斤计价却没填重量被拒" "$r" '按公斤计价就得填重量'
r=$(post "act=save&purchase_date=$D0&kind=salmon&weight_kg=-5")
chk "负重量被拒" "$r" '要大于 0'
r=$(curl -s -b "$J" -L -d "act=save&purchase_date=$D0&kind=salmon&weight_kg=5" "http://127.0.0.1:$PORT/meat.php")
chk "缺 CSRF 被拒" "$r" '表单已过期'

echo "— 场景4：发票到了，补齐第 1 条 —"
id=$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php" | grep -o 'edit=[0-9]*' | tail -1 | cut -d= -f2)
r=$(post "act=save&id=$id&purchase_date=$D1&kind=salmon&weight_kg=12.6&unit_count=3&unit_type=piece&total_price=151.2&supplier=Pescados%20SL")
chk "补齐后保存成功" "$r" '已保存'
nochk "补齐后不再是待补发票" "$(echo "$r" | tr -d '\n' | grep -o '<tr[^>]*>.\{0,400\}09-01.\{0,900\}</tr>')" '待补发票'

echo "— 场景5：平均条重估算 —"
for spec in "$D3:12,0" "$D4:13,5" "$D5:11,4"; do
  post "act=save&purchase_date=${spec%%:*}&kind=salmon&weight_kg=${spec#*:}&unit_count=3&unit_type=piece" >/dev/null
done
p=$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php?from=$D1&to=$D0")
chk "算出了平均条重" "$p" 'kg/条'
chk "标出样本数" "$p" '条</td>'
r=$(post "act=save&purchase_date=$D5&kind=salmon&unit_count=2&unit_type=piece")
chk "只填条数的新记录被估算" "$r" '≈'
chk "汇总里标出估算部分" "$r" '其中估算'

echo "— 场景6：作废与恢复 —"
id2=$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php" | grep -o 'edit=[0-9]*' | head -1 | cut -d= -f2)
r=$(post "act=delete&id=$id2")
chk "作废成功" "$r" '已作废'
nochk "作废后默认不显示" "$r" "edit=$id2\""
r=$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php?deleted=1")
chk "勾选后能看到已作废" "$r" '已作废'
r=$(post "act=restore&id=$id2")
chk "能恢复" "$r" '已恢复'

echo "— 场景7：数据真的落到了独立文件 —"
[ -f "$T/data/app.db" ] && echo "  ✓ SQLite 文件生成在程序目录之外" \
  || { echo "  ✗ 没找到数据文件"; fails=$((fails+1)); }
n=$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM meat_purchase")->fetchColumn();' "$T/data/app.db")
[ "$n" -ge 6 ] && echo "  ✓ 库里有 $n 条采购记录" || { echo "  ✗ 记录数不对（$n）"; fails=$((fails+1)); }
lg=$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM meat_purchase_log")->fetchColumn();' "$T/data/app.db")
[ "$lg" -ge 8 ] && echo "  ✓ 留痕表有 $lg 条日志" || { echo "  ✗ 留痕不足（$lg）"; fails=$((fails+1)); }
# 数据库层的 CHECK 约束：绕过页面直接写也进不去脏数据
if php -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $p->exec("INSERT INTO meat_purchase (purchase_date,kind,created_at,updated_at) VALUES (\"2026-01-01\",\"x\",\"t\",\"t\")");' "$T/data/app.db" 2>/dev/null; then
  echo "  ✗ 数据库层 CHECK 没拦住两个都空的行"; fails=$((fails+1))
else echo "  ✓ 绕过页面直接写也被 CHECK 拦下"; fi

echo "— 场景8：主库那条只读线没被碰 —"
nochk "本页不引用主库查询" "$(cat "$APP/meat.php")" 'Db::select'
nochk "Store 不认识 MySQL" "$(cat "$APP/lib/store.php")" 'mysqli\|mysql:host'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
