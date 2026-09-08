#!/bin/bash
# 发票导入：真实 HTTP 流程 —— 上传、核对、入库、重复导入、跳过行、拒收
#
# 这个流程最要紧的一条是【上传之后不能直接入库】：认错列是静默的，
# 中间那步「核对」就是唯一能发现它的地方。所以这里专门断言
# 「传完之后库里还是 0 条」，再点确认才有数据。
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

PORT=8254
php -S 127.0.0.1:$PORT -t "$T/prog" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
U="http://127.0.0.1:$PORT/meatimport.php"
# 日期跟着今天走：写死的话，跑到那天之后会被「日期在未来」拦下，
# 测试自己失败而不是程序有问题
D1=$(date -d '-5 day' +%F); D2=$(date -d '-4 day' +%F); D3=$(date -d '-3 day' +%F)

chk(){ if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else
  echo "  ✗ $1  —— 找不到: $3"
  echo "$2" | grep -oE 'class="(okmsg|err)">[^<]*' | head -2 | sed 's/^/      提示: /'
  fails=$((fails+1)); fi }
nochk(){ if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }
tok(){ curl -s -b "$J" -c "$J" "$U" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
rows(){ php -r '$p=new PDO("sqlite:".$argv[1]);
  echo (int) $p->query("SELECT COUNT(*) FROM meat_purchase WHERE deleted_at IS NULL")->fetchColumn();' "$T/data/app.db"; }
# 上传一个文件，返回预览页
up(){
  local out; out=$(curl -s -b "$J" -c "$J" -L -F "csrf=$(tok)" -F "act=upload" -F "file=@$1" "$U")
  # 页面中途 fatal 会返回 200 但内容被截断，必须确认渲染到底
  if ! echo "$out" | grep -q '</body>'; then
    echo "  ✗✗ 页面渲染中断（多半是 PHP fatal）: $1" >&2; fails=$((fails+1))
  fi
  # Warning / Deprecated 不会截断页面，只是混进 HTML 里 —— 光看「渲染完了没」
  # 是发现不了的。fgetcsv 在 PHP 8.4 上的废弃警告就是这么漏过去的。
  if echo "$out" | grep -qiE '(Fatal error|Parse error|Warning:|Deprecated:|Notice:|Uncaught)'; then
    echo "  ✗✗ 响应里混进了 PHP 报错: $1" >&2
    echo "$out" | grep -iE '(Fatal|Parse|Warning|Deprecated|Notice|Uncaught)' | head -2 | sed 's/^/      /'
    fails=$((fails+1))
  fi
  echo "$out"
}
# 照着预览页上的按钮点「导入」—— sheet 和 stamp 都从页面上取，
# 不自己拼：拼出来的话，就测不到「预览和入库必须是同一批」这条了
imp(){
  local pv="$1"
  local sheet stamp
  sheet=$(echo "$pv" | grep -o 'name="sheet" value="[0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  stamp=$(echo "$pv" | grep -o 'name="stamp" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$J" -c "$J" -L -d "csrf=$(tok)&act=import&sheet=$sheet&stamp=$stamp" "$U"
}

# 登录
lt=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$lt&password=pw" "http://127.0.0.1:$PORT/login.php"

# ---- 样本文件 ----
cat > "$T/good.csv" <<EOF
类别;送货日期;净重;未税金额;税率;发票号;中文名称;类型
Salmón;$D1;10;100;10;FA-1;三文鱼整条;Compra
Atún;$D2;5;60;10;FA-2;金枪鱼;Compra
Ternera;$D3;20;180;10;FA-3;牛肉;Compra
EOF
cat > "$T/ret.csv" <<EOF
类别;送货日期;净重;含税金额;类型
Salmón;$D1;8;92;Compra
Salmón;$D2;-4;-46;Return
Pulpo;$D3;6;70;Compra
EOF
cat > "$T/odd.csv" <<EOF
类别;送货日期;净重;含税金额
Salmón;$D1;100;5
EOF
cat > "$T/junk.csv" <<EOF
随便;写点;什么
1;2;3
EOF
cp "$T/good.csv" "$T/bad.txt"

echo "发票导入："
p=$(curl -s -b "$J" "$U")
chk "页面打得开" "$p" '上传发票明细'
chk "子标签在采购下面" "$p" 'meatweek.php'
chk "采购页有入口" "$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php")" 'meatimport.php'

echo "— 场景1：上传后先核对，不直接入库 —"
before=$(rows)
pv=$(up "$T/good.csv")
chk "认出了表头" "$pv" '认出来的列'
chk "列出了日期认到哪一列" "$pv" '日期：<strong>B 列'
chk "列出了重量认到哪一列" "$pv" '重量：<strong>C 列'
chk "写明金额口径是折算含税" "$pv" '按表里的税率折算成含税'
chk "给出可导入条数" "$pv" '可导入'
chk "给出均价（认错列的报警器）" "$pv" '€/kg'
chk "有确认按钮" "$pv" '确认无误，导入'
[ "$(rows)" = "$before" ] && echo "  ✓ 核对阶段库里一条都没进" \
  || { echo "  ✗ 还没确认就写进库了"; fails=$((fails+1)); }

echo "— 场景2：确认后入库 —"
r=$(imp "$pv")
chk "报出导入结果" "$r" '已导入 3 条'
# 100+60+180 = 340 未税，税率 10% → 374 含税；35 kg
chk "合计按含税算" "$r" '374.00'
[ "$(rows)" = "3" ] && echo "  ✓ 库里 3 条" || { echo "  ✗ 库里是 $(rows) 条"; fails=$((fails+1)); }
chk "采购明细页看得到" "$(curl -s -b "$J" "http://127.0.0.1:$PORT/meat.php?from=$D1&to=$(date +%F)")" '三文鱼'

echo "— 场景3：同一份文件再导一次，不会翻倍 —"
pv2=$(up "$T/good.csv")
chk "预览里标出已经导过" "$pv2" '已导过'
r=$(imp "$pv2")
chk "一条都没重复进来" "$r" '已导入 0 条'
chk "如实说有多少条是跳过的" "$r" '3 条早就导过了'
[ "$(rows)" = "3" ] && echo "  ✓ 库里还是 3 条" || { echo "  ✗ 数据翻倍了（$(rows) 条）"; fails=$((fails+1)); }

echo "— 场景4：退货行和认不出的品类，跳过并列出来 —"
pv=$(up "$T/ret.csv")
chk "退货行标为跳过" "$pv" '退货／负数行'
chk "认不出的品类也列出来" "$pv" '认不出品类'
r=$(imp "$pv")
chk "只导进正常的那条" "$r" '已导入 1 条'
chk "提醒还有没导的要人工处理" "$r" '请人工处理'
[ "$(rows)" = "4" ] && echo "  ✓ 库里 4 条（退货没被抵扣）" || { echo "  ✗ 库里是 $(rows) 条"; fails=$((fails+1)); }

echo "— 场景5：均价离谱就报警（认错列唯一的自动信号）—"
pv=$(up "$T/odd.csv")
chk "报了警" "$pv" '请核对'
chk "说清了怀疑认错列" "$pv" '认错了列'
chk "报警里带上认到的列，方便对着看" "$pv" 'weight='

echo "— 场景6：拒收 —"
r=$(curl -s -b "$J" -c "$J" -L -F "csrf=$(tok)" -F "act=upload" -F "file=@$T/bad.txt" "$U")
chk "非 xlsx/csv 被拒" "$r" '只认 .xlsx 和 .csv'
r=$(up "$T/junk.csv")
chk "认不出表头就直说" "$r" '认不出表头'
r=$(curl -s -b "$J" -c "$J" -L -F "act=upload" -F "file=@$T/good.csv" "$U")
chk "缺 CSRF 被拒" "$r" '表单已过期'
r=$(curl -s -b "$J" -c "$J" -L -d "csrf=$(tok)&act=import&sheet=0&stamp=乱写的" "$U")
chk "对不上预览的批次不许导" "$r" '重新核对'
[ "$(rows)" = "4" ] && echo "  ✓ 这些都没往库里写东西" || { echo "  ✗ 库里是 $(rows) 条"; fails=$((fails+1)); }

echo "— 场景7：没登录进不来 —"
r=$(curl -s -L "$U")
chk "未登录被弹回登录页" "$r" '登录'
nochk "未登录看不到上传表单" "$r" '上传发票明细'

echo "— 场景8：主库那条只读线没被碰 —"
nochk "本页不引用主库查询" "$(cat "$APP/meatimport.php")" 'Db::select'
nochk "上传的文件不落盘" "$(cat "$APP/meatimport.php")" 'move_uploaded_file'
nochk "导入逻辑不认识 MySQL" "$(cat "$APP/lib/meatimport.php")" 'mysqli\|mysql:host'
# 留痕：导进来的每一条都要能查到是怎么进来的
lg=$(php -r '$p=new PDO("sqlite:".$argv[1]);
  echo (int) $p->query("SELECT COUNT(*) FROM meat_purchase_log WHERE action=\"import\"")->fetchColumn();' "$T/data/app.db")
[ "$lg" = "4" ] && echo "  ✓ 4 条导入都留了痕" || { echo "  ✗ 留痕 $lg 条"; fails=$((fails+1)); }
# 唯一索引真的在库上，不是只在 PHP 里判
if php -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $k=$p->query("SELECT import_key FROM meat_purchase WHERE import_key IS NOT NULL")->fetchColumn();
  $s=$p->prepare("INSERT INTO meat_purchase (purchase_date,kind,weight_kg,import_key,created_at,updated_at)
                  VALUES (\"2026-01-01\",\"salmon\",1,?,\"t\",\"t\")"); $s->execute([$k]);' "$T/data/app.db" 2>/dev/null; then
  echo "  ✗ 数据库层没拦住重复的导入指纹"; fails=$((fails+1))
else echo "  ✓ 绕过页面直接写重复指纹也被唯一索引拦下"; fi

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
