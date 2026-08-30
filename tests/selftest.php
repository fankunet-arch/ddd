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

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ack.php';
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
echo "\n【2b】mysqli 驱动的占位符转换\n";
// 关键风险：SQL 里有 '08:00:00' 这类时间常量，里面全是冒号。
// 转换 :name 占位符时必须跳过引号内的内容，否则会把 :00 当成参数。
// =====================================================================

[$q, $v] = MysqliDriver::toPositional(
    "SELECT * FROM t WHERE a >= :from AND a < :to AND TIME(x) >= '08:00:00'",
    [':from' => 'F', ':to' => 'T']
);
eq('时间常量里的冒号未被误认',
   $q, "SELECT * FROM t WHERE a >= ? AND a < ? AND TIME(x) >= '08:00:00'");
eq('参数顺序正确', $v, ['F', 'T']);

[$q2, $v2] = MysqliDriver::toPositional(
    "SELECT CASE WHEN TIME(c) >= '08:00:00' AND TIME(c) < '17:30:00' THEN 'day'
                 WHEN TIME(c) >= '18:00:00' OR TIME(c) < '02:00:00' THEN 'night'
            END FROM t WHERE c >= :from AND c < :to AND id = :item",
    [':from' => 'F', ':to' => 'T', ':item' => 431]
);
eq('多个时间常量场景下参数个数正确', count($v2), 3);
eq('多个时间常量场景下参数值正确', $v2, ['F', 'T', 431]);
foreach (["'08:00:00'", "'17:30:00'", "'18:00:00'", "'02:00:00'", "'day'", "'night'"] as $lit) {
    ok("字符串常量 {$lit} 原样保留", strpos($q2, $lit) !== false);
}
ok('占位符全部换成 ?', substr_count($q2, '?') === 3 && strpos($q2, ':from') === false);

// 真实 SQL 端到端转换
foreach ($built as $label => [$rsql, $rparams]) {
    [$cq, $cv] = MysqliDriver::toPositional($rsql, $rparams);
    ok("{$label} 转换后无残留命名占位符",
       !preg_match("/(?<!')\B:[A-Za-z_]\w*/", preg_replace("/'[^']*'/", "''", $cq)));
    eq("{$label} 参数个数与 ? 个数一致", substr_count($cq, '?'), count($cv));
}

// 参数缺失要报错，不能悄悄生成错误的 SQL
throws('缺参数时报错', static fn() => MysqliDriver::toPositional('SELECT :a', []));

// 转义引号不能让解析器跑偏
[$q3, $v3] = MysqliDriver::toPositional(
    "SELECT * FROM t WHERE n = 'it\\'s 12:30' AND a = :x", [':x' => 1]
);
eq('转义引号内的冒号未被误认', substr_count($q3, '?'), 1);
eq('转义引号场景参数正确', $v3, [1]);

// =====================================================================
echo "\n【2c】驱动选择\n";
// =====================================================================

$have = Db::availableDrivers();
ok('本机至少有一种可用驱动: ' . (implode(', ', $have) ?: '无'), count($have) > 0);
foreach ($have as $d) {
    ok("可用驱动 {$d} 名称合法", in_array($d, ['pdo', 'mysqli'], true));
}
ok('PdoDriver 类存在', class_exists('PdoDriver'));
ok('MysqliDriver 类存在', class_exists('MysqliDriver'));
ok('两种驱动都实现了 DbDriver 接口',
   in_array('DbDriver', class_implements('PdoDriver') ?: [], true)
   && in_array('DbDriver', class_implements('MysqliDriver') ?: [], true));

// 程序不得依赖 mbstring —— 真实环境里常常没启用
$src = '';
foreach (['../lib/db.php', '../lib/biz.php', '../lib/report.php', '../lib/view.php',
          '../index.php', '../dish.php', 'checkdb.php', 'env.php'] as $f) {
    $src .= (string) file_get_contents(__DIR__ . '/' . $f);
}
ok('全程序未使用 mbstring 函数', !preg_match('/\bmb_[a-z_]+\s*\(/', $src));
ok('全程序未使用 iconv', !preg_match('/\biconv\s*\(/', $src));

// =====================================================================
echo "\n【2d】岗位单量 SQL\n";
// =====================================================================

// 菜品 → 岗位映射：3 个热菜(11)、2 个饮料(6)、1 个未配岗位
$pcMap = [1 => 11, 2 => 11, 3 => 11, 431 => 6, 432 => 6, 900 => null];
[$ssql, $sparams] = Biz::buildStationSql($from, $to, 'history_order_detail', $pcMap);

ok('岗位 SQL 通过只读检查', (static function () use ($ssql) {
    try { Db::assertReadOnly($ssql); return true; } catch (Throwable $e) { return false; }
})());
ok('岗位 SQL 未做 JOIN', stripos($ssql, 'join') === false);
ok('岗位 SQL 只查明细表', substr_count($ssql, 'history_order_detail') === 1);
ok('单量用 COUNT(DISTINCT order_head_id)',
   strpos($ssql, 'COUNT(DISTINCT order_head_id)') !== false);
ok('热菜岗位的菜品被编进 IN 列表', strpos($ssql, 'IN (1,2,3) THEN 11') !== false);
ok('饮料岗位的菜品被编进 IN 列表', strpos($ssql, 'IN (431,432) THEN 6') !== false);
ok('未配岗位归为 ' . Biz::PC_NONE, strpos($ssql, 'IN (900) THEN -1') !== false);
ok('字典外的菜品归为 ' . Biz::PC_UNKNOWN, strpos($ssql, 'ELSE -2 END') !== false);
ok('岗位 SQL 走时间索引', strpos($ssql, 'order_time >= :from') !== false);
ok('岗位 SQL 参数走绑定', !preg_match('/\'20\d\d-\d\d-\d\d /', $ssql));
eq('岗位 SQL 参数', array_keys($sparams), [':from', ':to']);
// SQL 里除了绑定参数只能出现数字和岗位 ID，不能混入任何菜名之类的文本
ok('IN 列表只含数字', !preg_match('/IN \([^)]*[^0-9,)][^)]*\)/', $ssql));

// 映射为空时不能生成语法错误的 CASE
[$esql] = Biz::buildStationSql($from, $to, 'history_order_detail', []);
ok('空映射不生成空 CASE', strpos($esql, 'CASE ELSE') === false && strpos($esql, '-2 AS pc') !== false);

// 非法菜品 ID 要被丢弃，不能拼进 SQL
[$bsql] = Biz::buildStationSql($from, $to, 'history_order_detail',
    ['5; DROP TABLE x' => 1, '-3' => 1, '0' => 1, 7 => 1]);
ok('注入文本被 (int) 强转剥掉', strpos($bsql, 'DROP') === false && strpos($bsql, ';') === false);
ok('负数与 0 的菜品 ID 被剔除', strpos($bsql, '-3') === false && !preg_match('/IN \([^)]*\b0\b/', $bsql));
ok('合法 ID 保留', strpos($bsql, 'IN (5,7)') !== false);
ok('剔除后 SQL 仍通过只读检查', (static function () use ($bsql) {
    try { Db::assertReadOnly($bsql); return true; } catch (Throwable $e) { return false; }
})());

// ---- 岗位结果聚合与排名 ----
$pcs2 = [6 => 'bebidas', 11 => '热菜'];
$stRows = [
    ['pc' => 11, 'seg' => 'day',   'orders' => 30, 'items' => 3, 'qty' => 50, 'lines_cnt' => 40, 'amount' => 0],
    ['pc' => 11, 'seg' => 'night', 'orders' => 20, 'items' => 3, 'qty' => 35, 'lines_cnt' => 25, 'amount' => 0],
    ['pc' => 6,  'seg' => 'day',   'orders' => 45, 'items' => 2, 'qty' => 60, 'lines_cnt' => 55, 'amount' => 180.0],
    ['pc' => -1, 'seg' => 'day',   'orders' => 2,  'items' => 1, 'qty' => 2,  'lines_cnt' => 2,  'amount' => 0],
    ['pc' => -2, 'seg' => 'night', 'orders' => 1,  'items' => 1, 'qty' => 1,  'lines_cnt' => 1,  'amount' => 0],
];
$stLive = [
    ['pc' => 11, 'seg' => 'night', 'orders' => 5, 'items' => 1, 'qty' => 6, 'lines_cnt' => 6, 'amount' => 0],
];
$sb = Report::buildStations($pcs2, $stRows, $stLive);

eq('岗位数（含未分配与已删除）', count($sb['stations']), 4);
$byPc = [];
foreach ($sb['stations'] as $s) { $byPc[$s['pc']] = $s; }
eq('热菜全天单量 = 30 + 20 + 实时 5', $byPc[11]['total']['orders'], 55);
eq('热菜白天单量', $byPc[11]['day']['orders'], 30);
eq('热菜晚上单量 = 20 + 实时 5', $byPc[11]['night']['orders'], 25);
eq('bebidas 全天单量', $byPc[6]['total']['orders'], 45);
eq('未分配岗位名称', $byPc[-1]['pc_name'], '未分配岗位');
eq('已删除菜品名称', $byPc[-2]['pc_name'], '菜品已从菜单删除');
eq('岗位名来自字典', $byPc[11]['pc_name'], '热菜');
eq('合计单量', $sb['grand']['total']['orders'], 30 + 20 + 5 + 45 + 2 + 1);
eq('白天合计单量', $sb['grand']['day']['orders'], 30 + 45 + 2);

$ranked = Report::sortStations($sb['stations'], 'orders');
eq('按单量排名第 1', $ranked[0]['pc_name'], '热菜');       // 55
eq('按单量排名第 2', $ranked[1]['pc_name'], 'bebidas');    // 45
$byQty = Report::sortStations($sb['stations'], 'qty');
eq('按份数排名第 1', $byQty[0]['pc_name'], '热菜');        // 50+35+6=91 > bebidas 60
eq('按份数排名第 2', $byQty[1]['pc_name'], 'bebidas');     // 60
$byAmt = Report::sortStations($sb['stations'], 'amount');
eq('按金额排名第 1', $byAmt[0]['pc_name'], 'bebidas');     // 180
ok('非法排序字段回退到单量',
   Report::sortStations($sb['stations'], '乱写')[0]['pc_name'] === '热菜');

// =====================================================================
echo "\n【2f】开台核对\n";
// =====================================================================

[$osql, $oparams] = Biz::buildOpenTablesSql(true);
ok('开台 SQL 通过只读检查', (static function () use ($osql) {
    try { Db::assertReadOnly($osql); return true; } catch (Throwable $e) { return false; }
})());
ok('只查 order_head 一张表',
   substr_count($osql, 'FROM order_head') === 1 && stripos($osql, 'join') === false);
ok('未结算判定为 order_end_time IS NULL', strpos($osql, 'order_end_time IS NULL') !== false);
ok('按订单归并去重人数',
   strpos($osql, 'MAX(customer_num)') !== false && strpos($osql, 'GROUP BY order_head_id') !== false);
[$asql] = Biz::buildOpenTablesSql(false);
ok('查全部时不加未结算条件', strpos($asql, 'order_end_time IS NULL') === false);

[$csql] = Biz::buildComboCountSql([101, 102], [1890, 2390]);
ok('套餐份数 SQL 通过只读检查', (static function () use ($csql) {
    try { Db::assertReadOnly($csql); return true; } catch (Throwable $e) { return false; }
})());
ok('只查 order_detail 一张表',
   substr_count($csql, 'FROM order_detail') === 1 && stripos($csql, 'join') === false);
ok('订单号编进 IN 列表', strpos($csql, 'IN (101,102)') !== false);
ok('套餐份数与总菜品数一次查出',
   strpos($csql, 'IN (1890,2390) THEN quantity') !== false && strpos($csql, 'SUM(quantity) AS dish_qty') !== false);
ok('套餐清单为空时份数恒为 0',
   strpos(Biz::buildComboCountSql([101], [])[0], '0  AS combo_qty') !== false);
[$isql] = Biz::buildComboCountSql(['5; DROP TABLE x', -1, 0, 9], ['7; DELETE', 1890]);
ok('订单号里的注入文本被剥掉', strpos($isql, 'DROP') === false && strpos($isql, 'DELETE') === false);
ok('非法订单号被剔除', strpos($isql, 'IN (5,9)') !== false);
throws('订单号全非法时报错', static fn() => Biz::buildComboCountSql([0, -1], [1890]));

// ---- 逐桌比对 ----
$heads = [
    // 4 人打了 4 份 —— 一致
    ['order_head_id' => 1, 't0' => date('Y-m-d H:i:s', time() - 1800), 'guests' => 4,
     'table_name' => '51', 'employee' => 'Jefe', 'amount' => 95.6, 'checks' => 1,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
    // 4 人只打了 2 份 —— 少了
    ['order_head_id' => 2, 't0' => date('Y-m-d H:i:s', time() - 3600), 'guests' => 4,
     'table_name' => '52', 'employee' => 'Jefe', 'amount' => 47.8, 'checks' => 1,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
    // 2 人一份没打 —— 未打套餐
    ['order_head_id' => 3, 't0' => date('Y-m-d H:i:s', time() - 600), 'guests' => 2,
     'table_name' => '53', 'employee' => 'A', 'amount' => 5.9, 'checks' => 1,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
    // 2 人打了 3 份 —— 多了
    ['order_head_id' => 4, 't0' => date('Y-m-d H:i:s', time() - 900), 'guests' => 2,
     'table_name' => '54', 'employee' => 'B', 'amount' => 71.7, 'checks' => 1,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
    // 没填人数
    ['order_head_id' => 5, 't0' => date('Y-m-d H:i:s', time() - 300), 'guests' => 0,
     'table_name' => 'Llevar', 'employee' => 'C', 'amount' => 20.0, 'checks' => 1,
     'eat_type' => 3, 'status' => 0, 'settled' => 0],
    // 开台 6 小时还没结 —— 滞留
    ['order_head_id' => 6, 't0' => date('Y-m-d H:i:s', time() - 6 * 3600), 'guests' => 2,
     'table_name' => '55', 'employee' => 'D', 'amount' => 47.8, 'checks' => 2,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
];
$counts = [
    ['order_head_id' => 1, 'combo_qty' => 4, 'dish_qty' => 12, 'lines_cnt' => 10],
    ['order_head_id' => 2, 'combo_qty' => 2, 'dish_qty' => 8,  'lines_cnt' => 7],
    // 3 号桌只点了水，没有套餐行 —— 明细里查得到但 combo_qty 为 0
    ['order_head_id' => 3, 'combo_qty' => 0, 'dish_qty' => 2,  'lines_cnt' => 2],
    ['order_head_id' => 4, 'combo_qty' => 3, 'dish_qty' => 9,  'lines_cnt' => 8],
    ['order_head_id' => 5, 'combo_qty' => 1, 'dish_qty' => 3,  'lines_cnt' => 3],
    ['order_head_id' => 6, 'combo_qty' => 2, 'dish_qty' => 6,  'lines_cnt' => 5],
];
$ot = Report::buildOpenTables($heads, $counts, 4);
$by = [];
foreach ($ot['rows'] as $r) { $by[$r['id']] = $r; }

eq('4人4份 → 一致',       $by[1]['state'], Report::OPEN_OK);
eq('4人2份 → 套餐打少了', $by[2]['state'], Report::OPEN_SHORT);
eq('2人0份 → 未打套餐',   $by[3]['state'], Report::OPEN_NONE);
eq('2人3份 → 套餐打多了', $by[4]['state'], Report::OPEN_OVER);
eq('没填人数 → 未填人数', $by[5]['state'], Report::OPEN_NOGUEST);
eq('少打的差额为负', $by[2]['diff'], -2.0);
eq('多打的差额为正', $by[4]['diff'], 1.0);
eq('一致的差额为零', $by[1]['diff'], 0.0);

ok('开台 6 小时标记为滞留', $by[6]['stale']);
ok('开台半小时不算滞留', !$by[1]['stale']);
eq('已开台分钟数约 60', (int) round($by[2]['minutes'] / 10) * 10, 60);

eq('开台数', $ot['sum']['tables'], 6);
eq('人数合计', $ot['sum']['guests'], 4 + 4 + 2 + 2 + 0 + 2);
eq('套餐份数合计', $ot['sum']['combo'], 4 + 2 + 0 + 3 + 1 + 2);
// 1 号（4人4份）与 6 号（2人2份）份数都一致，只有 2/3/4/5 号有问题。
// 6 号虽然开台超时，但那是另一个维度，不算份数问题。
eq('需要核对的台数', $ot['sum']['problem'], 4);
eq('6 号桌份数一致', $by[6]['state'], Report::OPEN_OK);
ok('滞留与份数问题互相独立', $by[6]['stale'] && $by[6]['state'] === Report::OPEN_OK);
eq('滞留台数', $ot['sum']['stale'], 1);

// 明细表里完全没有记录的订单（刚开台还没下单）也要出现，且算作未打套餐
$ot2 = Report::buildOpenTables([$heads[0]], []);
eq('无任何明细的台仍会列出', count($ot2['rows']), 1);
eq('无明细 → 套餐份数 0', $ot2['rows'][0]['combo'], 0.0);
eq('无明细 → 判为未打套餐', $ot2['rows'][0]['state'], Report::OPEN_NONE);

// 排序：问题台排前面
$sorted = Report::sortOpenTables($ot['rows']);
eq('排序后第一个是未打套餐', $sorted[0]['state'], Report::OPEN_NONE);
eq('排序后最后一个是一致的', end($sorted)['state'], Report::OPEN_OK);
// 关闭问题优先后整体按桌号排（桌号 51~55 与 Llevar）
$byTbl = Report::sortOpenTables($ot['rows'], false);
ok('关闭问题优先后按桌号排',
   strnatcasecmp($byTbl[0]['table'], $byTbl[1]['table']) <= 0);
eq('关闭问题优先后第一个是最小桌号', $byTbl[0]['table'], '51');

foreach ([Report::OPEN_OK, Report::OPEN_SHORT, Report::OPEN_OVER,
          Report::OPEN_NONE, Report::OPEN_NOGUEST, Report::OPEN_SKIP] as $st) {
    ok("状态 {$st} 有中文标签", Report::openStateLabel($st) !== $st);
}

// =====================================================================
echo "\n【2e2】外带等免核对的台\n";
// =====================================================================

// ---- 桌号通配符匹配 ----
$pat = ['Llevar*', '外带*'];
ok('Llevar 命中',            Report::isNoComboTable('Llevar', $pat));
ok('大小写不敏感',            Report::isNoComboTable('LLEVAR', $pat));
ok('通配后缀命中',            Report::isNoComboTable('Llevar 2', $pat));
ok('带横线的也命中',          Report::isNoComboTable('llevar-03', $pat));
ok('中文外带命中',            Report::isNoComboTable('外带1', $pat));
ok('前后空格不影响',          Report::isNoComboTable('  Llevar  ', $pat));
ok('普通桌号不命中',          !Report::isNoComboTable('51', $pat));
ok('不做部分匹配（前缀要对上）', !Report::isNoComboTable('A-Llevar', $pat));
ok('空桌号不命中',            !Report::isNoComboTable('', $pat));
ok('空规则时谁都不命中',       !Report::isNoComboTable('Llevar', []));
ok('规则里的空串被忽略',       !Report::isNoComboTable('随便什么桌', ['', '   ']));
// 通配符必须是我们自己的语义，不能让正则元字符漏进去
ok('点号只当普通字符',        !Report::isNoComboTable('LlevarX', ['Llevar.']));
ok('单字通配 ? 生效',          Report::isNoComboTable('Llevar1', ['Llevar?']));
ok('单字通配只吃一个字符',    !Report::isNoComboTable('Llevar12', ['Llevar?']));

// ---- 规则的读取与默认值 ----
// config.php 是用户自己维护的（里面有数据库密码），升级程序时多半不会跟着换。
// 所以缺项必须套用代码里的默认值，否则功能会静默失效。
$oldCfg = ['host' => 'x', 'combo_item_ids' => [1890]];   // 老版本 config，没有这两项
eq('老 config 套用默认桌号规则',
   Report::skipRules($oldCfg)['tables'], Report::DEFAULT_NO_COMBO_TABLES);
ok('默认规则能盖住 Llevar',
   Report::isNoComboTable('Llevar', Report::skipRules($oldCfg)['tables']));
eq('老 config 的 eat_type 规则为空', Report::skipRules($oldCfg)['eat_types'], []);
// 明确写成 [] 是「不要跳过任何台」，不能被默认值覆盖
eq('显式留空则不套默认', Report::skipRules(['no_combo_tables' => []])['tables'], []);
eq('显式配置优先',
   Report::skipRules(['no_combo_tables' => ['Barra*']])['tables'], ['Barra*']);
eq('规则里的空白项被剔除',
   Report::skipRules(['no_combo_tables' => ['  Llevar*  ', '', '  ']])['tables'], ['Llevar*']);
eq('eat_types 转成整数',
   Report::skipRules(['no_combo_eat_types' => ['3', 5]])['eat_types'], [3, 5]);
// 仓库里的 config.php 本身也要能直接用
ok('随包的 config.php 带默认外带规则',
   Report::isNoComboTable('Llevar', Report::skipRules(require __DIR__ . '/../config.php')['tables']));

// ---- eat_type 规则 ----
ok('eat_type 命中即免核对', Report::skipsComboCheck('12', 3, ['eat_types' => [3]]));
ok('eat_type 不命中',       !Report::skipsComboCheck('12', 0, ['eat_types' => [3]]));
ok('eat_types 留空则不生效', !Report::skipsComboCheck('12', 3, ['eat_types' => []]));
ok('桌号与 eat_type 任一命中即可',
   Report::skipsComboCheck('Llevar', 0, ['tables' => ['Llevar*'], 'eat_types' => [3]]));
ok('两条规则都空时不跳过',   !Report::skipsComboCheck('Llevar', 3, []));

// ---- 接入核对结果 ----
$skipRules = ['tables' => ['Llevar*'], 'eat_types' => []];
$otSkip = Report::buildOpenTables($heads, $counts, 4, [], $skipRules);
$bySkip = [];
foreach ($otSkip['rows'] as $r) { $bySkip[$r['id']] = $r; }

eq('Llevar 判为免核对', $bySkip[5]['state'], Report::OPEN_SKIP);
ok('免核对的行带 skip 标记', $bySkip[5]['skip']);
ok('普通台不带 skip 标记', !$bySkip[1]['skip']);
eq('未加规则时 Llevar 是「未填人数」', $by[5]['state'], Report::OPEN_NOGUEST);
// 原本 4 个问题台（2/3/4/5），Llevar 免核对后只剩 3 个
eq('免核对的台不计入待处理', $otSkip['sum']['problem'], 3);
eq('免核对单独计数', $otSkip['sum']['skip'], 1);
eq('免核对的台仍列在明细里', count($otSkip['rows']), count($ot['rows']));
eq('免核对不影响开台数', $otSkip['sum']['tables'], $ot['sum']['tables']);
eq('免核对不影响金额合计', $otSkip['sum']['amount'], $ot['sum']['amount']);

// 免核对的台排在最后
$skipSorted = Report::sortOpenTables($otSkip['rows']);
eq('免核对的台排在最末', end($skipSorted)['state'], Report::OPEN_SKIP);

// 免核对的台不接受人工确认（就算会话里留着旧记录也不认）
$otSkipAck = Report::buildOpenTables($heads, $counts, 4,
    [5 => ['fp' => $bySkip[5]['fp'], 'at' => time()]], $skipRules);
$ackedSkip = null;
foreach ($otSkipAck['rows'] as $r) { if ($r['id'] === 5) { $ackedSkip = $r; } }
ok('免核对的台不显示为已确认', !$ackedSkip['acked']);
eq('免核对的台不计入已确认数', $otSkipAck['sum']['acked'], 0);

// eat_type 规则同样能生效（5 号单的 eat_type 是 3）
$otEat = Report::buildOpenTables($heads, $counts, 4, [], ['eat_types' => [3]]);
$byEat = [];
foreach ($otEat['rows'] as $r) { $byEat[$r['id']] = $r; }
eq('按 eat_type 也能判为免核对', $byEat[5]['state'], Report::OPEN_SKIP);
eq('按 eat_type 免核对后问题台同样减一', $otEat['sum']['problem'], 3);

// =====================================================================
echo "\n【2f2】开台核对的人工确认\n";
// =====================================================================

Ack::resetMemory();

// 指纹只认「人数 + 套餐份数」，其他字段变了不影响
$base = ['guests' => 4, 'combo' => 2.0, 'amount' => 47.8, 'dishes' => 8];
eq('指纹格式', Ack::fingerprint($base), '4:200');
eq('金额变化不影响指纹', Ack::fingerprint($base + []), Ack::fingerprint(array_merge($base, ['amount' => 99.9])));
eq('菜品数变化不影响指纹', Ack::fingerprint($base), Ack::fingerprint(array_merge($base, ['dishes' => 30])));
ok('人数变化会改变指纹', Ack::fingerprint($base) !== Ack::fingerprint(array_merge($base, ['guests' => 5])));
ok('套餐份数变化会改变指纹', Ack::fingerprint($base) !== Ack::fingerprint(array_merge($base, ['combo' => 3.0])));
eq('小数份数指纹稳定', Ack::fingerprint(['guests' => 2, 'combo' => 1.5]), '2:150');

// 存取
Ack::set(101, '4:200');
eq('存入后能取到', Ack::all()[101]['fp'] ?? null, '4:200');
Ack::clear(101);
eq('撤销后取不到', Ack::all()[101] ?? null, null);
Ack::set(101, '4:200');
Ack::set(102, '2:0');
eq('可存多台', count(Ack::all()), 2);
Ack::clearAll();
eq('清空全部', count(Ack::all()), 0);
Ack::set(0, 'x');
eq('非法订单号不存', count(Ack::all()), 0);

// ---- 与核对结果结合 ----
$h4 = [['order_head_id' => 7, 't0' => date('Y-m-d H:i:s', time() - 600), 'guests' => 8,
        'table_name' => '并桌A', 'employee' => 'Jefe', 'amount' => 20.0, 'checks' => 1,
        'eat_type' => 0, 'status' => 0, 'settled' => 0]];
$c4 = [['order_head_id' => 7, 'combo_qty' => 0, 'dish_qty' => 3, 'lines_cnt' => 3]];

$noAck = Report::buildOpenTables($h4, $c4, 4);
eq('未确认时是「未打套餐」', $noAck['rows'][0]['state'], Report::OPEN_NONE);
eq('未确认时计入待处理', $noAck['sum']['problem'], 1);
eq('未确认时 acked 为假', $noAck['rows'][0]['acked'], false);
$fp = $noAck['rows'][0]['fp'];
eq('行里带出的指纹与 Ack 算的一致', $fp, Ack::fingerprint(['guests' => 8, 'combo' => 0]));

$acks = [7 => ['fp' => $fp, 'at' => time()]];
$withAck = Report::buildOpenTables($h4, $c4, 4, $acks);
ok('确认后标记为已确认', $withAck['rows'][0]['acked']);
eq('确认后不再计入待处理', $withAck['sum']['problem'], 0);
eq('确认后单独计数', $withAck['sum']['acked'], 1);
eq('确认后原始状态仍保留', $withAck['rows'][0]['state'], Report::OPEN_NONE);
ok('确认时间被带出', $withAck['rows'][0]['acked_at'] > 0);

// 人数变了 → 确认自动作废
$h5 = $h4; $h5[0]['guests'] = 10;
$changed = Report::buildOpenTables($h5, $c4, 4, $acks);
ok('人数变化后确认作废', !$changed['rows'][0]['acked']);
eq('作废后重新计入待处理', $changed['sum']['problem'], 1);

// 补打了套餐 → 确认也作废（而且状态本身也变了）
$c5 = [['order_head_id' => 7, 'combo_qty' => 8, 'dish_qty' => 11, 'lines_cnt' => 11]];
$fixed = Report::buildOpenTables($h4, $c5, 4, $acks);
ok('补打套餐后确认作废', !$fixed['rows'][0]['acked']);
eq('补打套餐后状态变为一致', $fixed['rows'][0]['state'], Report::OPEN_OK);

// 指纹对不上的陈旧确认不生效
$stale = Report::buildOpenTables($h4, $c4, 4, [7 => ['fp' => '999:999', 'at' => time()]]);
ok('指纹不匹配的确认不生效', !$stale['rows'][0]['acked']);

// 只是金额/菜品变了，确认应当保持
$c6 = [['order_head_id' => 7, 'combo_qty' => 0, 'dish_qty' => 30, 'lines_cnt' => 25]];
$h6 = $h4; $h6[0]['amount'] = 300.0;
$keep = Report::buildOpenTables($h6, $c6, 4, $acks);
ok('只是又点了菜，确认仍然有效', $keep['rows'][0]['acked']);

// 排序：待处理 > 已确认 > 正常
$hs = [
    ['order_head_id' => 1, 't0' => '2026-08-25 19:00:00', 'guests' => 2, 'table_name' => 'A',
     'employee' => '', 'amount' => 0, 'checks' => 1, 'eat_type' => 0, 'status' => 0, 'settled' => 0],
    ['order_head_id' => 2, 't0' => '2026-08-25 19:01:00', 'guests' => 4, 'table_name' => 'B',
     'employee' => '', 'amount' => 0, 'checks' => 1, 'eat_type' => 0, 'status' => 0, 'settled' => 0],
    ['order_head_id' => 3, 't0' => '2026-08-25 19:02:00', 'guests' => 3, 'table_name' => 'C',
     'employee' => '', 'amount' => 0, 'checks' => 1, 'eat_type' => 0, 'status' => 0, 'settled' => 0],
];
$cs = [
    ['order_head_id' => 1, 'combo_qty' => 2, 'dish_qty' => 2, 'lines_cnt' => 2],  // 一致
    ['order_head_id' => 2, 'combo_qty' => 0, 'dish_qty' => 1, 'lines_cnt' => 1],  // 未打套餐
    ['order_head_id' => 3, 'combo_qty' => 0, 'dish_qty' => 1, 'lines_cnt' => 1],  // 未打，但已确认
];
$mixed = Report::buildOpenTables($hs, $cs, 4, [3 => ['fp' => '3:0', 'at' => time()]]);
$sorted2 = Report::sortOpenTables($mixed['rows']);
eq('排序：待处理的问题台在最前', $sorted2[0]['table'], 'B');
eq('排序：已确认的排中间', $sorted2[1]['table'], 'C');
eq('排序：一致的排最后', $sorted2[2]['table'], 'A');
eq('混合场景待处理计数', $mixed['sum']['problem'], 1);
eq('混合场景已确认计数', $mixed['sum']['acked'], 1);

// ---- 组内按桌号自然排序 ----
$mkHead = static fn($id, $tbl, $g) => [
    'order_head_id' => $id, 't0' => '2026-08-25 19:0' . ($id % 10) . ':00',
    'guests' => $g, 'table_name' => $tbl, 'employee' => '', 'amount' => 0,
    'checks' => 1, 'eat_type' => 0, 'status' => 0, 'settled' => 0,
];
$mkCnt = static fn($id, $c) => ['order_head_id' => $id, 'combo_qty' => $c,
                                'dish_qty' => 3, 'lines_cnt' => 3];
// 4 张正常台（桌号 10 / 2 / 9 / A10 / A2 / Llevar）+ 2 张问题台（51 / 7）
$sortHeads = [$mkHead(1, '10', 2), $mkHead(2, '2', 2), $mkHead(3, '9', 2),
              $mkHead(4, '51', 4), $mkHead(5, '7', 4),
              $mkHead(6, 'Llevar', 2), $mkHead(7, 'A2', 2), $mkHead(8, 'A10', 2)];
$sortCnts = [$mkCnt(1, 2), $mkCnt(2, 2), $mkCnt(3, 2),
             $mkCnt(4, 0), $mkCnt(5, 0),
             $mkCnt(6, 2), $mkCnt(7, 2), $mkCnt(8, 2)];
$sortRes = Report::sortOpenTables(
    Report::buildOpenTables($sortHeads, $sortCnts, 4)['rows']);
$order = array_column($sortRes, 'table');

eq('问题台排最前且按桌号排', array_slice($order, 0, 2), ['7', '51']);
eq('正常台按桌号自然排序', array_slice($order, 2), ['2', '9', '10', 'A2', 'A10', 'Llevar']);
ok('数字桌号 10 排在 9 之后（不是字符串序）',
   array_search('10', $order, true) > array_search('9', $order, true));
ok('数字桌号 2 排在 10 之前',
   array_search('2', $order, true) < array_search('10', $order, true));
ok('带字母的桌号 A2 排在 A10 之前',
   array_search('A2', $order, true) < array_search('A10', $order, true));
ok('纯数字桌号排在文字桌号之前',
   array_search('51', $order, true) < array_search('Llevar', $order, true));

// 关闭「问题优先」后应当整体按桌号排
$byTable = array_column(
    Report::sortOpenTables(Report::buildOpenTables($sortHeads, $sortCnts, 4)['rows'], false),
    'table');
eq('不分组时全部按桌号排', $byTable, ['2', '7', '9', '10', '51', 'A2', 'A10', 'Llevar']);

// 已确认的台也要按桌号排（在问题台之后、正常台之前）
$ackedAll = [4 => ['fp' => '4:0', 'at' => time()], 5 => ['fp' => '4:0', 'at' => time()]];
$ackOrder = array_column(Report::sortOpenTables(
    Report::buildOpenTables($sortHeads, $sortCnts, 4, $ackedAll)['rows']), 'table');
eq('已确认的台按桌号排在最前那一档之后', array_slice($ackOrder, 0, 2), ['7', '51']);

Ack::resetMemory();

// ---- 页面：必须走 POST + CSRF + 二次确认 ----
$openSrc = (string) file_get_contents(__DIR__ . '/../open.php');
ok('确认走 POST 而不是链接', strpos($openSrc, '<form method="post" class="ackform">') !== false);
ok('确认表单带 CSRF', strpos($openSrc, "name=\"csrf\" value=\"' . h(Auth::csrfToken())") !== false);
ok('服务端校验 CSRF', strpos($openSrc, 'Auth::csrfValid($_POST[') !== false);
ok('有二次确认（ask 参数）', strpos($openSrc, '$askId === $r[\'id\']') !== false);
ok('二次确认后才真正提交', strpos($openSrc, 'class="btn-mini yes"') !== false
   && strpos($openSrc, 'class="btn-mini no"') !== false);
ok('提交后跳转，避免刷新重复提交', strpos($openSrc, "header('Location: ' . \$selfUrl())") !== false);
ok('确认前比对指纹，数据变了就拒绝',
   strpos($openSrc, "\$_POST['fp']") !== false && strpos($openSrc, '请重新核对后再确认') !== false);
ok('已确认的台不算「有问题」', strpos($openSrc, "&& !\$r['acked']") !== false);
ok('确认状态不写数据库',
   strpos((string) file_get_contents(__DIR__ . '/../lib/ack.php'), 'Db::select') === false);

// ---- 页面：免核对规则来自 config，且不参与「只看有问题的台」----
ok('免核对规则从 config 读取（缺项套默认）',
   strpos($openSrc, 'Report::skipRules($cfg)') !== false);
ok('页面会提示当前用的是默认值还是 config 里的配置',
   strpos($openSrc, '$skipCustom') !== false);
ok('免核对规则传给了核对函数',
   strpos($openSrc, 'Report::buildOpenTables($heads, $counts, $warnHours, Ack::all(), $skipRules)') !== false);
ok('「只看有问题」会滤掉免核对的台',
   strpos($openSrc, "\$r['state'] !== Report::OPEN_SKIP") !== false);
ok('免核对的台不给确认按钮',
   strpos($openSrc, "\$r['state'] === Report::OPEN_SKIP") !== false);
ok('服务端拒绝确认免核对的台', strpos($openSrc, '本来就免核对') !== false);
ok('页面上列出了当前生效的免核对规则', strpos($openSrc, '当前的判定规则') !== false);

// =====================================================================
echo "\n【2e】登录\n";
// =====================================================================

ok('Auth 类存在', class_exists('Auth'));
ok('config 里有 password 字段', array_key_exists('password', Db::config()));

// 明文密码
$refl = new ReflectionClass('Db');
$prop = $refl->getProperty('cfg');
$prop->setAccessible(true);
$orig = $prop->getValue();

$prop->setValue(null, array_merge($orig, ['password' => 'plain-secret']));
ok('明文密码：正确密码通过', Auth::verify('plain-secret'));
ok('明文密码：错误密码拒绝', !Auth::verify('plain-secre'));
ok('明文密码：空密码拒绝', !Auth::verify(''));
ok('明文密码：已配置', Auth::isConfigured());

// bcrypt 哈希密码
$hash = password_hash('hashed-secret', PASSWORD_DEFAULT);
$prop->setValue(null, array_merge($orig, ['password' => $hash]));
ok('哈希密码：正确密码通过', Auth::verify('hashed-secret'));
ok('哈希密码：错误密码拒绝', !Auth::verify('hashed-secre'));
ok('哈希密码：不会把哈希本身当密码', !Auth::verify($hash));

// 未配置
$prop->setValue(null, array_merge($orig, ['password' => '']));
ok('未设置密码时拒绝一切登录', !Auth::verify('') && !Auth::verify('随便'));
ok('未设置密码时 isConfigured 为假', !Auth::isConfigured());
$prop->setValue(null, array_merge($orig, ['password' => '在这里设置登录密码']));
ok('占位符不算已配置', !Auth::isConfigured());

$prop->setValue(null, $orig);   // 还原

// 所有对外页面都必须挂上登录保护
foreach (['index.php', 'dish.php', 'station.php'] as $page) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $page);
    ok("{$page} 有登录保护", strpos($src, 'Auth::requireLogin()') !== false);
    ok("{$page} 登录检查在业务逻辑之前",
       strpos($src, 'Auth::requireLogin()') < strpos($src, 'Biz::'));
}
$src = (string) file_get_contents(__DIR__ . '/checkdb.php');
ok('checkdb.php 有登录保护（命令行除外）', strpos($src, 'Auth::isLoggedIn()') !== false);
ok('checkdb.php 登录跳转在任何输出之前',
   strpos($src, "Location: ../login.php") < strpos($src, "echo '<pre"));

// =====================================================================
echo "\n【2g】自适应布局\n";
// 浏览器层面的验证（横向溢出、点击目标、字号）需要真实渲染，
// 这里只静态校验关键规则和标记有没有掉，防止改样式时误删。
// =====================================================================

$css = (string) file_get_contents(__DIR__ . '/../assets/app.css');

foreach (['max-width:900px' => '平板断点',
          'max-width:640px' => '手机断点',
          'pointer:coarse'  => '触屏设备适配'] as $q => $desc) {
    ok("样式含{$desc}（{$q}）", strpos(str_replace(' ', '', $css), $q) !== false);
}
ok('手机上表单竖排', strpos($css, 'flex-direction:column') !== false);
ok('输入框字号 ≥16px（否则 iOS 聚焦时会放大页面）',
   preg_match('/input\[type=date\][^{]*\{[^}]*font-size:16px/s', $css) === 1);
ok('表格容器可横向滚动', strpos($css, 'overflow-x:auto') !== false);
ok('有吸附首列规则', strpos($css, 'table.grid.stick') !== false);
ok('有手机紧凑列表规则', strpos($css, '.openlist') !== false);
ok('紧凑列表默认隐藏（只在手机显示）', strpos($css, '.openlist{display:none') !== false);
ok('手机上隐藏次要列', strpos($css, '.hide-sm{display:none}') !== false);
ok('免核对状态有样式', strpos($css, '.state.s-skip') !== false);
ok('免核对的行被压暗', strpos($css, 'li.row-skip') !== false
   && strpos($css, 'tr.row-skip td') !== false);
ok('登录页适配小屏高度', strpos($css, '100dvh') !== false);

// 两套页面模板都要有 viewport，否则手机上会按桌面宽度缩放
foreach (['../login.php' => '登录页', '../lib/view.php' => '主页面'] as $f => $desc) {
    $src = (string) file_get_contents(__DIR__ . '/' . $f);
    ok("{$desc}有 viewport 声明",
       strpos($src, 'name="viewport"') !== false
       && strpos($src, 'width=device-width') !== false);
    ok("{$desc}有 theme-color", strpos($src, 'name="theme-color"') !== false);
}

// 导航在手机上换短名，靠 CSS 切换，不依赖 JS
$view = (string) file_get_contents(__DIR__ . '/../lib/view.php');
ok('导航项带长短两种标题', strpos($view, 'class="lg"') !== false && strpos($view, 'class="sm"') !== false);
ok('导航长短标题由 CSS 切换',
   strpos($css, '.tabs .sm{display:none}') !== false && strpos($css, '.tabs .sm{display:inline}') !== false);

// 开台核对是手机上最常用的页：手机走紧凑列表，桌面走完整表格，两者二选一
$open = (string) file_get_contents(__DIR__ . '/../open.php');
ok('开台核对有手机紧凑列表', strpos($open, '<ul class="openlist">') !== false);
ok('开台核对有桌面完整表格', strpos($open, 'class="tablewrap opentable"') !== false);
ok('手机上显示列表并隐藏表格',
   strpos($css, '.openlist{display:block}') !== false && strpos($css, '.opentable{display:none}') !== false);
// 两个视图共用同一个格式化函数，避免口径跑偏
ok('两个视图共用格式化函数', substr_count($open, '$fmt($r)') === 2 && strpos($open, '$fmt = static function') !== false);
foreach (['l1', 'l2', 'l3'] as $line) {
    ok("紧凑列表有 {$line} 行", strpos($open, 'class="' . $line . '"') !== false);
}

// 各页表格都要能在窄屏下横向滚动并保留首列
foreach (['../index.php', '../dish.php', '../station.php', '../open.php'] as $f) {
    $src  = (string) file_get_contents(__DIR__ . '/' . $f);
    $name = basename($f);
    $tables = preg_match_all('/<table class="grid[^"]*"/', $src);
    $inWrap = preg_match_all('/<div class="tablewrap[^"]*">\s*\n?\s*<table/', $src);
    ok("{$name} 的表格都放在滚动容器里（{$inWrap}/{$tables}）", $tables > 0 && $inWrap === $tables);
}

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

// ---- 按岗位筛选 ----
$onlyHot = Report::filterByStation($b['items'], '11');
eq('筛选热菜岗位后的菜品数', count($onlyHot), 2);
ok('筛选结果只含该岗位', !array_filter($onlyHot, fn($i) => $i['pc'] !== 11));
$onlyBeb = Report::filterByStation($b['items'], '6');
eq('筛选 bebidas 岗位后的菜品数', count($onlyBeb), 1);
eq('筛选后再排行取该岗位第一', Report::rank($onlyBeb, 'total', 'desc', 10)[0]['name'], 'Agua');
eq('筛选不存在的岗位返回空', count(Report::filterByStation($b['items'], '999')), 0);

// 未分配岗位的菜品要能单独筛出来
$menu2 = $menu + [777 => ['name' => '没岗位的菜', 'name2' => '', 'print_class' => null, 'is_condiment' => false]];
$b2 = Report::buildDishes($menu2, $pcs, array_merge($dishRows, [
    ['menu_item_id' => 777, 'item_name' => '没岗位的菜', 'seg' => 'day', 'qty' => 3, 'times' => 3, 'amount' => 0],
]));
$none = Report::filterByStation($b2['items'], 'none');
eq('未分配岗位可单独筛选', count($none), 1);
eq('未分配岗位菜品正确', array_values($none)[0]['name'], '没岗位的菜');
ok('未分配岗位不会混入具体岗位', count(Report::filterByStation($b2['items'], '11')) === 2);

// ---- 岗位汇总 ----
$sum = Report::stationSummary($b['items'], 'total');
eq('岗位汇总组数', count($sum), 2);
eq('汇总按点单量降序', $sum[0]['pc_name'], '热菜');
eq('热菜岗位点单量 = 35 + 40', $sum[0]['qty'], 75.0);
eq('热菜岗位菜品数', $sum[0]['items'], 2);
eq('bebidas 点单量', $sum[1]['qty'], 5.0);
ok('汇总总量 == 全店总量',
   abs(array_sum(array_column($sum, 'qty')) - $b['grand']['total']['qty']) < 0.001);
eq('白天时段汇总只含白天有量的岗位', count(Report::stationSummary($b['items'], 'day')), 1);

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
