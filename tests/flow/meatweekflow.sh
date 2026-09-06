#!/bin/bash
# 肉类周报表：真实 HTTP 流程
#   - POS 用桩（每天固定人数/营业额），采购走真实的自有 SQLite
#   - 覆盖：正常渲染、周切换、跨周归并、估算标记、缺重量标记、
#           本周未完不计入合计、主库挂掉时降级、子标签导航
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

# ---- POS 桩：每个营业日 40 人 / 1000 元；POSFAIL=1 时模拟主库挂掉 ----
cat > "$T/prog/lib/db.php" <<'PHP'
<?php
declare(strict_types=1);
final class Db {
    private static array $cfg=[]; private static array $over=[];
    public static function config(): array {
        if (!self::$cfg) { self::$over=(array)require __DIR__.'/../config.php';
            self::$cfg=self::$over+(array)require __DIR__.'/settings.php';
            self::$cfg['password']='pw'; }
        return self::$cfg;
    }
    public static function defaults(): array { return (array) require __DIR__.'/settings.php'; }
    public static function overrides(): array { self::config(); return self::$over; }
    public static function assertReadOnly(string $s): void {}
    public static function availableDrivers(): array { return ['mysqli']; }
    public static function driverName(): string { return 'mysqli'; }
    public static function select(string $sql, array $p=[]): array {
        if (getenv('POSFAIL') === '1') throw new RuntimeException('主库连不上（测试模拟）');
        $from = substr((string)($p[':from'] ?? ''),0,10);
        $to   = substr((string)($p[':to']   ?? ''),0,10);
        if (!$from) return [];
        // 只让历史表出数：order_head（当天在营业的单）也出一份的话人数会翻倍，
        // 那是桩的假象，不是程序的问题
        if (!str_contains($sql, 'FROM history_order_head')) return [];
        $out=[]; $d=strtotime($from);
        while ($d < strtotime($to)) {
            $out[] = ['biz_date'=>date('Y-m-d',$d),'seg'=>'night','checks'=>10,
                      'guests'=>40,'actual'=>1000.0,'original'=>1000.0,'discount'=>0.0,
                      'service'=>0.0,'tax'=>0.0,'should_amt'=>1000.0,'ret'=>0.0];
            $d = strtotime('+1 day', $d);
        }
        return $out;
    }
}
PHP

PORT=8253
php -S 127.0.0.1:$PORT -t "$T/prog" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; kill $SRV2 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
chk(){ if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else
  echo "  ✗ $1  —— 找不到: $3"; fails=$((fails+1)); fi }
nochk(){ if echo "$2" | grep -q "$3"; then echo "  ✗ $1  —— 不该出现: $3"; fails=$((fails+1)); else echo "  ✓ $1"; fi }
tok(){ curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/meat.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
post(){ curl -s -b "$J" -c "$J" -L -d "csrf=$(tok)&$1" "http://127.0.0.1:$PORT/meat.php" >/dev/null; }
# 页面中途 fatal 仍是 200、内容被截断 —— 必须确认渲染到底
get(){
  local out; out=$(curl -s -b "$J" "http://127.0.0.1:$PORT/$1")
  if ! echo "$out" | grep -q '</body>'; then
    echo "  ✗✗ 页面渲染中断（多半是 PHP fatal）: $1" >&2; fails=$((fails+1)); fi
  echo "$out"
}

lt=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$lt&password=pw" "http://127.0.0.1:$PORT/login.php"

# ---- 造数据：往前 5 个整周，每周一进货一次；日期一律相对今天算 ----
# 上上周（-2 周）故意进双倍，用来验「高出滚动均值会标黄」
mon(){ date -d "monday -$1 week" +%F; }   # $1 周前的那个周一
for w in 1 2 3 4 5; do
  d=$(mon $w)
  case $w in
    2) kg=30 ;;      # 异常大量
    *) kg=10 ;;
  esac
  post "act=save&purchase_date=$d&kind=salmon&weight_kg=$kg&unit_count=$((kg/5))&unit_type=piece&total_price=$((kg*15))&supplier=Pescados"
  post "act=save&purchase_date=$d&kind=beef&weight_kg=8&unit_count=2&unit_type=pack&total_price=96&supplier=Carnes"
done
# 只填条数的一笔（有 3 条以上样本，应被估算成 ≈）
post "act=save&purchase_date=$(mon 1)&kind=salmon&unit_count=2&unit_type=piece"
# 缺重量又没样本的品类（应出现「!」缺重量标记）
post "act=save&purchase_date=$(mon 1)&kind=atun&unit_count=1&unit_type=piece"
# 本周（未完）也进一笔
post "act=save&purchase_date=$(date -d 'monday' +%F)&kind=lubina&weight_kg=6&total_price=60"

echo "周报表："
p=$(get "meatweek.php")
chk "页面打得开" "$p" '逐周明细'
chk "写明采购≠消耗" "$p" '不是「实际消耗量」'
chk "有滚动平均列" "$p" '近 4 周均值'
chk "有人均克数列" "$p" '人均 g'
chk "有占营业额列" "$p" '占营业额'
chk "有各品类小计" "$p" '各品类小计'
chk "算出均价 €/kg" "$p" '均价 €/kg'
chk "本周标为未完" "$p" '本周未完'
chk "缺重量有 ! 标记" "$p" '缺重量，没算进来'
chk "估算有 ≈ 标记" "$p" '按平均条重估算的'
chk "标出异常周" "$p" 'row-warn'
chk "说明合并方式" "$p" '不做 JOIN'

echo "— 子标签导航 —"
chk "周报表页有子标签" "$p" 'class="subtabs"'
chk "周报表标为当前" "$p" 'meatweek.php" class="on"'
m=$(get "meat.php")
chk "录入页也有子标签" "$m" 'class="subtabs"'
chk "录入页能跳到周报表" "$m" 'href="meatweek.php"'

echo "— 周数切换 —"
p4=$(get "meatweek.php?weeks=4")
chk "4 周选项生效" "$p4" 'value="4" selected'
n4=$(echo "$p4" | grep -c 'class="date"')
p12=$(get "meatweek.php?weeks=12")
chk "12 周选项生效" "$p12" 'value="12" selected'
n12=$(echo "$p12" | grep -c 'class="date"')
[ "$n12" -gt "$n4" ] && echo "  ✓ 12 周比 4 周行数多（$n12 > $n4）" \
  || { echo "  ✗ 行数没变（$n4 / $n12）"; fails=$((fails+1)); }
pbad=$(get "meatweek.php?weeks=999")
chk "非法周数回落到默认" "$pbad" 'value="8" selected'

echo "— 人均口径 —"
# 每周 40 人 × 7 天 = 280 人；普通周 10+8=18kg → 18000/280 ≈ 64 g/人
chk "周客人数 = 40人 × 7天" "$p" '>280</td>'
chk "人均算出合理数值（18kg/280人 ≈ 64g）" "$p" '>6[0-9]</td>'

echo "— 数字列表头都带 class=n —"
bad=$(echo "$p" | grep -oE '<th>(合计 kg|采购额|人均 g|近 4 周均值|占营业额|客人数)' | head -3)
[ -z "$bad" ] && echo "  ✓ 数字列表头都是 class=\"n\"" \
  || { echo "  ✗ 有数字列表头缺 class=n: $bad"; fails=$((fails+1)); }

echo "— 主库挂掉时的降级 —"
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null
POSFAIL=1 php -S 127.0.0.1:$PORT -t "$T/prog" >/dev/null 2>&1 & SRV2=$!
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
lt=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$lt&password=pw" "http://127.0.0.1:$PORT/login.php"
pf=$(get "meatweek.php")
chk "主库挂了页面照样渲染完" "$pf" '逐周明细'
chk "说清哪两列算不出来" "$pf" '人均用量和成本占比这两列算不出来'
chk "采购数据照常显示" "$pf" '各品类小计'
nochk "主库挂了就没有客人数" "$pf" '>280</td>'
chk "人均列退回占位符" "$pf" 'class="n strong"><span class="dim">—</span>'
chk "合计卡片人均也是—" "$pf" '<div class="big">—'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
