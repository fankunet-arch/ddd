#!/bin/bash
# 用桩数据渲染真实页面，检查有没有运行期错误（未定义变量、函数、HTML 结构等）
set -e
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{index.php,dish.php,station.php,open.php,compare.php,meat.php,meatweek.php,stock.php,stocknow.php,login.php,config.php,lib,assets} "$T"/
# 自有 SQLite 放进临时目录，别落到别处
mkdir -p "$T/store"
python3 -c "
import sys
p=sys.argv[1]
s=open(p,encoding='utf-8').read()
s=s.replace('return [', 'return [\n    \'store_path\' => \''+sys.argv[2]+'\',', 1)
open(p,'w',encoding='utf-8').write(s)
" "$T/config.php" "$T/store/app.db"

# 用返回固定数据的桩替换掉数据库访问层
cat > "$T/lib/db.php" <<'PHP'
<?php
declare(strict_types=1);
final class Db {
    private static array $over = [];
    private static array $cfg = [];
    public static array $calls = [];
    public static function config(): array {
        if (!self::$cfg) { self::$over = (array) require __DIR__ . '/../config.php';
            self::$cfg = self::$over + (array) require __DIR__ . '/settings.php'; }
        return self::$cfg;
    }
    public static function defaults(): array { return (array) require __DIR__ . '/settings.php'; }
    public static function overrides(): array { self::config(); return self::$over; }
    public static function assertReadOnly(string $sql): void {}
    public static function select(string $sql, array $params = []): array {
        self::$calls[] = $sql;
        // 开台列表和营业额统计都查 order_head，靠 table_name 区分 ——
        // 不加这个条件的话，营业额统计会拿到开台列表的行，字段对不上
        if (str_contains($sql, 'FROM order_head') && str_contains($sql, 'table_name')) {
            $t = static fn($sec) => date('Y-m-d H:i:s', time() - $sec);
            return [
                ['order_head_id'=>1,'t0'=>$t(1800),'guests'=>4,'table_name'=>'51','employee'=>'Jefe',
                 'amount'=>95.6,'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0],
                ['order_head_id'=>2,'t0'=>$t(3600),'guests'=>4,'table_name'=>'52','employee'=>'Jefe',
                 'amount'=>47.8,'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0],
                ['order_head_id'=>3,'t0'=>$t(600), 'guests'=>2,'table_name'=>'53','employee'=>'A',
                 'amount'=>5.9,'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0],
                ['order_head_id'=>4,'t0'=>$t(900), 'guests'=>2,'table_name'=>'54','employee'=>'B',
                 'amount'=>71.7,'checks'=>2,'eat_type'=>0,'status'=>0,'settled'=>0],
                ['order_head_id'=>5,'t0'=>$t(300), 'guests'=>0,'table_name'=>'Llevar','employee'=>'C',
                 'amount'=>20.0,'checks'=>1,'eat_type'=>3,'status'=>0,'settled'=>0],
                ['order_head_id'=>6,'t0'=>$t(21600),'guests'=>2,'table_name'=>'55','employee'=>'D',
                 'amount'=>47.8,'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0],
            ];
        }
        if (str_contains($sql, 'AS combo_qty')) {
            return [
                ['order_head_id'=>1,'combo_qty'=>4,'drink_qty'=>4,'drink_amount'=>10.0,'dish_qty'=>12,'lines_cnt'=>10],
                ['order_head_id'=>2,'combo_qty'=>2,'drink_qty'=>4,'drink_amount'=>10.0,'dish_qty'=>8, 'lines_cnt'=>7],
                ['order_head_id'=>3,'combo_qty'=>0,'drink_qty'=>2,'drink_amount'=>5.0, 'dish_qty'=>2, 'lines_cnt'=>2],
                ['order_head_id'=>4,'combo_qty'=>3,'drink_qty'=>1,'drink_amount'=>2.5, 'dish_qty'=>9, 'lines_cnt'=>8],
                ['order_head_id'=>5,'combo_qty'=>1,'drink_qty'=>0,'drink_amount'=>0.0, 'dish_qty'=>3, 'lines_cnt'=>3],
                ['order_head_id'=>6,'combo_qty'=>2,'drink_qty'=>2,'drink_amount'=>5.0, 'dish_qty'=>6, 'lines_cnt'=>5],
            ];
        }
        if (str_contains($sql, 'FROM menu_item')) {
            return [
                ['item_id'=>1,  'item_name1'=>'1-Edamame','item_name2'=>'','print_class'=>11,'item_type'=>0],
                ['item_id'=>2,  'item_name1'=>'MENÚ INFINITY NOCHE-FESTIVO-FIN DE SEMANA-ADULTOS','item_name2'=>'','print_class'=>11,'item_type'=>0],
                ['item_id'=>431,'item_name1'=>'Agua','item_name2'=>'','print_class'=>6,'item_type'=>0],
                ['item_id'=>900,'item_name1'=>'S/Pepino','item_name2'=>'','print_class'=>null,'item_type'=>1],
                ['item_id'=>999,'item_name1'=>'没人点的菜','item_name2'=>'','print_class'=>6,'item_type'=>0],
                ['item_id'=>1890,'item_name1'=>'MENÚ INFINITY MEDIODIA - ADULTOS','item_name2'=>'','print_class'=>11,'item_type'=>0,'price_1'=>18.90],
                ['item_id'=>2390,'item_name1'=>'MENÚ INFINITY NOCHE LUNES A JUEVES-ADULTOS','item_name2'=>'','print_class'=>11,'item_type'=>0,'price_1'=>23.90],
            ];
        }
        if (str_contains($sql, 'COUNT(DISTINCT order_head_id)')) {
            return [
                ['pc'=>11,'seg'=>'day',  'orders'=>30,'items'=>2,'qty'=>50,'lines_cnt'=>40,'amount'=>0],
                ['pc'=>11,'seg'=>'night','orders'=>20,'items'=>2,'qty'=>35,'lines_cnt'=>25,'amount'=>0],
                ['pc'=>6, 'seg'=>'day',  'orders'=>45,'items'=>1,'qty'=>60,'lines_cnt'=>55,'amount'=>180.0],
                ['pc'=>-1,'seg'=>'day',  'orders'=>2, 'items'=>1,'qty'=>2, 'lines_cnt'=>2, 'amount'=>0],
                ['pc'=>-2,'seg'=>'gap',  'orders'=>1, 'items'=>1,'qty'=>1, 'lines_cnt'=>1, 'amount'=>0],
            ];
        }
        if (str_contains($sql, 'FROM print_class')) {
            return [['print_class_id'=>6,'print_class_name'=>'bebidas'],
                    ['print_class_id'=>11,'print_class_name'=>'热菜']];
        }
        if (str_contains($sql, 'history_order_head') || str_contains($sql, ' order_head')) {
            return [
                ['biz_date'=>'2026-08-12','seg'=>'day','checks'=>30,'guests'=>70,'actual'=>1500.0,
                 'original'=>1500.0,'discount'=>0,'service'=>0,'tax'=>136.36,'should_amt'=>1500.0,'ret'=>0],
                ['biz_date'=>'2026-08-13','seg'=>'day','checks'=>50,'guests'=>120,'actual'=>3000.0,
                 'original'=>3200.0,'discount'=>-200.0,'service'=>0,'tax'=>272.73,'should_amt'=>3000.0,'ret'=>0],
                ['biz_date'=>'2026-08-13','seg'=>'night','checks'=>40,'guests'=>100,'actual'=>2400.0,
                 'original'=>2450.0,'discount'=>0,'service'=>0,'tax'=>218.18,'should_amt'=>2450.0,'ret'=>50.0],
            ];
        }
        if (str_contains($sql, 'menu_item_id = :item')) {
            return [
                ['biz_date'=>'2026-08-12','seg'=>'day','qty'=>4,'times'=>4,'amount'=>11.2],
                ['biz_date'=>'2026-08-13','seg'=>'night','qty'=>7,'times'=>6,'amount'=>19.6],
            ];
        }
        if (str_contains($sql, 'order_detail')) {
            return [
                ['menu_item_id'=>1,'item_name'=>'1-Edamame','seg'=>'day','qty'=>10,'times'=>8,'amount'=>0],
                ['menu_item_id'=>1,'item_name'=>'1-Edamame','seg'=>'night','qty'=>25,'times'=>20,'amount'=>0],
                ['menu_item_id'=>2,'item_name'=>'2-Takoyaki','seg'=>'day','qty'=>40,'times'=>30,'amount'=>0],
                ['menu_item_id'=>431,'item_name'=>'Agua','seg'=>'night','qty'=>5,'times'=>5,'amount'=>14.0],
                ['menu_item_id'=>900,'item_name'=>'S/Pepino','seg'=>'day','qty'=>99,'times'=>99,'amount'=>0],
            ];
        }
        return [];
    }
    public static function selectOne(string $sql, array $params = []): ?array {
        return self::select($sql, $params)[0] ?? null;
    }
}
PHP

# 渲染测试里跳过登录（登录逻辑由 selftest.php 单独覆盖）
cat > "$T/lib/auth.php" <<'PHP'
<?php
declare(strict_types=1);
final class Auth {
    public static function boot(): void {}
    public static function isConfigured(): bool { return true; }
    public static function isLoggedIn(): bool { return getenv('STUB_OUT') !== '1'; }
    public static function verify(string $p): bool { return $p === 'x'; }
    public static function login(): void {}
    public static function logout(): void {}
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) { echo 'REDIRECT_TO_LOGIN'; exit; }
    }
    public static function csrfToken(): string { return 'tok'; }
    public static function csrfValid(?string $t): bool { return $t === 'tok'; }
}
PHP

run() { # $1=页面 $2=query string $3=用例名
  out=$(cd "$T" && QUERY_STRING="$2" REQUEST_METHOD=GET STUB_OUT="${STUB_OUT:-0}" \
        php -d error_reporting=E_ALL -d display_errors=1 \
        -r "parse_str(getenv('QUERY_STRING'), \$_GET); include '$1';" 2>&1)
  if echo "$out" | grep -qiE '(Fatal error|Parse error|Warning:|Deprecated:|Notice:|Uncaught)'; then
    echo "  ✗ $3"
    echo "$out" | grep -iE '(Fatal|Parse|Warning|Deprecated|Notice|Uncaught)' | head -4 | sed 's/^/      /'
    return 1
  fi
  # 页面结构完整性
  if ! echo "$out" | grep -q '</html>'; then echo "  ✗ $3 —— 页面未渲染完整"; return 1; fi
  echo "  ✓ $3  ($(echo "$out" | wc -c) 字节)"
  echo "$out" > "$T/out_$3.html"
  return 0
}

run_expect() { # $1=页面 $2=qs $3=期望出现的字符串 $4=用例名
  out=$(cd "$T" && QUERY_STRING="$2" REQUEST_METHOD=GET STUB_OUT="${STUB_OUT:-0}" \
        php -d error_reporting=E_ALL -d display_errors=1 \
        -r "parse_str(getenv('QUERY_STRING'), \$_GET); include '$1';" 2>&1)
  if echo "$out" | grep -qiE '(Fatal error|Parse error|Warning:|Deprecated:|Notice:)'; then
    echo "  ✗ $4 —— 有 PHP 错误"; echo "$out" | head -3 | sed 's/^/      /'; return 1
  fi
  if echo "$out" | grep -q "$3"; then echo "  ✓ $4"; return 0; fi
  echo "  ✗ $4 —— 没有出现 $3"; return 1
}

fails=0
echo "渲染测试："
run index.php ""                                                        "首页-未查询"     || fails=$((fails+1))
run index.php "go=1&start=2026-08-12&end=2026-08-13"                    "营业额-区间"     || fails=$((fails+1))
run index.php "go=1&start=2026-08-13&end=2026-08-13&eat_type=3"         "营业额-当天外带" || fails=$((fails+1))
run index.php "go=1&start=2026-08-13&end=2026-08-13&exclude_zero=1"     "营业额-排除0元"  || fails=$((fails+1))
run index.php "go=1&start=2026-01-01&end=2026-08-13"                    "营业额-超3个月"  || fails=$((fails+1))
run index.php "go=1&start=2026-08-13&end=2026-08-01"                    "营业额-日期倒置" || fails=$((fails+1))
run dish.php  ""                                                        "菜品-未查询"     || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13"                    "菜品-排行榜"     || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&seg=day"            "菜品-白天榜"     || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&seg=night"          "菜品-晚上榜"     || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&show_never=1"       "菜品-零点单"     || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&mode=item&item_id=431" "菜品-单菜明细" || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&mode=item&item_id=99999" "菜品-不存在的菜" || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&pc=11"             "菜品-只看热菜岗位" || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&pc=6"              "菜品-只看bebidas" || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&pc=none"           "菜品-只看未分配"  || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&pc=99999"          "菜品-不存在岗位"  || fails=$((fails+1))
run station.php "go=1&start=2026-08-12&end=2026-08-13"                "岗位-单量排名"   || fails=$((fails+1))
run station.php "go=1&start=2026-08-12&end=2026-08-13&sort=qty"       "岗位-按份数"     || fails=$((fails+1))
run station.php "go=1&start=2026-08-12&end=2026-08-13&sort=amount"    "岗位-按金额"     || fails=$((fails+1))
run station.php "go=1&start=2026-08-12&end=2026-08-13&sort=%3Cbad%3E" "岗位-非法排序"   || fails=$((fails+1))
run station.php ""                                                     "岗位-未查询"     || fails=$((fails+1))
run meat.php ""                                                         "采购-录入页"     || fails=$((fails+1))
run meat.php "pending=1"                                                "采购-只看待补"   || fails=$((fails+1))
run meat.php "deleted=1"                                                "采购-含已作废"   || fails=$((fails+1))
run meat.php "kind=salmon&from=2020-01-01&to=2030-01-01"                 "采购-按品类筛"   || fails=$((fails+1))
run meat.php "kind=%3Cscript%3E&edit=abc"                               "采购-非法参数"   || fails=$((fails+1))
run meatweek.php ""                                                     "采购-周报表"     || fails=$((fails+1))
run meatweek.php "weeks=4"                                              "采购-周报表4周"  || fails=$((fails+1))
run meatweek.php "weeks=12"                                             "采购-周报表12周" || fails=$((fails+1))
run meatweek.php "weeks=%3Cscript%3E"                                   "采购-周报表非法" || fails=$((fails+1))
run stock.php ""                                                        "库存-录入页"     || fails=$((fails+1))
run stock.php "mk=count&deleted=1"                                      "库存-只看盘点"   || fails=$((fails+1))
run stock.php "item=%3Cscript%3E&edit=abc&at=xx"                        "库存-非法参数"   || fails=$((fails+1))
run stocknow.php ""                                                     "库存-当前库存"   || fails=$((fails+1))
run stocknow.php "item=beef"                                            "库存-指定品类"   || fails=$((fails+1))
run stocknow.php "item=%3Cscript%3E"                                    "库存-非法品类"   || fails=$((fails+1))
run compare.php ""                                                      "对比-未查询"     || fails=$((fails+1))
run compare.php "go=1&preset=7"                                         "对比-近7天"      || fails=$((fails+1))
run compare.php "go=1&preset=30&seg=night"                              "对比-近30天晚上" || fails=$((fails+1))
run compare.php "go=1&preset=&start=2026-08-01&end=2026-08-07"          "对比-自选区间"   || fails=$((fails+1))
run compare.php "go=1&preset=&start=2026-08-01&end=2026-08-07&manual=1&pstart=2025-08-01&pend=2025-08-07" "对比-手动对比期" || fails=$((fails+1))
run compare.php "go=1&preset=7&with_dish=1&with_station=1"              "对比-含菜品岗位" || fails=$((fails+1))
run compare.php "go=1&preset=&start=2026-01-01&end=2026-12-31"          "对比-超上限"     || fails=$((fails+1))
run compare.php "go=1&preset=%3Cbad%3E&seg=%3Cscript%3E"                "对比-非法参数"   || fails=$((fails+1))
run open.php "" "开台核对"                                  || fails=$((fails+1))
run open.php "issues=1" "开台核对-只看问题" || fails=$((fails+1))
run open.php "scope=all" "开台核对-全部订单" || fails=$((fails+1))
STUB_OUT=1 run_expect open.php "" "REDIRECT_TO_LOGIN" "未登录不能看开台核对" || fails=$((fails+1))
STUB_OUT=1 run login.php ""                                 "登录页"          || fails=$((fails+1))
STUB_OUT=1 run_expect index.php "" "REDIRECT_TO_LOGIN"                 "未登录跳转登录页" || fails=$((fails+1))
STUB_OUT=1 run_expect station.php "go=1&start=2026-08-12&end=2026-08-13" "REDIRECT_TO_LOGIN" "未登录不能看岗位页" || fails=$((fails+1))
STUB_OUT=1 run_expect dish.php "go=1&start=2026-08-12&end=2026-08-13" "REDIRECT_TO_LOGIN" "未登录不能看菜品页" || fails=$((fails+1))
run dish.php  "go=1&start=2026-08-12&end=2026-08-13&seg=%3Cscript%3E"   "菜品-非法seg参数" || fails=$((fails+1))

echo
echo "内容抽查："
chk() { if grep -q "$2" "$T/out_$1.html" 2>/dev/null; then echo "  ✓ $3"; else echo "  ✗ $3"; fails=$((fails+1)); fi }
chk "营业额-区间" "6,900.00"      "全天营业额 = 1500+3000+2400"
chk "营业额-区间" "290"           "全天人数 = 70+120+100"
chk "营业额-超3个月" "跨度不能超过" "超范围给出提示"
chk "营业额-日期倒置" "不能早于"    "日期倒置给出提示"
chk "菜品-排行榜" "MENÚ INFINITY" "排行榜含超长菜名"
chk "菜品-单菜明细" "Agua"        "单菜页显示菜名"
chk "菜品-只看热菜岗位" "MENÚ INFINITY" "选中岗位后仍显示该岗位菜品"
chk "菜品-排行榜" "各岗位点单排行" "全部岗位模式保留逐个岗位列表"
chk "菜品-排行榜" "岗位汇总"     "全部岗位模式有岗位汇总表"
if grep -q "各岗位点单排行" "$T/out_菜品-只看热菜岗位.html"; then echo "  ✗ 选中单一岗位时不该再列出全部岗位"; fails=$((fails+1));
else echo "  ✓ 选中单一岗位时不再列出全部岗位"; fi
# 只检查表格区域（<script> 之前）；搜索下拉的菜品清单本就该包含全部菜品
if sed '/<script>/,$d' "$T/out_菜品-只看热菜岗位.html" | grep -q "Agua"; then
  echo "  ✗ 热菜岗位的表格里混入了 bebidas 的菜"; fails=$((fails+1));
else echo "  ✓ 岗位筛选未串味（表格内只有本岗位菜品）"; fi
if sed '/<script>/,$d' "$T/out_菜品-只看bebidas.html" | grep -q "MENÚ INFINITY"; then
  echo "  ✗ bebidas 岗位的表格里混入了热菜"; fails=$((fails+1));
else echo "  ✓ 反向筛选同样未串味"; fi
chk "岗位-单量排名" "岗位排名"      "岗位页有排名表"
chk "岗位-单量排名" "未分配岗位"    "未分配岗位单独成行"
chk "岗位-单量排名" "菜品已从菜单删除" "已删除菜品单独成行"
chk "岗位-单量排名" "98"            "合计单量 = 30+20+45+2+1"
chk "开台核对" "套餐打少了"   "识别出套餐打少的台"
chk "开台核对" "未打套餐"     "识别出未打套餐的台"
chk "开台核对" "套餐打多了"   "识别出套餐打多的台"
chk "开台核对" "未填人数"     "识别出未填人数的台"
chk "开台核对" "当前算作"     "列出生效的套餐清单"
if grep -q "class=\"state s-ok\"" "$T/out_开台核对.html"; then echo "  ✓ 一致的台标为正常"; else echo "  ✗ 缺少一致状态"; fails=$((fails+1)); fi
if grep -q "stale" "$T/out_开台核对.html"; then echo "  ✓ 滞留台被标记"; else echo "  ✗ 滞留台未标记"; fails=$((fails+1)); fi
# 底部套餐清单里也用 s-ok 表示「菜品在菜单里存在」，所以要认状态文字而不是类名
if grep -q ">套餐一致<" "$T/out_开台核对-只看问题.html"; then
  echo "  ✗ 只看问题时混入了一致的台"; fails=$((fails+1));
else echo "  ✓ 只看问题时不含一致的台"; fi
if grep -q ">套餐一致<" "$T/out_开台核对.html"; then echo "  ✓ 完整列表里包含一致的台";
else echo "  ✗ 完整列表里缺少一致的台"; fails=$((fails+1)); fi
chk "登录页" "请输入密码"           "登录页渲染"
chk "岗位-单量排名" "退出"          "导航栏有退出入口"
if grep -q "岗位单量排名" "$T/out_营业额-区间.html"; then echo "  ✓ 导航栏含岗位页入口"; else echo "  ✗ 导航栏缺岗位页入口"; fails=$((fails+1)); fi
if grep -q "S/Pepino" "$T/out_菜品-排行榜.html"; then echo "  ✗ 做法项混进了排行榜"; fails=$((fails+1));
else echo "  ✓ 做法项未混进排行榜"; fi
if grep -q "<script>alert" "$T/out_菜品-非法seg参数.html" 2>/dev/null; then echo "  ✗ 参数未转义"; fails=$((fails+1));
else echo "  ✓ 非法参数被安全处理"; fi

echo
[ $fails -eq 0 ] && echo "全部通过" || echo "失败 $fails 项"
echo "SAVED:$T"
exit $fails
