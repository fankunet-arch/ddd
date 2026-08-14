<?php
/**
 * 自检脚本 —— 不需要连接数据库即可运行：
 *
 *     php tests/selftest.php
 *
 * 校验三件事：
 *   1. 只读防线是否拦得住写操作语句；
 *   2. 日期范围换算与 3 个月上限校验是否正确；
 *   3. 汇总/排行逻辑在真实数据形态下是否算得对（含分单人数去重这个关键点）。
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/biz.php';
require_once __DIR__ . '/../lib/report.php';

$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$name}" . ($extra !== '' ? "  → {$extra}" : '') . "\n";
    }
}

function eq(string $name, $actual, $expected): void
{
    ok($name, $actual == $expected, 'got ' . var_export($actual, true) . ', want ' . var_export($expected, true));
}

function throws(string $name, callable $fn): void
{
    try {
        $fn();
        ok($name, false, '没有抛出异常');
    } catch (Throwable $e) {
        ok($name, true);
    }
}

// =====================================================================
echo "\n【1】只读防线\n";
// =====================================================================

foreach ([
    'INSERT INTO order_head VALUES (1)',
    'UPDATE order_head SET status = 1',
    'DELETE FROM order_head',
    'DROP TABLE order_head',
    'TRUNCATE order_head',
    'ALTER TABLE order_head ADD COLUMN x INT',
    'REPLACE INTO order_head VALUES (1)',
    'CREATE TABLE t (a INT)',
    'SELECT 1; DROP TABLE order_head',            // 多语句
    'SELECT 1 /* x */ ; DELETE FROM t',           // 注释里藏分号
    "SELECT * INTO OUTFILE '/tmp/x' FROM order_head",
    'CALL some_proc()',
    'GRANT ALL ON *.* TO x',
] as $bad) {
    throws('拒绝: ' . substr($bad, 0, 46), static fn() => Db::assertReadOnly($bad));
}

// 正常语句必须放行 —— 包括程序真实生成的那几条
foreach ([
    'SELECT 1',
    'SELECT item_id, item_name1 FROM menu_item ORDER BY item_id',
] as $good) {
    ok('放行: ' . substr($good, 0, 46), (static function () use ($good) {
        try { Db::assertReadOnly($good); return true; } catch (Throwable $e) { return false; }
    })());
}

// =====================================================================
echo "\n【2】真实生成的 SQL 必须能通过只读检查\n";
// =====================================================================

[$from, $to] = Biz::range('2026-05-01', '2026-07-31');
$built = [
    '营业额(历史表)'   => Biz::buildSalesSql($from, $to, 'history_order_head'),
    '营业额(实时表)'   => Biz::buildSalesSql($from, $to, 'order_head', ['eat_type' => '0', 'exclude_zero' => true]),
    '菜品汇总(历史表)' => Biz::buildDishTotalsSql($from, $to, 'history_order_detail'),
    '菜品汇总(实时表)' => Biz::buildDishTotalsSql($from, $to, 'order_detail', ['include_combo_child' => true]),
    '单菜品(历史表)'   => Biz::buildDishByDaySql($from, $to, 'history_order_detail', 431),
];
foreach ($built as $label => [$sql, $params]) {
    ok("{$label} 通过只读检查", (static function () use ($sql) {
        try { Db::assertReadOnly($sql); return true; } catch (Throwable $e) { return false; }
    })());
    ok("{$label} 参数全部走绑定", !preg_match('/\'20\d\d-\d\d-\d\d /', $sql), '日期被拼进了 SQL');
}

// 表名白名单
throws('拒绝非法表名', static fn() => Biz::buildSalesSql($from, $to, 'order_head; DROP TABLE x'));
throws('拒绝未授权表名', static fn() => Biz::buildDishTotalsSql($from, $to, 'employee'));

// =====================================================================
echo "\n【3】日期范围换算\n";
// =====================================================================

[$f, $t] = Biz::range('2026-08-01', '2026-08-03');
eq('起点 = 首日 08:00', $f, '2026-08-01 08:00:00');
eq('终点 = 末日次日 02:00（覆盖末日晚市跨夜）', $t, '2026-08-04 02:00:00');

[$f1, $t1] = Biz::range('2026-08-13', '2026-08-13');
eq('单日查询起点', $f1, '2026-08-13 08:00:00');
eq('单日查询终点', $t1, '2026-08-14 02:00:00');

eq('92 天合法', Biz::validateRange('2026-05-01', '2026-07-31'), null);
ok('93 天被拒', Biz::validateRange('2026-05-01', '2026-08-01') !== null);
ok('结束早于开始被拒', Biz::validateRange('2026-08-05', '2026-08-01') !== null);
ok('非法日期被拒', Biz::validateRange('乱写', '2026-08-01') !== null);

// =====================================================================
echo "\n【4】汇总逻辑（分单人数去重 —— 真实数据里会导致 12% 虚高）\n";
// =====================================================================

// 模拟数据库返回：8/13 白天与晚上各若干组
$histRows = [
    ['biz_date' => '2026-08-13', 'seg' => 'day',   'checks' => 50, 'guests' => 120,
     'actual' => 3000.00, 'original' => 3200.00, 'discount' => -200.00, 'service' => 0,
     'tax' => 272.73, 'should_amt' => 3000.00, 'ret' => 0],
    ['biz_date' => '2026-08-13', 'seg' => 'night', 'checks' => 40, 'guests' => 100,
     'actual' => 2400.00, 'original' => 2400.00, 'discount' => 0, 'service' => 0,
     'tax' => 218.18, 'should_amt' => 2450.00, 'ret' => 50.00],
    ['biz_date' => '2026-08-12', 'seg' => 'day',   'checks' => 30, 'guests' => 70,
     'actual' => 1500.00, 'original' => 1500.00, 'discount' => 0, 'service' => 0,
     'tax' => 136.36, 'should_amt' => 1500.00, 'ret' => 0],
];
// 实时表（未日结）补充同一天的数据，必须能正确合并进去
$liveRows = [
    ['biz_date' => '2026-08-13', 'seg' => 'night', 'checks' => 5, 'guests' => 12,
     'actual' => 300.00, 'original' => 300.00, 'discount' => 0, 'service' => 0,
     'tax' => 27.27, 'should_amt' => 300.00, 'ret' => 0],
];

$p = Report::pivotSales($histRows, $liveRows);
eq('营业日数', count($p['days']), 2);
eq('日期升序', array_keys($p['days']), ['2026-08-12', '2026-08-13']);
eq('8/13 晚上账单数 = 历史 40 + 实时 5', $p['days']['2026-08-13']['night']['checks'], 45);
eq('8/13 晚上营业额 = 2400 + 300', $p['days']['2026-08-13']['night']['actual'], 2700.00);
eq('8/13 全天 = 白天 + 晚上', $p['days']['2026-08-13']['total']['actual'], 3000.00 + 2700.00);
eq('8/13 全天人数', $p['days']['2026-08-13']['total']['guests'], 120 + 112);
eq('总营业额', $p['total']['total']['actual'], 3000.00 + 2700.00 + 1500.00);
eq('总人数', $p['total']['total']['guests'], 120 + 112 + 70);
eq('白天小计', $p['total']['day']['actual'], 4500.00);
eq('晚上小计', $p['total']['night']['actual'], 2700.00);
ok('全天 = 白天 + 晚上 + 时段外',
   abs($p['total']['total']['actual']
       - ($p['total']['day']['actual'] + $p['total']['night']['actual'] + $p['total']['gap']['actual'])) < 0.001);

// 金额恒等式：应收 = 原价 + 折扣（折扣记负数）；实收 = 应收 - 退单
$d = $p['days']['2026-08-13']['day'];
ok('应收 = 原价 + 折扣', abs(($d['original'] + $d['discount']) - $d['should_amt']) < 0.001);
$n = $p['days']['2026-08-13']['night'];
ok('实收 = 应收 - 退单', abs(($n['should_amt'] - $n['ret']) - $n['actual']) < 0.001);

// =====================================================================
echo "\n【5】菜品排行逻辑\n";
// =====================================================================

// 字典：3 个真菜 + 1 个做法项（item_type=1，必须被排除）
$menu = [
    1   => ['name' => '1-Edamame',    'name2' => '', 'print_class' => 11, 'is_condiment' => false],
    2   => ['name' => '2-Takoyaki',   'name2' => '', 'print_class' => 11, 'is_condiment' => false],
    431 => ['name' => 'Agua',         'name2' => '', 'print_class' => 6,  'is_condiment' => false],
    900 => ['name' => 'S/Pepino',     'name2' => '', 'print_class' => null, 'is_condiment' => true],
    999 => ['name' => '从没点过的菜',  'name2' => '', 'print_class' => 6,  'is_condiment' => false],
];
$pcs = [6 => 'bebidas', 11 => '热菜'];

$dishRows = [
    ['menu_item_id' => 1,   'item_name' => '1-Edamame',  'seg' => 'day',   'qty' => 10, 'times' => 8,  'amount' => 0],
    ['menu_item_id' => 1,   'item_name' => '1-Edamame',  'seg' => 'night', 'qty' => 25, 'times' => 20, 'amount' => 0],
    ['menu_item_id' => 2,   'item_name' => '2-Takoyaki', 'seg' => 'day',   'qty' => 40, 'times' => 30, 'amount' => 0],
    ['menu_item_id' => 431, 'item_name' => 'Agua',       'seg' => 'night', 'qty' => 5,  'times' => 5,  'amount' => 14.00],
    // 做法项必须被剔除
    ['menu_item_id' => 900, 'item_name' => 'S/Pepino',   'seg' => 'day',   'qty' => 99, 'times' => 99, 'amount' => 0],
];
$b = Report::buildDishes($menu, $pcs, $dishRows);

ok('做法项(item_type=1)被排除', !isset($b['items'][900]));
eq('参与统计的菜品数', count($b['items']), 3);
eq('菜品 1 全天点单 = 白天10 + 晚上25', $b['items'][1]['total']['qty'], 35.0);
eq('菜品 1 岗位名', $b['items'][1]['pc_name'], '热菜');
eq('菜品 431 岗位名', $b['items'][431]['pc_name'], 'bebidas');
eq('全天总点单量', $b['grand']['total']['qty'], 10.0 + 25.0 + 40.0 + 5.0);
eq('白天总点单量', $b['grand']['day']['qty'], 10.0 + 40.0);

$top = Report::rank($b['items'], 'total', 'desc', 10);
eq('全天最多第 1 名', $top[0]['name'], '2-Takoyaki');   // 40
eq('全天最多第 2 名', $top[1]['name'], '1-Edamame');    // 35
$topDay = Report::rank($b['items'], 'day', 'desc', 10);
eq('白天最多第 1 名', $topDay[0]['name'], '2-Takoyaki'); // 40
eq('白天榜排除无白天记录的菜', count($topDay), 2);        // Agua 只有晚上，不入白天榜
$topNight = Report::rank($b['items'], 'night', 'desc', 10);
eq('晚上最多第 1 名', $topNight[0]['name'], '1-Edamame'); // 25
$bot = Report::rank($b['items'], 'total', 'asc', 10);
eq('全天最少第 1 名', $bot[0]['name'], 'Agua');           // 5

$st = Report::byStation($b['items'], 'total', 10);
eq('岗位组数', count($st), 2);
eq('点单量最大的岗位', $st[0]['pc_name'], '热菜');         // 35 + 40 = 75
eq('热菜岗位菜品数', $st[0]['items'], 2);
eq('热菜岗位最多', $st[0]['top'][0]['name'], '2-Takoyaki');
eq('热菜岗位最少', $st[0]['bottom'][0]['name'], '1-Edamame');

$never = Report::neverOrdered($menu, $b['items'], $pcs);
eq('零点单菜品数', count($never), 1);
eq('零点单菜品', $never[0]['name'], '从没点过的菜');
ok('零点单列表不含做法项', !in_array('S/Pepino', array_column($never, 'name'), true));

// 排序稳定性：数量相同按菜名排，保证同样输入永远同样输出
$tie = [
    10 => ['id' => 10, 'name' => 'BBB', 'pc' => 1, 'pc_name' => 'X', 'total' => ['qty' => 5, 'times' => 1, 'amount' => 0]],
    11 => ['id' => 11, 'name' => 'AAA', 'pc' => 1, 'pc_name' => 'X', 'total' => ['qty' => 5, 'times' => 1, 'amount' => 0]],
];
eq('并列时按菜名稳定排序', Report::rank($tie, 'total', 'desc', 10)[0]['name'], 'AAA');

// =====================================================================
echo "\n【6】时段划分表达式覆盖用户定义的边界\n";
// =====================================================================

$c = Db::config();
eq('白天起点', $c['day_start'], '08:00:00');
eq('白天终点', $c['day_end'], '17:30:00');
eq('晚上起点', $c['night_start'], '18:00:00');
eq('晚上终点(次日)', $c['night_end'], '02:00:00');
eq('营业日切分点与晚市收尾一致', $c['day_cut_hour'], 2);

[$sql] = Biz::buildSalesSql($from, $to, 'history_order_head');
ok('SQL 含白天区间判断', strpos($sql, "TIME(h.t0) >= '08:00:00'") !== false);
ok('SQL 含晚上跨夜判断', strpos($sql, "TIME(h.t0) < '02:00:00'") !== false);
ok('SQL 含营业日偏移', strpos($sql, 'INTERVAL 2 HOUR') !== false);
ok('SQL 按订单去重人数', strpos($sql, 'MAX(customer_num)') !== false);
ok('SQL 内层按 order_head_id 归并', strpos($sql, 'GROUP BY order_head_id') !== false);
ok('SQL 走索引（未对时间列套函数）', strpos($sql, 'order_start_time >= :from') !== false);

[$dsql] = Biz::buildDishTotalsSql($from, $to, 'history_order_detail');
ok('菜品 SQL 排除非菜品行(-3/-4)', strpos($dsql, 'menu_item_id > 0') !== false);
ok('菜品 SQL 排除退菜', strpos($dsql, 'is_return_item') !== false);
ok('菜品 SQL 排除套餐/做法子项', strpos($dsql, 'COALESCE(condiment_belong_item, 0) = 0') !== false);
ok('菜品 SQL 排除 0 数量标记行', strpos($dsql, 'quantity > 0') !== false);
ok('菜品 SQL 未与 menu_item 做 JOIN', stripos($dsql, 'join') === false);
ok('营业额 SQL 未做 JOIN', stripos($sql, 'join') === false);

// =====================================================================
printf("\n%s\n通过 %d 项，失败 %d 项\n", str_repeat('─', 46), $pass, $fail);
exit($fail === 0 ? 0 : 1);
