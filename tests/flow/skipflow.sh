#!/bin/bash
# 验证外带（Llevar）免核对：状态、计数、排序、不给确认按钮、服务端拒绝确认
set -e
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/

cat > "$T/lib/db.php" <<'PHP'
<?php
declare(strict_types=1);
final class Db {
    private static array $over = [];
    private static array $cfg = [];
    public static function config(): array {
        if (!self::$cfg) { self::$over = (array) require __DIR__.'/../config.php';
            self::$cfg = self::$over + (array) require __DIR__.'/settings.php'; self::$cfg['password']='pw'; }
        return self::$cfg;
    }
    public static function defaults(): array { return (array) require __DIR__ . '/settings.php'; }
    public static function overrides(): array { self::config(); return self::$over; }
    public static function assertReadOnly(string $s): void {}
    public static function availableDrivers(): array { return ['mysqli']; }
    public static function select(string $sql, array $p = []): array {
        $mk = fn($id,$tbl,$g,$et) => ['order_head_id'=>$id,'t0'=>date('Y-m-d H:i:s',time()-600),
            'guests'=>$g,'table_name'=>$tbl,'employee'=>'Jefe','amount'=>20.0,'checks'=>1,
            'eat_type'=>$et,'status'=>0,'settled'=>0];
        if (str_contains($sql,'FROM order_head'))
            return [$mk(7,'并桌A',8,0), $mk(8,'Llevar 2',0,3), $mk(9,'12',2,0)];
        if (str_contains($sql,'AS combo_qty'))
            return [['order_head_id'=>7,'combo_qty'=>0,'drink_qty'=>8,'drink_amount'=>20.0,'dish_qty'=>3,'lines_cnt'=>3],
                    ['order_head_id'=>8,'combo_qty'=>0,'drink_qty'=>0,'drink_amount'=>0.0,'dish_qty'=>2,'lines_cnt'=>2],
                    ['order_head_id'=>9,'combo_qty'=>2,'drink_qty'=>2,'drink_amount'=>5.0,'dish_qty'=>5,'lines_cnt'=>4]];
        if (str_contains($sql,'FROM print_class'))
            return [['print_class_id'=>6,'print_class_name'=>'bebidas'],
                    ['print_class_id'=>11,'print_class_name'=>'热菜']];
        if (str_contains($sql,'FROM menu_item'))
            return [['item_id'=>1890,'item_name1'=>'MENU','item_name2'=>'','print_class'=>11,
                     'item_type'=>0,'price_1'=>18.90]];
        return [];
    }
    public static function selectOne(string $s, array $p=[]): ?array { return self::select($s,$p)[0]??null; }
}
PHP

PORT=8201
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/cookies.txt"
fails=0
chk()   { if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else echo "  ✗ $1"; fails=$((fails+1)); fi }
nochk() { if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }

echo "外带免核对流程："
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw&back=open.php" "http://127.0.0.1:$PORT/login.php"
page=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
rows=$(echo "$page" | sed -n '/<ul class="openlist">/,/<\/ul>/p')

chk "Llevar 显示为免核对" "$rows" 's-skip">免核对'
nochk "Llevar 没被判成未打套餐" "$(echo "$rows" | grep -A3 'Llevar')" '未打套餐'
chk "并桌A 仍报未打套餐" "$rows" '未打套餐'
chk "需要核对的台只剩 1 台" "$page" '<div class="big">1</div>'
chk "卡片上显示免核对台数" "$page" '外带·免核对'
chk "页面列出免核对规则" "$page" '当前的判定规则'
chk "规则里带出生效的通配符" "$page" '<code>Llevar\*</code>'

# 免核对的台不给「确认」按钮：数一下确认链接的数量（只应有并桌A 一个）
n=$(echo "$rows" | grep -c 'btn-mini" href' || true)
[ "$n" = "1" ] && echo "  ✓ 只有问题台给确认按钮（$n 个）" || { echo "  ✗ 确认按钮数量不对（$n）"; fails=$((fails+1)); }

# 排序：免核对的排在最后
order=$(echo "$rows" | tr -d '\n' | grep -o 'class="l1">[[:space:]]*<b>[^<]*' \
  | sed 's/.*<b>//' | tr '\n' ',')
[ "$order" = "并桌A,12,Llevar 2," ] && echo "  ✓ 排序：问题台 → 一致 → 免核对（$order）" \
  || { echo "  ✗ 排序不对（$order）"; fails=$((fails+1)); }

# 「只看有问题的台」不应包含 Llevar
issues=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php?issues=1")
nochk "只看问题时不出现 Llevar" "$(echo "$issues" | sed -n '/<ul class="openlist">/,/<\/ul>/p')" 'Llevar'

# 服务端拒绝对免核对的台盖章（伪造 POST）
# 先展开并桌A 的二次确认表单，从里面取一个有效的 CSRF token
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php?ask=7" \
  | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
[ -n "$tok" ] && echo "  ✓ 取到 CSRF token" || { echo "  ✗ 没取到 CSRF token"; fails=$((fails+1)); }
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&act=ack&id=8&fp=0:0" "http://127.0.0.1:$PORT/open.php"
after=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
chk "伪造确认被服务端拒绝" "$after" '本来就免核对'
nochk "免核对的台没被标成已确认" "$(echo "$after" | sed -n '/<ul class="openlist">/,/<\/ul>/p')" 's-ack'


# ---- 回归 1：随包 config.php 不写功能参数时，settings.php 的默认值要生效 ----
chk "页面注明用的是内置默认值" "$page" '内置默认值'

# ---- 回归 2：config.php 里显式覆盖时，必须以 config.php 为准 ----
echo
echo "config.php 显式写成 no_combo_tables => []（所有台都核对）："
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null || true
php -r '$s=file_get_contents($argv[1]); $s=preg_replace("/^return \[/m", "return [\n    \x27no_combo_tables\x27 => [],", $s, 1); file_put_contents($argv[1],$s);' "$T/config.php"
php -l "$T/config.php" >/dev/null || { echo "  ✗ 改出来的 config.php 语法不对"; exit 1; }
PORT=8203
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
J2="$T/cookies2.txt"
tok=$(curl -s -b "$J2" -c "$J2" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J2" -c "$J2" -d "csrf=$tok&password=pw&back=open.php" "http://127.0.0.1:$PORT/login.php"
off=$(curl -s -b "$J2" -c "$J2" "http://127.0.0.1:$PORT/open.php")
offrows=$(echo "$off" | sed -n '/<ul class="openlist">/,/<\/ul>/p')
nochk "显式留空后不再有免核对的台" "$offrows" 's-skip'
chk "显式留空后 Llevar 回到未打套餐" "$offrows" '未打套餐'
chk "显式留空后需要核对的台变成 2 台" "$off" '<div class="big">2</div>'
nochk "显式配置时不再提示内置默认值" "$off" '内置默认值'
chk "页面说明桌号规则为空" "$off" '桌号规则为空'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
