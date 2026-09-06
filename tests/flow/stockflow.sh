#!/bin/bash
# 库存：真实 HTTP 流程 —— 存入/盘点、用量推算、漏记存入、盘点进度、改删
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/. "$T"/prog 2>/dev/null
mkdir -p "$T/data"
php -r '$f=$argv[1];$s=file_get_contents($f);$s=preg_replace("/^return \[/m","return [\n    \x27store_path\x27 => \x27".$argv[2]."\x27,",$s,1);file_put_contents($f,$s);' \
    "$T/prog/config.php" "$T/data/app.db"
python3 - "$T/prog/config.php" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
s = s.replace("'在这里设置登录密码'", "'pw'")
assert "'pw'" in s, '密码没替换成功'
open(p, 'w', encoding='utf-8').write(s)
PYEOF

PORT=8256
php -S 127.0.0.1:$PORT -t "$T/prog" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
# 日期一律相对今天算 —— 写死的日期跑到那天之后会被「未来」校验拦下
D2=$(date -d '-2 day' +%F); D1=$(date -d '-1 day' +%F); D0=$(date +%F)

chk(){ if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else
  echo "  ✗ $1  —— 找不到: $3"
  echo "$2" | grep -oE 'class="(okmsg|err)">[^<]*' | head -2 | sed 's/^/      提示: /'
  fails=$((fails+1)); fi }
nochk(){ if echo "$2" | grep -q "$3"; then echo "  ✗ $1  —— 不该出现: $3"; fails=$((fails+1)); else echo "  ✓ $1"; fi }
tok(){ curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/stock.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
# 页面中途 fatal 仍是 200、内容被截断 —— 必须确认渲染到底
post(){
  local out; out=$(curl -s -b "$J" -c "$J" -L -d "csrf=$(tok)&$1" "http://127.0.0.1:$PORT/stock.php")
  if ! echo "$out" | grep -q '</body>'; then
    echo "  ✗✗ 页面渲染中断（多半是 PHP fatal）: $1" >&2; fails=$((fails+1)); fi
  echo "$out"
}
get(){
  local out; out=$(curl -s -b "$J" "http://127.0.0.1:$PORT/$1")
  if ! echo "$out" | grep -q '</body>'; then
    echo "  ✗✗ 页面渲染中断（多半是 PHP fatal）: $1" >&2; fails=$((fails+1)); fi
  echo "$out"
}

lt=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$lt&password=pw" "http://127.0.0.1:$PORT/login.php"

echo "库存录入："
p=$(get "stock.php")
chk "页面打得开" "$p" '记一笔库存'
chk "导航里有库存入口" "$p" 'stock.php'
chk "动作是两个大按钮" "$p" 'class="pickopt'
chk "存入和盘点都在" "$p" '>盘点<'
chk "品类带单位显示" "$p" '三文鱼条（箱）'
chk "时点可选" "$p" '午市后'
chk "写明取出不用记" "$p" '上次盘点 + 期间存入 − 本次盘点'

echo "— 场景1：用户举的例子（4 箱 → 存入 3 → 盘点 5 → 用掉 2）—"
r=$(post "act=save&move_kind=count&happened_date=$D1&happened_time=23:30&moment=dinner_end&item=salmon_fillet&qty=4")
chk "昨晚盘点 4 箱" "$r" '已记录'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=salmon_fillet&qty=3")
chk "今天存入 3 箱" "$r" '存入 三文鱼条 3箱'
r=$(post "act=save&move_kind=count&happened_date=$D0&happened_time=23:30&moment=dinner_end&item=salmon_fillet&qty=5")
chk "今晚盘点 5 箱" "$r" '已记录'
n=$(get "stocknow.php")
chk "账面上限 = 5（盘点后没再存入）" "$n" '<td class="n strong">5</td>'
chk "算出取出量 2" "$n" '2 <span class="dim">箱</span>'
chk "分段用量表在" "$n" '取出（已使用）'
chk "写明账面不等于实时库存" "$n" '不等于「现在冰箱里有多少」'

echo "— 场景2：一天盘好几次，能算出餐期用量 —"
post "act=save&move_kind=count&happened_date=$D0&happened_time=11:00&moment=arrive&item=beef&qty=10" >/dev/null
post "act=save&move_kind=count&happened_date=$D0&happened_time=16:30&moment=lunch_end&item=beef&qty=7" >/dev/null
n=$(get "stocknow.php?item=beef")
chk "午市用量 3 包" "$n" '3 <span class="dim">包</span>'
chk "区间标出时点" "$n" '到店'
chk "区间标出时长" "$n" '5.5 小时'

echo "— 场景3：漏记存入会被抓出来 —"
# 盘点数比上次还多，中间又没存入 → 用量负数
post "act=save&move_kind=count&happened_date=$D0&happened_time=11:00&moment=arrive&item=salmon_skin&qty=2" >/dev/null
post "act=save&move_kind=count&happened_date=$D0&happened_time=23:30&moment=dinner_end&item=salmon_skin&qty=6" >/dev/null
n=$(get "stocknow.php")
chk "负用量被标出来" "$n" '漏记存入'
chk "顶上给出负数段的总数" "$n" '用量算成负数'
chk "解释了负数的含义" "$n" '东西不会凭空长出来'

echo "— 场景4：盘点进度（一条一条录最容易漏项）—"
p=$(get "stock.php?at=$D0+23%3A30")
chk "显示这轮盘了几项" "$p" '这一轮盘点'
chk "列出还差哪些" "$p" '还差'

echo "— 场景5：校验 —"
r=$(post "act=save&happened_date=$D0&happened_time=11:00&item=beef&qty=3")
chk "不选动作被拒" "$r" '请选「存入」还是「盘点」'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=beef")
chk "不填数量被拒" "$r" '请填数量'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=beef&qty=0")
chk "存入 0 被拒" "$r" '存入量要大于 0'
r=$(post "act=save&move_kind=count&happened_date=$D0&happened_time=11:00&item=beef&qty=0")
chk "盘点 0 可以（数完发现空了）" "$r" '已记录'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=&item=beef&qty=3")
chk "不填时间被拒" "$r" '请填时间'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=25:99&item=beef&qty=3")
chk "时间乱写被拒" "$r" '时间格式不对'
r=$(post "act=save&move_kind=in&happened_date=2030-01-01&happened_time=11:00&item=beef&qty=3")
chk "未来日期被拒" "$r" '日期在未来'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=NOTEXIST&qty=3")
chk "品类不在清单里被拒" "$r" '不在清单里'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=beef&qty=-3")
chk "负数被拒" "$r" '不能是负数'
r=$(post "act=save&move_kind=jump&happened_date=$D0&happened_time=11:00&item=beef&qty=3")
chk "乱造动作被拒" "$r" '只能是存入或盘点'
r=$(curl -s -b "$J" -L -d "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=beef&qty=3" "http://127.0.0.1:$PORT/stock.php")
chk "缺 CSRF 被拒" "$r" '表单已过期'
r=$(post "act=save&move_kind=in&happened_date=$D0&happened_time=11:00&item=salmon_fillet&qty=2,5")
chk "西语逗号小数被识别" "$r" '2.5箱'

echo "— 场景6：改与删 —"
id=$(get "stock.php" | grep -o 'edit=[0-9]*' | head -1 | cut -d= -f2)
r=$(post "act=save&id=$id&move_kind=in&happened_date=$D0&happened_time=11:00&item=salmon_fillet&qty=9")
chk "能改" "$r" '已保存'
r=$(post "act=delete&id=$id")
chk "能作废" "$r" '已作废'
nochk "作废后默认不显示" "$r" "edit=$id\""
r=$(get "stock.php?deleted=1")
chk "勾了才看得到已作废" "$r" '已作废'
r=$(post "act=restore&id=$id")
chk "能恢复" "$r" '已恢复'

echo "— 场景7：作废的记录不参与结存 —"
# 把三文鱼条最后那次盘点作废，账面应该退回上一次盘点
before=$(get "stocknow.php")
cid=$(php -r '$p=new PDO("sqlite:".$argv[1]);
  echo $p->query("SELECT id FROM stock_move WHERE item=\"salmon_fillet\" AND move_kind=\"count\" ORDER BY happened_at DESC LIMIT 1")->fetchColumn();' "$T/data/app.db")
post "act=delete&id=$cid" >/dev/null
after=$(get "stocknow.php")
[ "$before" != "$after" ] && echo "  ✓ 作废盘点后结存跟着变了" \
  || { echo "  ✗ 作废后结存没变，说明软删没生效"; fails=$((fails+1)); }
post "act=restore&id=$cid" >/dev/null

echo "— 场景8：数据与主库那条只读线 —"
n=$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM stock_move")->fetchColumn();' "$T/data/app.db")
[ "$n" -ge 8 ] && echo "  ✓ 库里有 $n 条库存流水" || { echo "  ✗ 记录数不对（$n）"; fails=$((fails+1)); }
lg=$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM stock_move_log")->fetchColumn();' "$T/data/app.db")
[ "$lg" -ge 10 ] && echo "  ✓ 留痕表有 $lg 条日志" || { echo "  ✗ 留痕不足（$lg）"; fails=$((fails+1)); }
# 数据库层的 CHECK：绕过页面直接写也进不去脏数据
if php -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $p->exec("INSERT INTO stock_move (happened_at,item,move_kind,qty,created_at,updated_at)
            VALUES (\"2026-01-01 10:00\",\"x\",\"in\",0,\"t\",\"t\")");' "$T/data/app.db" 2>/dev/null; then
  echo "  ✗ 数据库层 CHECK 没拦住「存入 0」"; fails=$((fails+1))
else echo "  ✓ 绕过页面直接写「存入 0」也被 CHECK 拦下"; fi
if php -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $p->exec("INSERT INTO stock_move (happened_at,item,move_kind,qty,created_at,updated_at)
            VALUES (\"2026-01-01 10:00\",\"x\",\"jump\",1,\"t\",\"t\")");' "$T/data/app.db" 2>/dev/null; then
  echo "  ✗ 数据库层 CHECK 没拦住乱造的动作"; fails=$((fails+1))
else echo "  ✓ 绕过页面直接写乱造动作也被 CHECK 拦下"; fi
nochk "库存页不引用主库查询" "$(cat "$APP/stock.php")" 'Db::select'
nochk "当前库存页不引用主库查询" "$(cat "$APP/stocknow.php")" 'Db::select'
nochk "当前库存页不写任何东西" "$(cat "$APP/stocknow.php")" 'INSERT\|UPDATE \|DELETE'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
