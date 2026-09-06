#!/bin/bash
# 验证「每人至少一份酒水」这条核对在真实页面上的表现
set -e
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/

cat > "$T/lib/db.php" <<'PHP'
<?php
declare(strict_types=1);
final class Db {
    private static array $cfg  = [];
    private static array $over = [];
    public static function config(): array {
        if (!self::$cfg) { self::$over = (array) require __DIR__.'/../config.php';
            self::$cfg = self::$over + (array) require __DIR__.'/settings.php';
            self::$cfg['password']='pw'; }
        return self::$cfg;
    }
    public static function defaults(): array { return (array) require __DIR__ . '/settings.php'; }
    public static function overrides(): array { self::config(); return self::$over; }
    public static function assertReadOnly(string $s): void {}
    public static function availableDrivers(): array { return ['mysqli']; }
    public static function select(string $sql, array $p = []): array {
        $mk = fn($id,$tbl,$g) => ['order_head_id'=>$id,'t0'=>date('Y-m-d H:i:s',time()-600),
            'guests'=>$g,'table_name'=>$tbl,'employee'=>'A','amount'=>50.0,'checks'=>1,
            'eat_type'=>0,'status'=>0,'settled'=>0];
        $ct = fn($id,$combo,$drink,$amt) => ['order_head_id'=>$id,'combo_qty'=>$combo,
            'drink_qty'=>$drink,'drink_amount'=>$amt,'dish_qty'=>8,'lines_cnt'=>6];
        if (str_contains($sql,'FROM order_head'))
            return [$mk(1,'11',2), $mk(2,'12',2), $mk(3,'13',2), $mk(4,'14',2)];
        if (str_contains($sql,'AS combo_qty'))
            return [$ct(1,2,2,5.0),    // 够
                    $ct(2,2,5,12.5),   // 多了，也算够
                    $ct(3,2,1,2.5),    // 不足
                    $ct(4,2,0,0.0)];   // 一份没点
        if (str_contains($sql,'FROM print_class'))
            return [['print_class_id'=>6,'print_class_name'=>'bebidas'],
                    ['print_class_id'=>11,'print_class_name'=>'热菜']];
        if (str_contains($sql,'FROM menu_item'))
            return [['item_id'=>1890,'item_name1'=>'MENU','item_name2'=>'','print_class'=>11,
                     'item_type'=>0,'price_1'=>18.90],
                    ['item_id'=>431,'item_name1'=>'Agua','item_name2'=>'','print_class'=>6,
                     'item_type'=>0,'price_1'=>2.50],
                    ['item_id'=>432,'item_name1'=>'S/Hielo','item_name2'=>'','print_class'=>6,
                     'item_type'=>1,'price_1'=>0.0]];
        return [];
    }
    public static function selectOne(string $s, array $p=[]): ?array { return self::select($s,$p)[0]??null; }
}
PHP

PORT=8204
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
chk()   { if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else echo "  ✗ $1"; fails=$((fails+1)); fi }
nochk() { if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }
row()   { echo "$1" | tr -d '\n' | grep -o "<li id=\"t$2\".*\?</li>" | head -1; }

echo "酒水核对（每人至少一份）："
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw&back=open.php" "http://127.0.0.1:$PORT/login.php"
page=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
list=$(echo "$page" | sed -n '/<ul class="openlist">/,/<\/ul>/p')

nochk "2 人 2 杯不报警"      "$(row "$list" 1)" '酒水不足\|未点酒水'
nochk "2 人 5 杯（多了）也不报警" "$(row "$list" 2)" '酒水不足\|未点酒水'
chk   "2 人 1 杯 → 酒水不足"  "$(row "$list" 3)" 's-dshort">酒水不足'
chk   "不足时标出还差几份"     "$(row "$list" 3)" '缺 1'
chk   "2 人 0 杯 → 未点酒水"  "$(row "$list" 4)" 's-dnone">未点酒水'
chk   "套餐一致仍然照常显示"   "$(row "$list" 3)" '套餐一致'
chk   "逐台列出酒水杯数"       "$(row "$list" 2)" '酒水 <b>5'
chk   "需要核对的台 = 2"       "$page" '<div class="big">2</div>'
chk   "问题卡里拆出酒水一项"   "$page" '酒水不足</dt>'
chk   "酒水份数合计卡片"       "$page" '酒水份数合计'
chk   "酒水金额合计"           "$page" '20.00'
chk   "页面说明酒水口径"       "$page" '酒水口径'
chk   "列出命中的岗位"         "$page" 'bebidas'
chk   "说明多点不算问题"       "$page" '多了不算问题'

# 只看有问题的台：应当只剩 13、14
issues=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php?issues=1")
ilist=$(echo "$issues" | sed -n '/<ul class="openlist">/,/<\/ul>/p')
order=$(echo "$ilist" | tr -d '\n' | grep -o 'class="l1">[[:space:]]*<b>[^<]*' | sed 's/.*<b>//' | tr '\n' ',')
[ "$order" = "13,14," ] && echo "  ✓ 只看问题时只剩酒水不足的两台（$order）" \
  || { echo "  ✗ 只看问题时内容不对（$order）"; fails=$((fails+1)); }

# 酒水不足的台可以人工确认（这桌就是不喝酒）
ask=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php?ask=3")
chk "酒水不足的台给了确认按钮" "$ask" 'btn-mini yes'
tok2=$(echo "$ask" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
fp=$(echo "$ask" | grep -o 'name="fp" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
okp=$(curl -s -b "$J" -c "$J" -L --data-urlencode "fp=$fp" -d "csrf=$tok2&act=ack&id=3" "http://127.0.0.1:$PORT/open.php")
chk "确认后不再计入待处理" "$okp" '<div class="big">1</div>'
chk "确认后仍标着酒水不足" "$(row "$(echo "$okp" | sed -n '/<ul class="openlist">/,/<\/ul>/p')" 3)" '酒水不足'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
