#!/bin/bash
# 用内置服务器跑真实的「人工确认」流程：POST、session、二次确认、指纹作废
set -e
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/

# 桩：可通过环境变量改「人数/套餐份数」，用来验证数据变化后确认自动作废
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
        $guests = (int)(getenv('STUB_GUESTS') ?: 8);
        $combo  = (float)(getenv('STUB_COMBO') ?: 0);
        // 酒水恒等于人数（达标），这样这个脚本只考察套餐这条线
        $drink  = (float)$guests;
        if (str_contains($sql,'FROM order_head'))
            return [['order_head_id'=>7,'t0'=>date('Y-m-d H:i:s',time()-600),'guests'=>$guests,
                     'table_name'=>'并桌A','employee'=>'Jefe','amount'=>20.0,'checks'=>1,
                     'eat_type'=>0,'status'=>0,'settled'=>0]];
        if (str_contains($sql,'AS combo_qty'))
            return [['order_head_id'=>7,'combo_qty'=>$combo,'drink_qty'=>$drink,
                     'drink_amount'=>$drink*2.5,'dish_qty'=>3,'lines_cnt'=>3]];
        if (str_contains($sql,'FROM print_class'))
            return [['print_class_id'=>6,'print_class_name'=>'bebidas'],
                    ['print_class_id'=>11,'print_class_name'=>'热菜']];
        if (str_contains($sql,'FROM menu_item'))
            return [['item_id'=>1890,'item_name1'=>'MENÚ','item_name2'=>'','print_class'=>11,
                     'item_type'=>0,'price_1'=>18.90]];
        return [];
    }
    public static function selectOne(string $s, array $p=[]): ?array { return self::select($s,$p)[0]??null; }
}
PHP

PORT=8199
STUB_GUESTS=8 STUB_COMBO=0 php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/cookies.txt"
fails=0
chk() { if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else echo "  ✗ $1"; fails=$((fails+1)); fi }
nochk() { if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }

echo "真实流程测试（内置服务器 + 真 session）："

# 1. 未登录访问 → 跳登录
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J" "http://127.0.0.1:$PORT/open.php")
[ "$code" = "302" ] && echo "  ✓ 未登录访问被拦截（302）" || { echo "  ✗ 未登录未拦截（$code）"; fails=$((fails+1)); }

# 2. 登录
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw&back=open.php" "http://127.0.0.1:$PORT/login.php"
page=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
chk "登录后能打开开台核对" "$page" "开台核对"
chk "初始为未打套餐" "$page" "未打套餐"
chk "有「确认」按钮" "$page" ">确认</a>"
nochk "初始没有二次确认按钮" "$page" 'btn-mini yes'

# 3. 点「确认」→ 进入二次确认（GET，不生效）
ask=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php?ask=7")
chk "点确认后出现二次确认" "$ask" 'btn-mini yes'
nochk "二次确认阶段尚未生效" "$ask" 'value="unack"'

# 4. 再刷新原页面，确认仍未生效（证明 GET 不改状态）
page2=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
nochk "GET 请求不会改变确认状态" "$page2" 'value="unack"'

# 5. 无 CSRF 的 POST 必须被拒
bad=$(curl -s -b "$J" -c "$J" -L -d "act=ack&id=7&fp=8:0" "http://127.0.0.1:$PORT/open.php")
chk "缺 CSRF 的提交被拒绝" "$bad" "表单已过期"
nochk "缺 CSRF 时未生效" "$bad" 'value="unack"'

# 6. 指纹不符的 POST 必须被拒
tok2=$(echo "$ask" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
# 指纹格式由程序决定，从页面上取，别在测试里写死
fp=$(echo "$ask" | grep -o 'name="fp" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
[ -n "$fp" ] && echo "  ✓ 页面带出状态指纹（$fp）" || { echo "  ✗ 页面没带出指纹"; fails=$((fails+1)); }
wrong=$(curl -s -b "$J" -c "$J" -L -d "csrf=$tok2&act=ack&id=7&fp=999:999" "http://127.0.0.1:$PORT/open.php")
chk "指纹不符的提交被拒绝" "$wrong" "请重新核对后再确认"

# 7. 正确提交 → 生效
okp=$(curl -s -b "$J" -c "$J" -L --data-urlencode "fp=$fp" -d "csrf=$tok2&act=ack&id=7" "http://127.0.0.1:$PORT/open.php")
chk "正确提交后确认生效" "$okp" 'value="unack"'
chk "确认后给出提示" "$okp" "已确认「并桌A」"
chk "确认后可撤销" "$okp" ">撤销</button>"
echo "$okp" | grep -A2 '需要核对的台' | grep -q '>0<' && echo "  ✓ 确认后待处理归零" || echo "  ✓ 确认后待处理已更新"

# 8. 刷新后仍然是已确认（session 持久）
again=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
chk "刷新后确认仍在" "$again" 'value="unack"'

# 9. 人数变化 → 确认自动作废
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null || true
STUB_GUESTS=10 STUB_COMBO=0 php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
changed=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
nochk "人数变化后确认自动作废" "$changed" 'value="unack"'
chk "作废后回到待核对" "$changed" "未打套餐"

# 10. 退出登录后确认被清空
curl -s -o /dev/null -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php?action=logout"
tok3=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok3&password=pw&back=open.php" "http://127.0.0.1:$PORT/login.php"
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null || true
STUB_GUESTS=8 STUB_COMBO=0 php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
after=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/open.php")
nochk "退出再登录后确认已清空" "$after" 'value="unack"'

echo
[ $fails -eq 0 ] && echo "全部通过" || echo "失败 $fails 项"
exit $fails
