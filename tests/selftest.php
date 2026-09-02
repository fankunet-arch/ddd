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
echo "\n【1b】只读防线：绕过尝试\n";
// 这一组是体检时补的。原来的实现有两个真实缺口：
//   1. 'INTO OUTFILE' 当普通关键字匹配，只认中间恰好一个空格 ——
//      INTO␣␣OUTFILE、INTO\nOUTFILE 全都能绕过去，而这是清单里唯一
//      真能往磁盘写文件的一条。
//   2. \bLOAD\b 匹配不到 LOAD_FILE（下划线是单词字符）。
// =====================================================================

foreach ([
    // 空白变形：多个空格、换行、制表符
    "SELECT * FROM t INTO  OUTFILE '/tmp/x'",
    "SELECT * FROM t INTO\nOUTFILE '/tmp/x'",
    "SELECT * FROM t INTO\tOUTFILE '/tmp/x'",
    "SELECT * FROM t INTO   DUMPFILE '/tmp/x'",
    "SELECT * FROM t INTO\n\t DUMPFILE '/tmp/x'",
    // 读服务器文件 / 写用户变量
    "SELECT LOAD_FILE('/etc/passwd')",
    "SELECT 1 INTO @a",
    "SELECT 1 INTO   @a",
    // 拖住连接（不写数据，但能把库拖垮）
    "SELECT SLEEP(10)",
    "SELECT BENCHMARK(100000000,MD5('a'))",
    "SELECT GET_LOCK('x',100)",
    "SELECT RELEASE_LOCK('x')",
    "SELECT * FROM t PROCEDURE ANALYSE()",
    // 大小写与前导空白
    "select * from t into outfile '/tmp/x'",
    "\n\t SELECT 1 INTO OUTFILE '/x'",
] as $bad) {
    throws('拦截: ' . substr(str_replace(["\n", "\t"], ' ', $bad), 0, 44),
           static fn() => Db::assertReadOnly($bad));
}

// 正常语句不能被这批新规则误伤
foreach ([
    "SELECT a INTO_SOMETHING FROM t",   // 列名里含 INTO 不该中招
    "SELECT sleepy_col FROM t",         // 列名以 sleep 开头不该中招
    "SELECT time_load FROM t",          // 含 load 的列名不该中招
] as $good) {
    ok('不误伤: ' . substr($good, 0, 44), (static function () use ($good) {
        try { Db::assertReadOnly($good); return true; } catch (Throwable $e) { return false; }
    })());
}

// 关键字出现在字符串常量里也会被拒 —— 这是【故意】保守：
// 本程序的 SQL 全部由模板生成，任何用户输入都走参数绑定，永远不会把
// 关键字写进字符串常量。宁可误杀（查询被拒，看得见）也不放过（写操作溜过去）。
throws('字符串常量里的关键字也照拒（故意从严，失败即拒绝）',
       static fn() => Db::assertReadOnly("SELECT * FROM t WHERE a = 'INTO OUTFILE'"));

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
// 这一段专测套餐口径，酒水核对先关掉（min_drink = 0），下面【2e3】单独测
$NODRINK = ['min_drink' => 0];
$ot = Report::buildOpenTables($heads, $counts, 4, [], [], $NODRINK);
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
$ot2 = Report::buildOpenTables([$heads[0]], [], 4, [], [], $NODRINK);
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
// 所以功能默认值放在随程序更新的 lib/settings.php 里，缺项时必须能兜住。
$settings = require __DIR__ . '/../lib/settings.php';
$oldCfg = ['host' => 'x', 'combo_item_ids' => [1890]];   // 老版本 config，没有这两项
eq('老 config 套用 settings.php 的桌号规则',
   Report::skipRules($oldCfg)['tables'], $settings['no_combo_tables']);
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
// 默认值只有 settings.php 一个出处，config.php 里不该再抄一份
ok('settings.php 的默认规则覆盖 Llevar',
   Report::isNoComboTable('Llevar', Report::skipRules($settings)['tables']));
$shipped = require __DIR__ . '/../config.php';
ok('随包的 config.php 不重复写功能参数',
   !array_key_exists('no_combo_tables', $shipped)
   && !array_key_exists('combo_item_ids', $shipped)
   && !array_key_exists('day_start', $shipped));

// ---- 两层配置：settings.php 默认值 + config.php 覆盖 ----
// 这是外带免核对翻车后加的防线：功能参数不能只存在于 config.php 里，
// 否则站点沿用旧 config 时新功能读不到值，会静默失效。
$merged = Db::config();
foreach (['day_start', 'day_end', 'night_start', 'night_end', 'day_cut_hour',
          'max_range_days', 'combo_item_ids', 'no_combo_tables', 'no_combo_eat_types',
          'open_table_warn_hours', 'ack_hours', 'driver'] as $k) {
    ok("功能参数 {$k} 有默认值", array_key_exists($k, $settings));
    ok("生效配置里能读到 {$k}", array_key_exists($k, $merged));
}
foreach (['host', 'port', 'dbname', 'user', 'pass', 'password'] as $k) {
    ok("连接/密码项 {$k} 在 config.php 里", array_key_exists($k, $shipped));
    ok("连接/密码项 {$k} 不在 settings.php 里", !array_key_exists($k, $settings));
}
eq('config.php 没写的键用 settings.php 的值',
   $merged['combo_item_ids'], $settings['combo_item_ids']);
eq('config.php 写了的键优先', $merged['host'], $shipped['host']);
eq('Db::overrides() 只给出 config.php 里的键',
   array_keys(Db::overrides()), array_keys($shipped));

// ---- eat_type 规则 ----
ok('eat_type 命中即免核对', Report::skipsComboCheck('12', 3, ['eat_types' => [3]]));
ok('eat_type 不命中',       !Report::skipsComboCheck('12', 0, ['eat_types' => [3]]));
ok('eat_types 留空则不生效', !Report::skipsComboCheck('12', 3, ['eat_types' => []]));
ok('桌号与 eat_type 任一命中即可',
   Report::skipsComboCheck('Llevar', 0, ['tables' => ['Llevar*'], 'eat_types' => [3]]));
ok('两条规则都空时不跳过',   !Report::skipsComboCheck('Llevar', 3, []));

// ---- 接入核对结果 ----
$skipRules = ['tables' => ['Llevar*'], 'eat_types' => []];
$otSkip = Report::buildOpenTables($heads, $counts, 4, [], $skipRules, $NODRINK);
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
    [5 => ['fp' => $bySkip[5]['fp'], 'at' => time()]], $skipRules, $NODRINK);
$ackedSkip = null;
foreach ($otSkipAck['rows'] as $r) { if ($r['id'] === 5) { $ackedSkip = $r; } }
ok('免核对的台不显示为已确认', !$ackedSkip['acked']);
eq('免核对的台不计入已确认数', $otSkipAck['sum']['acked'], 0);

// eat_type 规则同样能生效（5 号单的 eat_type 是 3）
$otEat = Report::buildOpenTables($heads, $counts, 4, [], ['eat_types' => [3]], $NODRINK);
$byEat = [];
foreach ($otEat['rows'] as $r) { $byEat[$r['id']] = $r; }
eq('按 eat_type 也能判为免核对', $byEat[5]['state'], Report::OPEN_SKIP);
eq('按 eat_type 免核对后问题台同样减一', $otEat['sum']['problem'], 3);

// =====================================================================
echo "\n【2e2b】期间对比\n";
// =====================================================================

// ---- 区间推算：用户的原话是「今天周三，就对比上周四到今天 与 上上周四到上周三」----
[$c1, $c2] = Biz::lastDays(7, '2026-09-02');          // 2026-09-02 是周三
eq('近 7 天起点是上周四', $c1 . ' ' . Report::dow($c1), '2026-08-27 周四');
eq('近 7 天终点是今天（周三）', $c2 . ' ' . Report::dow($c2), '2026-09-02 周三');
eq('近 7 天正好 7 天', Biz::rangeDays($c1, $c2), 7);

[$p1, $p2] = Biz::prevRange($c1, $c2);
eq('上期起点是上上周四', $p1 . ' ' . Report::dow($p1), '2026-08-20 周四');
eq('上期终点是上周三', $p2 . ' ' . Report::dow($p2), '2026-08-26 周三');
eq('上期也是 7 天', Biz::rangeDays($p1, $p2), 7);
ok('两期首尾相接不重叠', strtotime($p2) + 86400 === strtotime($c1));
ok('等长时星期几自动对齐',
   Report::dow($c1) === Report::dow($p1) && Report::dow($c2) === Report::dow($p2));

// 其他长度
eq('近 1 天就是今天', Biz::lastDays(1, '2026-09-02'), ['2026-09-02', '2026-09-02']);
eq('近 30 天起点', Biz::lastDays(30, '2026-09-02')[0], '2026-08-04');
eq('0 天按 1 天处理', Biz::lastDays(0, '2026-09-02'), ['2026-09-02', '2026-09-02']);
// 跨月、跨年、闰年
eq('上期跨月正确', Biz::prevRange('2026-03-01', '2026-03-07'), ['2026-02-22', '2026-02-28']);
eq('上期跨年正确', Biz::prevRange('2026-01-01', '2026-01-07'), ['2025-12-25', '2025-12-31']);
eq('闰年 2 月正确', Biz::prevRange('2024-03-01', '2024-03-01'), ['2024-02-29', '2024-02-29']);
eq('整月对比上一个月', Biz::prevRange('2026-08-01', '2026-08-31'), ['2026-07-01', '2026-07-31']);
eq('日期列表长度', count(Biz::dateList('2026-08-27', '2026-09-02')), 7);
eq('日期列表跨月正确', Biz::dateList('2026-08-31', '2026-09-02'),
   ['2026-08-31', '2026-09-01', '2026-09-02']);

// ---- 涨跌 ----
eq('涨跌：100 → 125', Report::delta(125, 100), [25.0, 0.25]);
eq('涨跌：100 → 75',  Report::delta(75, 100),  [-25.0, -0.25]);
eq('涨跌：持平',       Report::delta(100, 100), [0.0, 0.0]);
eq('上期为 0 时不算百分比（不能除以 0）', Report::delta(50, 0), [50.0, null]);
eq('两期都是 0',       Report::delta(0, 0),     [0.0, null]);
// 上期为负（退款多于收入）时用绝对值做分母，否则涨跌方向会反
eq('上期为负时方向不反', Report::delta(-50, -100), [50.0, 0.5]);

// ---- 营业额对比 ----
$mkDay = static fn($d, $seg, $amt, $g, $ck) => ['biz_date' => $d, 'seg' => $seg,
    'checks' => $ck, 'guests' => $g, 'actual' => $amt, 'original' => $amt,
    'discount' => 0, 'service' => 0, 'tax' => 0, 'should_amt' => $amt, 'ret' => 0];
$curP  = Report::pivotSales([$mkDay('2026-08-27', 'day', 100, 4, 2),
                             $mkDay('2026-08-28', 'night', 200, 6, 3)]);
$prevP = Report::pivotSales([$mkDay('2026-08-20', 'day', 80, 4, 2),
                             $mkDay('2026-08-21', 'night', 160, 4, 2)]);
$cs = Report::compareSales($curP, $prevP);
eq('全天营业额：本期', $cs['total']['actual']['cur'], 300.0);
eq('全天营业额：上期', $cs['total']['actual']['prev'], 240.0);
eq('全天营业额：涨跌额', $cs['total']['actual']['diff'], 60.0);
eq('全天营业额：涨跌率 25%', round($cs['total']['actual']['rate'] * 100, 1), 25.0);
eq('白天单独对比', $cs['day']['actual']['diff'], 20.0);
eq('晚上单独对比', $cs['night']['actual']['diff'], 40.0);
eq('人数对比', $cs['total']['guests']['diff'], 2.0);
// 人均要各期各自算完再比，不能拿差额相除
eq('本期人均 300/10', round($cs['total']['per_guest']['cur'], 2), 30.0);
eq('上期人均 240/8',  round($cs['total']['per_guest']['prev'], 2), 30.0);
eq('人均持平（总额涨了但人也多了）', round($cs['total']['per_guest']['diff'], 6), 0.0);

// 上期完全没数据时不能崩
$empty = Report::compareSales($curP, Report::pivotSales([]));
eq('上期无数据：本期照常', $empty['total']['actual']['cur'], 300.0);
eq('上期无数据：涨跌率为 null', $empty['total']['actual']['rate'], null);
eq('两期都无数据', Report::compareSales(Report::pivotSales([]), Report::pivotSales([]))
   ['total']['actual']['cur'], 0.0);

// ---- 逐日对照 ----
$cd = Biz::dateList('2026-08-27', '2026-09-02');
$pd = Biz::dateList('2026-08-20', '2026-08-26');
$rows = Report::compareDaily($cd, $pd, $curP['days'], $prevP['days'], 'total');
eq('逐日对照 7 行', count($rows), 7);
ok('每一行的星期几都对齐', (static function () use ($rows) {
    foreach ($rows as $r) { if (!$r['same_dow']) return false; }
    return true; })());
eq('第 1 行本期是周四', $rows[0]['cur_dow'], '周四');
eq('第 1 行上期也是周四', $rows[0]['prev_dow'], '周四');
eq('第 1 行本期金额', $rows[0]['cur_amt'], 100.0);
eq('第 1 行上期金额', $rows[0]['prev_amt'], 80.0);
eq('第 1 行涨跌', $rows[0]['amt_diff'], 20.0);
ok('全部成对', (static function () use ($rows) {
    foreach ($rows as $r) { if (!$r['paired']) return false; }
    return true; })());

// 不等长：多出来的天单独列出，不硬凑
$long = Report::compareDaily(Biz::dateList('2026-08-27', '2026-09-02'),
                             Biz::dateList('2026-08-24', '2026-08-26'),
                             $curP['days'], $prevP['days'], 'total');
eq('不等长时按较长的一边列出', count($long), 7);
ok('前 3 行成对', $long[0]['paired'] && $long[2]['paired']);
ok('第 4 行起无对应', !$long[3]['paired']);
eq('无对应时涨跌为 null', $long[3]['amt_diff'], null);
eq('无对应时上期日期为 null', $long[3]['prev_date'], null);
ok('不等长时星期几标记为不一致', !$long[0]['same_dow'] || !$long[1]['same_dow']);

// ---- 菜品／岗位对比 ----
$curItems  = [1 => ['name' => 'Agua', 'pc_name' => 'bebidas', 'qty' => 9, 'amount' => 22.5],
              2 => ['name' => 'Ramen', 'pc_name' => '热菜', 'qty' => 4, 'amount' => 36.0],
              3 => ['name' => '新菜', 'pc_name' => '热菜', 'qty' => 5, 'amount' => 50.0]];
$prevItems = [1 => ['name' => 'Agua', 'pc_name' => 'bebidas', 'qty' => 5, 'amount' => 12.5],
              2 => ['name' => 'Ramen', 'pc_name' => '热菜', 'qty' => 6, 'amount' => 54.0],
              4 => ['name' => '下架菜', 'pc_name' => '热菜', 'qty' => 3, 'amount' => 30.0]];
$ci = Report::compareItems($curItems, $prevItems, 'qty');
eq('涨得最多的排最前', $ci[0]['name'], '新菜');
eq('新菜上期为 0', $ci[0]['prev'], 0.0);
eq('新菜涨跌率为 null（上期为 0）', $ci[0]['rate'], null);
eq('跌得最多的排最后', end($ci)['name'], '下架菜');
eq('本期已下架的菜名从上期取', end($ci)['cur'], 0.0);
ok('下架菜名字不为空', end($ci)['name'] === '下架菜');
eq('对比行数（4 个菜都有变化）', count($ci), 4);
eq('金额一并带出', $ci[0]['cur_amt'], 50.0);
// 两期都没动静的不占版面
eq('两期都为 0 的不列出',
   count(Report::compareItems([9 => ['name' => 'X', 'qty' => 0]], [9 => ['name' => 'X', 'qty' => 0]])), 0);

// 压平函数
$flat = Report::flattenDishes(Report::buildDishes(
    [431 => ['name' => 'Agua', 'print_class' => 6, 'is_condiment' => false, 'price' => 2.5]],
    [6 => 'bebidas'],
    [['menu_item_id' => 431, 'item_name' => 'Agua', 'seg' => 'day',
      'qty' => 9, 'times' => 5, 'amount' => 22.5]])['items'], 'total');
eq('压平后带菜名', $flat[431]['name'], 'Agua');
eq('压平后带岗位', $flat[431]['pc_name'], 'bebidas');
eq('压平后带份数', $flat[431]['qty'], 9.0);

$flatS = Report::flattenStations(Report::buildStations([6 => 'bebidas'],
    [['pc' => 6, 'seg' => 'day', 'orders' => 12, 'items' => 2, 'qty' => 30,
      'lines_cnt' => 25, 'amount' => 60.0]])['stations'], 'total');
eq('岗位压平后比的是单量', $flatS[6]['qty'], 12);
eq('岗位压平后带金额', $flatS[6]['amount'], 60.0);

// ---- 快捷天数必须压过表单带上来的 start/end ----
// 踩过的坑：原来写的是「preset 非空【且】没传 start 才按天数算」，可日期框本来
// 就会跟着表单一起提交 —— 用户把下拉从 7 天切到 30 天时浏览器同时带上了旧的
// start/end，于是永远走 else 分支，切了等于没切。
$cmpSrc = (string) file_get_contents(__DIR__ . '/../compare.php');
ok('快捷天数优先于表单里的日期', strpos($cmpSrc, "if (\$preset !== '') {") !== false);
ok('没有「且没传 start」这种会失效的判断',
   strpos($cmpSrc, "!isset(\$_GET['start'])") === false);
ok('改日期会自动切回自选', strpos($cmpSrc, "p.value=") !== false);

// ---- 日期解析不了时不能抛异常 ----
// prevRange 在页面上是【先于】validateRange 调用的，strtotime 失败返回 false，
// 在 strict_types 下传给 date() 会抛 TypeError —— 抛出去就是白屏。
foreach ([['abc', 'xyz'], ['', ''], ['2026-13-45', '2026-99-99'], ['x', '2026-09-02']] as [$a, $b]) {
    ok("垃圾日期 '{$a}'~'{$b}' 不抛异常", (static function () use ($a, $b) {
        try { Biz::prevRange($a, $b); Biz::dateList($a, $b); Biz::lastDays(7, $a); return true; }
        catch (Throwable $e) { return false; }
    })());
}
eq('垃圾日期时原样返回，交给校验去报错', Biz::prevRange('abc', 'xyz'), ['abc', 'xyz']);
eq('垃圾日期的日期列表为空', Biz::dateList('abc', 'xyz'), []);
ok('垃圾日期会被 validateRange 拦下', Biz::validateRange('abc', 'xyz') !== null);
// 合法但不存在的日期（2 月 30 日）PHP 会自动归一，不应报错
ok('2026-02-30 被归一而不是崩溃', Biz::prevRange('2026-02-30', '2026-02-30') !== ['2026-02-30', '2026-02-30']);

// ---- 涨跌的颜色与无障碍 ----
$cssSrc = (string) file_get_contents(__DIR__ . '/../assets/app.css');
ok('涨跌不只靠颜色：输出了箭头',
   strpos($cmpSrc, '▲') !== false && strpos($cmpSrc, '▼') !== false);
ok('涨跌带正负号', strpos($cmpSrc, "'+' : '−'") !== false);
ok('默认绿涨', strpos($cssSrc, '.trend.up{color:#1a7a4d') !== false);
ok('默认红跌', strpos($cssSrc, '.trend.down{color:#b03a30') !== false);
ok('可翻成红涨绿跌', strpos($cssSrc, '.trend.up.ru') !== false
   && strpos($cssSrc, '.trend.down.ru') !== false);
ok('卡片顶边也跟着翻转', strpos($cssSrc, '.card.t-up.ru') !== false);
// 徽章会嵌在 13.5px 的表格和 12.5px 的卡片里，字号写成 em 会层层相乘，
// 小屏上缩到 11.3px 看不清 —— 必须用绝对值
ok('涨跌徽章用绝对字号，不跟着继承缩放',
   strpos($cssSrc, 'border-radius:20px;font-size:12.5px') !== false
   && strpos($cssSrc, '.trend em{font-style:normal;font-weight:400;font-size:12px') !== false);
ok('配色开关来自配置', strpos($cmpSrc, "trend_red_up") !== false);
$settingsTrend = require __DIR__ . '/../lib/settings.php';
ok('settings 里有 trend_red_up', array_key_exists('trend_red_up', $settingsTrend));
eq('默认是绿涨红跌', $settingsTrend['trend_red_up'], false);

ok('对比页登录保护', strpos($cmpSrc, 'Auth::requireLogin()') !== false);
ok('对比页校验两个区间',
   substr_count($cmpSrc, 'Biz::validateRange') >= 1
   && strpos($cmpSrc, 'Biz::validateRange($prevStart, $prevEnd)') !== false);
ok('菜品对比默认不查（要勾选）', strpos($cmpSrc, 'if ($withDish)') !== false);
ok('岗位对比默认不查（要勾选）', strpos($cmpSrc, 'if ($withStn)') !== false);
ok('含今天时提示数据不完整', strpos($cmpSrc, '今天还没营业完') !== false);
ok('两期不等长时给出警告', strpos($cmpSrc, '两期天数不一样') !== false);
ok('导航里有对比入口',
   strpos((string) file_get_contents(__DIR__ . '/../lib/view.php'), 'compare.php') !== false);

// =====================================================================
echo "\n【2e3】酒水核对：每人至少一份\n";
// =====================================================================

// ---- 酒水口径：按出品岗位名匹配出菜品清单 ----
$pcAll = [1 => 'Kitchen', 6 => 'bebidas', 9 => 'Barra', 11 => '热菜', 12 => 'Sushi 1'];
$menuAll = [
    431 => ['name' => 'Coca Cola', 'print_class' => 6,  'is_condiment' => false, 'price' => 2.5],
    432 => ['name' => 'Agua',      'print_class' => 6,  'is_condiment' => false, 'price' => 2.0],
    433 => ['name' => 'Cerveza',   'print_class' => 9,  'is_condiment' => false, 'price' => 3.0],
    501 => ['name' => 'Ramen',     'print_class' => 11, 'is_condiment' => false, 'price' => 9.0],
    502 => ['name' => 'S/Pepino',  'print_class' => 6,  'is_condiment' => true,  'price' => 0.0],
    777 => ['name' => 'Vino',      'print_class' => 1,  'is_condiment' => false, 'price' => 12.0],
];

$d = Report::drinkItems($menuAll, $pcAll, ['drink_print_classes' => ['bebidas*', 'bar*']]);
eq('命中饮料与吧台两个岗位', array_keys($d['classes']), [6, 9]);
eq('酒水菜品清单', $d['ids'], [431, 432, 433]);
ok('厨房的菜不算酒水', !in_array(501, $d['ids'], true));
ok('做法项不算酒水（哪怕挂在饮料岗位下）', !in_array(502, $d['ids'], true));

// 岗位名不匹配 → 一个都不算，页面会提示
$dNone = Report::drinkItems($menuAll, $pcAll, ['drink_print_classes' => ['不存在的岗位']]);
eq('岗位没命中时清单为空', $dNone['ids'], []);
eq('岗位没命中时不报错', $dNone['classes'], []);

// 单独补入 / 剔除
$dPlus = Report::drinkItems($menuAll, $pcAll,
    ['drink_print_classes' => ['bebidas*'], 'drink_extra_item_ids' => [777]]);
ok('额外补入的菜品算酒水', in_array(777, $dPlus['ids'], true));
eq('补入的菜品单独记账', $dPlus['extra'], [777]);
$dMinus = Report::drinkItems($menuAll, $pcAll,
    ['drink_print_classes' => ['bebidas*'], 'drink_exclude_item_ids' => [432]]);
ok('被剔除的菜品不算酒水', !in_array(432, $dMinus['ids'], true));
ok('剔除只影响指定的那一个', in_array(431, $dMinus['ids'], true));
// 剔除优先于补入，避免两边配矛盾时结果不确定
$dBoth = Report::drinkItems($menuAll, $pcAll, ['drink_print_classes' => [],
    'drink_extra_item_ids' => [777], 'drink_exclude_item_ids' => [777]]);
eq('同时补入又剔除时以剔除为准', $dBoth['ids'], []);

// ---- SQL：酒水和套餐在同一条查询里算出来，不额外扫表 ----
[$dsql] = Biz::buildComboCountSql([7, 8], [1890], [431, 432]);
ok('酒水份数进了同一条 SQL', strpos($dsql, 'AS drink_qty') !== false);
ok('酒水金额也一起算', strpos($dsql, 'AS drink_amount') !== false);
ok('酒水 SQL 仍然只查 order_detail 一张表', substr_count($dsql, 'FROM order_detail') === 1);
ok('酒水 SQL 未做 JOIN', stripos($dsql, 'join') === false);
ok('酒水 SQL 通过只读检查', (static function () use ($dsql) {
    try { Db::assertReadOnly($dsql); return true; } catch (Throwable $e) { return false; }
})());
ok('酒水菜品 ID 编进 IN 列表', strpos($dsql, 'IN (431,432) THEN quantity') !== false);
[$dsql0] = Biz::buildComboCountSql([7], [1890]);
ok('没配酒水清单时该项恒为 0', strpos($dsql0, '0   AS drink_amount') !== false
   || strpos($dsql0, '0  AS drink_qty') !== false);
ok('没配酒水清单时 SQL 仍然合法', (static function () use ($dsql0) {
    try { Db::assertReadOnly($dsql0); return true; } catch (Throwable $e) { return false; }
})());

// ---- 逐台判定：够、不够、一份没点 ----
$dh = static fn($id, $tbl, $g, $et = 0) => [
    'order_head_id' => $id, 't0' => date('Y-m-d H:i:s', time() - 600), 'guests' => $g,
    'table_name' => $tbl, 'employee' => 'A', 'amount' => 50.0, 'checks' => 1,
    'eat_type' => $et, 'status' => 0, 'settled' => 0];
$dc = static fn($id, $combo, $drink, $amt = 0.0) => [
    'order_head_id' => $id, 'combo_qty' => $combo, 'drink_qty' => $drink,
    'drink_amount' => $amt, 'dish_qty' => 8, 'lines_cnt' => 6];

$dHeads = [$dh(1, '1', 2), $dh(2, '2', 2), $dh(3, '3', 2), $dh(4, '4', 2),
           $dh(5, '5', 0), $dh(6, 'Llevar', 1, 3)];
$dCnts  = [$dc(1, 2, 2, 5.0),   // 2 人 2 杯 → 够
           $dc(2, 2, 5, 12.5),  // 2 人 5 杯 → 够（多了不算问题）
           $dc(3, 2, 1, 2.5),   // 2 人 1 杯 → 不足
           $dc(4, 2, 0, 0.0),   // 2 人 0 杯 → 未点酒水
           $dc(5, 0, 0, 0.0),   // 没填人数 → 不判定
           $dc(6, 0, 0, 0.0)];  // 外带 → 不判定
$dr = Report::buildOpenTables($dHeads, $dCnts, 4, [],
                              ['tables' => ['Llevar*']], ['min_drink' => 1]);
$dby = [];
foreach ($dr['rows'] as $r) { $dby[$r['id']] = $r; }

eq('2 人 2 杯 → 够',       $dby[1]['drink_state'], Report::DRINK_OK);
eq('2 人 5 杯 → 也算够',   $dby[2]['drink_state'], Report::DRINK_OK);
eq('2 人 1 杯 → 不足',     $dby[3]['drink_state'], Report::DRINK_SHORT);
eq('2 人 0 杯 → 未点酒水', $dby[4]['drink_state'], Report::DRINK_NONE);
eq('没填人数 → 不判定酒水', $dby[5]['drink_state'], Report::DRINK_NA);
eq('免核对的台 → 不判定酒水', $dby[6]['drink_state'], Report::DRINK_NA);
eq('还差几份（1 杯 vs 2 人）', $dby[3]['drink_short'], 1.0);
eq('够了就不欠', $dby[2]['drink_short'], 0.0);
eq('要求份数 = 人数 × 每人份数', $dby[1]['drink_need'], 2.0);

ok('套餐一致但酒水不足，仍算需要核对', $dby[3]['state'] === Report::OPEN_OK && $dby[3]['bad']);
ok('套餐一致且酒水够，才算没问题', $dby[1]['state'] === Report::OPEN_OK && !$dby[1]['bad']);
eq('待处理台数（3 号不足 + 4 号没点 + 5 号没填人数）', $dr['sum']['problem'], 3);
eq('其中酒水不足的', $dr['sum']['drink_problem'], 2);
eq('其中套餐有问题的（5 号没填人数）', $dr['sum']['combo_problem'], 1);
eq('酒水份数合计', $dr['sum']['drink'], 2 + 5 + 1);
eq('酒水金额合计', $dr['sum']['drink_amount'], 5.0 + 12.5 + 2.5);

// 每人两份：门槛跟着抬高
$dr2 = Report::buildOpenTables($dHeads, $dCnts, 4, [], [], ['min_drink' => 2]);
$dby2 = [];
foreach ($dr2['rows'] as $r) { $dby2[$r['id']] = $r; }
eq('每人两份时 2 人 2 杯不够', $dby2[1]['drink_state'], Report::DRINK_SHORT);
eq('每人两份时 2 人 5 杯仍然够', $dby2[2]['drink_state'], Report::DRINK_OK);
eq('每人两份时还差 2 份', $dby2[1]['drink_short'], 2.0);

// 关掉酒水核对：只统计，不判定
$dr0 = Report::buildOpenTables($dHeads, $dCnts, 4, [], [], ['min_drink' => 0]);
$dby0 = [];
foreach ($dr0['rows'] as $r) { $dby0[$r['id']] = $r; }
eq('关掉后不判定酒水', $dby0[4]['drink_state'], Report::DRINK_NA);
ok('关掉后 0 杯也不算问题', !$dby0[4]['bad']);
eq('关掉后酒水仍然照常统计', $dr0['sum']['drink'], 8.0);
eq('关掉后没有酒水问题台', $dr0['sum']['drink_problem'], 0);

// 明细里没有酒水字段（老数据/桩数据）也不能炸
$dNo = Report::buildOpenTables([$dh(9, '9', 2)],
    [['order_head_id' => 9, 'combo_qty' => 2, 'dish_qty' => 3, 'lines_cnt' => 3]], 4);
eq('缺 drink_qty 字段时按 0 处理', $dNo['rows'][0]['drink'], 0.0);
eq('缺字段时判为未点酒水', $dNo['rows'][0]['drink_state'], Report::DRINK_NONE);

// 排序：套餐问题 > 只有酒水不足 > 已确认 > 全合格
$sHeads = [$dh(1, 'A', 2), $dh(2, 'B', 2), $dh(3, 'C', 2)];
$sCnts  = [$dc(1, 2, 2),   // 全合格
           $dc(2, 0, 2),   // 套餐没打
           $dc(3, 2, 0)];  // 套餐一致，酒水没点
$sorted3 = array_column(Report::sortOpenTables(
    Report::buildOpenTables($sHeads, $sCnts, 4, [], [], ['min_drink' => 1])['rows']), 'table');
eq('排序：套餐问题最前，其次酒水不足，最后全合格', $sorted3, ['B', 'C', 'A']);

// 酒水从不足变成够 → 确认作废；不足时又加一杯（仍不足）→ 确认保留
$aHead = [$dh(1, 'A', 4)];
$aRow  = Report::buildOpenTables($aHead, [$dc(1, 4, 1)], 4, [], [], ['min_drink' => 1]);
$aFp   = $aRow['rows'][0]['fp'];
$aAck  = [1 => ['fp' => $aFp, 'at' => time()]];
ok('确认后不再计入待处理',
   Report::buildOpenTables($aHead, [$dc(1, 4, 1)], 4, $aAck, [], ['min_drink' => 1])
       ['sum']['problem'] === 0);
ok('酒水仍不足时多点一杯，确认保留',
   Report::buildOpenTables($aHead, [$dc(1, 4, 2)], 4, $aAck, [], ['min_drink' => 1])
       ['rows'][0]['acked']);
ok('酒水补齐后确认作废（状态已变）',
   !Report::buildOpenTables($aHead, [$dc(1, 4, 4)], 4, $aAck, [], ['min_drink' => 1])
       ['rows'][0]['acked']);

// =====================================================================
echo "\n【2e4】时钟与时区\n";
// PHP 用自己的时钟算「开了多久」，时间数据却是 POS 写的。php.ini 没设
// date.timezone 时 PHP 走 UTC，两边差 1~2 小时 —— 不报错，只是所有跟
// 时间有关的数字悄悄不对，这是最难自己发现的一类问题。
// =====================================================================

$tzHead = static fn($sec) => [['order_head_id' => 1,
    't0' => date('Y-m-d H:i:s', time() + $sec), 'guests' => 2, 'table_name' => 'T',
    'employee' => '', 'amount' => 0, 'checks' => 1, 'eat_type' => 0,
    'status' => 0, 'settled' => 0]];

$future = Report::buildOpenTables($tzHead(7200), []);
ok('开台时间在未来 → 标记 skew', !empty($future['rows'][0]['skew']));
ok('时钟异常时不再误报滞留', !$future['rows'][0]['stale']);
eq('汇总里记下异常台数', $future['sum']['clock_skew'], 1);

$normal = Report::buildOpenTables($tzHead(-600), []);
ok('正常开台不报时钟异常', empty($normal['rows'][0]['skew']));
eq('正常时汇总为 0', $normal['sum']['clock_skew'], 0);

$long = Report::buildOpenTables($tzHead(-5 * 3600), [], 4);
ok('开台 5 小时仍正常判为滞留', $long['rows'][0]['stale'] && empty($long['rows'][0]['skew']));
eq('滞留台不算时钟异常', $long['sum']['clock_skew'], 0);

// warn_hours <= 0 表示关掉提醒，而不是「全部标红」
ok('warn_hours = 0 关掉滞留提醒',
   !Report::buildOpenTables($tzHead(-99 * 3600), [], 0)['rows'][0]['stale']);
ok('warn_hours 负数同样关掉',
   !Report::buildOpenTables($tzHead(-99 * 3600), [], -1)['rows'][0]['stale']);

// 时间字段是垃圾字符串时，不能被 strtotime 当成 1970 年从而误报滞留
$junk = Report::buildOpenTables([['order_head_id' => 1, 't0' => '不是时间', 'guests' => 2,
    'table_name' => 'T', 'employee' => '', 'amount' => 0, 'checks' => 1,
    'eat_type' => 0, 'status' => 0, 'settled' => 0]], []);
eq('无法解析的时间 → minutes 为 null', $junk['rows'][0]['minutes'], null);
ok('无法解析的时间不误报滞留', !$junk['rows'][0]['stale']);
ok('无法解析的时间不误报时钟异常', empty($junk['rows'][0]['skew']));

// 程序必须显式设定时区，不能听凭 php.ini（没设时 PHP 默认 UTC）
$settingsTz = require __DIR__ . '/../lib/settings.php';
ok('settings.php 带 timezone 项', array_key_exists('timezone', $settingsTz));
ok('默认时区可用', $settingsTz['timezone'] === ''
   || (static function () use ($settingsTz) {
        try { new DateTimeZone($settingsTz['timezone']); return true; }
        catch (Throwable $e) { return false; } })());
ok('页面会提示时钟异常',
   strpos((string) file_get_contents(__DIR__ . '/../open.php'), 'clock_skew') !== false);
ok('checkdb 会报出时钟差',
   strpos((string) file_get_contents(__DIR__ . '/checkdb.php'), '时钟一致') !== false);

// =====================================================================
echo "\n【2e5】两种驱动的返回类型必须算出同样结果\n";
// PDO 默认把所有列取成字符串，mysqli + mysqlnd 取成原生类型。
// 同一段代码在两种驱动下必须完全一致，否则换个驱动数字就变了。
// =====================================================================

$asString = static fn(array $rows) => array_map(
    static fn($r) => array_map(static fn($v) => $v === null ? null : (string) $v, $r), $rows);

$tHeads = [
    ['order_head_id' => 1, 't0' => date('Y-m-d H:i:s', time() - 3600), 'guests' => 4,
     'table_name' => '11', 'employee' => 'A', 'amount' => 53.7, 'checks' => 1,
     'eat_type' => 0, 'status' => 0, 'settled' => 0],
    ['order_head_id' => 2, 't0' => date('Y-m-d H:i:s', time() - 3600), 'guests' => 0,
     'table_name' => '9', 'employee' => 'B', 'amount' => 0.0, 'checks' => 2,
     'eat_type' => 3, 'status' => 0, 'settled' => 1],
];
$tCnts = [['order_head_id' => 1, 'combo_qty' => 4, 'drink_qty' => 3,
           'drink_amount' => 7.5, 'dish_qty' => 12, 'lines_cnt' => 9]];
eq('开台核对：两种驱动结果一致',
   Report::buildOpenTables($tHeads, $tCnts, 4, [], [], ['min_drink' => 1]),
   Report::buildOpenTables($asString($tHeads), $asString($tCnts), 4, [], [], ['min_drink' => 1]));

$tSales = [['biz_date' => '2026-09-01', 'seg' => 'day', 'checks' => 3, 'guests' => 7,
            'actual' => 150.5, 'original' => 160.0, 'discount' => -9.5, 'service' => 0,
            'tax' => 13.7, 'should_amt' => 150.5, 'ret' => 0]];
eq('营业额透视：两种驱动结果一致',
   Report::pivotSales($tSales), Report::pivotSales($asString($tSales)));

$tMenu = [431 => ['name' => 'Agua', 'print_class' => 6, 'is_condiment' => false, 'price' => 2.0]];
$tDet  = [['menu_item_id' => 431, 'item_name' => 'Agua', 'seg' => 'day',
           'qty' => 10, 'times' => 5, 'amount' => 20.0]];
eq('菜品汇总：两种驱动结果一致',
   Report::buildDishes($tMenu, [6 => 'bebidas'], $tDet),
   Report::buildDishes($tMenu, [6 => 'bebidas'], $asString($tDet)));

$tSt = [['pc' => 6, 'seg' => 'day', 'orders' => 12, 'items' => 3, 'qty' => 30,
         'lines_cnt' => 25, 'amount' => 60.0]];
eq('岗位单量：两种驱动结果一致',
   Report::buildStations([6 => 'bebidas'], $tSt),
   Report::buildStations([6 => 'bebidas'], $asString($tSt)));

// =====================================================================
echo "\n【2e6】配置写错也不能把程序搞崩\n";
// config.php 是人手工改的，写成字符串、写成 null、少写一项都可能发生。
// =====================================================================

$cfgMenu = [1 => ['name' => 'A', 'print_class' => 6, 'is_condiment' => false, 'price' => 1.0]];
ok('免核对规则写成字符串不炸', is_array(Report::skipRules(['no_combo_tables' => 'Llevar*'])));
ok('免核对规则写成 null 不炸',  is_array(Report::skipRules(['no_combo_tables' => null])));
ok('eat_types 写成字符串不炸',  is_array(Report::skipRules(['no_combo_eat_types' => '3'])));
ok('酒水岗位写成字符串不炸',
   is_array(Report::drinkItems($cfgMenu, [6 => 'bebidas'], ['drink_print_classes' => 'bebidas*'])));
ok('酒水配置整个缺失不炸',
   is_array(Report::drinkItems($cfgMenu, [6 => 'bebidas'], [])));
ok('通配符 * 匹配全部岗位',
   Report::drinkItems($cfgMenu, [6 => 'bebidas'], ['drink_print_classes' => ['*']])['ids'] === [1]);
ok('规则里的正则元字符不会炸',
   Report::matchesAny('a', ['(((', '[[[', '\\', '+*?', '$^']) === false);
ok('非法 UTF-8 桌号不炸', is_bool(Report::matchesAny("\xC3\x28", ['A*'])));
ok('超长桌号不炸',        is_bool(Report::matchesAny(str_repeat('A', 10000), ['A*'])));

$mdH = [['order_head_id' => 1, 't0' => date('Y-m-d H:i:s'), 'guests' => 2, 'table_name' => 'T',
         'employee' => '', 'amount' => 0, 'checks' => 1, 'eat_type' => 0,
         'status' => 0, 'settled' => 0]];
$mdC = [['order_head_id' => 1, 'combo_qty' => 2, 'drink_qty' => 1, 'dish_qty' => 3, 'lines_cnt' => 3]];
eq('min_drink 负数视为不核对',
   Report::buildOpenTables($mdH, $mdC, 4, [], [], ['min_drink' => -1])['rows'][0]['drink_state'],
   Report::DRINK_NA);
eq('min_drink 写成字符串也能用',
   Report::buildOpenTables($mdH, $mdC, 4, [], [], ['min_drink' => '2'])['rows'][0]['drink_state'],
   Report::DRINK_SHORT);

// 明细里混进开台列表没有的订单，不能串到别的台上
$stray = Report::buildOpenTables($mdH,
    [['order_head_id' => 999, 'combo_qty' => 99, 'drink_qty' => 99,
      'dish_qty' => 99, 'lines_cnt' => 9]]);
eq('明细里的野订单不串台', $stray['rows'][0]['combo'], 0.0);
eq('野订单不会凭空多出一行', count($stray['rows']), 1);

// =====================================================================
echo "\n【2f2】开台核对的人工确认\n";
// =====================================================================

Ack::resetMemory();

// 指纹只认「人数 + 套餐份数」，其他字段变了不影响
$base = ['guests' => 4, 'combo' => 2.0, 'amount' => 47.8, 'dishes' => 8];
eq('指纹格式', Ack::fingerprint($base), '4:200:d0');
eq('金额变化不影响指纹', Ack::fingerprint($base + []), Ack::fingerprint(array_merge($base, ['amount' => 99.9])));
eq('菜品数变化不影响指纹', Ack::fingerprint($base), Ack::fingerprint(array_merge($base, ['dishes' => 30])));
ok('人数变化会改变指纹', Ack::fingerprint($base) !== Ack::fingerprint(array_merge($base, ['guests' => 5])));
ok('套餐份数变化会改变指纹', Ack::fingerprint($base) !== Ack::fingerprint(array_merge($base, ['combo' => 3.0])));
eq('小数份数指纹稳定', Ack::fingerprint(['guests' => 2, 'combo' => 1.5]), '2:150:d0');
ok('酒水达标与否会改变指纹',
   Ack::fingerprint($base) !== Ack::fingerprint(array_merge($base, ['drink_ok' => true])));
eq('酒水达标时指纹带 d1',
   Ack::fingerprint(['guests' => 2, 'combo' => 2, 'drink_ok' => true]), '2:200:d1');

// 存取
Ack::set(101, '4:200:d0');
eq('存入后能取到', Ack::all()[101]['fp'] ?? null, '4:200:d0');
Ack::clear(101);
eq('撤销后取不到', Ack::all()[101] ?? null, null);
Ack::set(101, '4:200');
Ack::set(102, '2:0:d0');
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

$noAck = Report::buildOpenTables($h4, $c4, 4, [], [], $NODRINK);
eq('未确认时是「未打套餐」', $noAck['rows'][0]['state'], Report::OPEN_NONE);
eq('未确认时计入待处理', $noAck['sum']['problem'], 1);
eq('未确认时 acked 为假', $noAck['rows'][0]['acked'], false);
$fp = $noAck['rows'][0]['fp'];
eq('行里带出的指纹与 Ack 算的一致', $fp,
   Ack::fingerprint(['guests' => 8, 'combo' => 0, 'drink_ok' => true]));

$acks = [7 => ['fp' => $fp, 'at' => time()]];
$withAck = Report::buildOpenTables($h4, $c4, 4, $acks, [], $NODRINK);
ok('确认后标记为已确认', $withAck['rows'][0]['acked']);
eq('确认后不再计入待处理', $withAck['sum']['problem'], 0);
eq('确认后单独计数', $withAck['sum']['acked'], 1);
eq('确认后原始状态仍保留', $withAck['rows'][0]['state'], Report::OPEN_NONE);
ok('确认时间被带出', $withAck['rows'][0]['acked_at'] > 0);

// 人数变了 → 确认自动作废
$h5 = $h4; $h5[0]['guests'] = 10;
$changed = Report::buildOpenTables($h5, $c4, 4, $acks, [], $NODRINK);
ok('人数变化后确认作废', !$changed['rows'][0]['acked']);
eq('作废后重新计入待处理', $changed['sum']['problem'], 1);

// 补打了套餐 → 确认也作废（而且状态本身也变了）
$c5 = [['order_head_id' => 7, 'combo_qty' => 8, 'dish_qty' => 11, 'lines_cnt' => 11]];
$fixed = Report::buildOpenTables($h4, $c5, 4, $acks, [], $NODRINK);
ok('补打套餐后确认作废', !$fixed['rows'][0]['acked']);
eq('补打套餐后状态变为一致', $fixed['rows'][0]['state'], Report::OPEN_OK);

// 指纹对不上的陈旧确认不生效
$stale = Report::buildOpenTables($h4, $c4, 4, [7 => ['fp' => '999:999', 'at' => time()]], [], $NODRINK);
ok('指纹不匹配的确认不生效', !$stale['rows'][0]['acked']);

// 只是金额/菜品变了，确认应当保持
$c6 = [['order_head_id' => 7, 'combo_qty' => 0, 'dish_qty' => 30, 'lines_cnt' => 25]];
$h6 = $h4; $h6[0]['amount'] = 300.0;
$keep = Report::buildOpenTables($h6, $c6, 4, $acks, [], $NODRINK);
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
$mixed = Report::buildOpenTables($hs, $cs, 4, [3 => ['fp' => '3:0:d1', 'at' => time()]], [], $NODRINK);
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
    Report::buildOpenTables($sortHeads, $sortCnts, 4, [], [], $NODRINK)['rows']);
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
    Report::sortOpenTables(Report::buildOpenTables($sortHeads, $sortCnts, 4, [], [], $NODRINK)['rows'], false),
    'table');
eq('不分组时全部按桌号排', $byTable, ['2', '7', '9', '10', '51', 'A2', 'A10', 'Llevar']);

// 已确认的台也要按桌号排（在问题台之后、正常台之前）
$ackedAll = [4 => ['fp' => '4:0:d1', 'at' => time()], 5 => ['fp' => '4:0:d1', 'at' => time()]];
$ackOrder = array_column(Report::sortOpenTables(
    Report::buildOpenTables($sortHeads, $sortCnts, 4, $ackedAll, [], $NODRINK)['rows']), 'table');
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
   strpos($openSrc, 'Report::buildOpenTables($heads, $counts, $warnHours, Ack::all(), $skipRules,') !== false);
ok('「只看有问题」会滤掉免核对的台',
   strpos($openSrc, "\$r['state'] !== Report::OPEN_SKIP") !== false);
ok('免核对的台不给确认按钮',
   strpos($openSrc, "\$r['state'] === Report::OPEN_SKIP") !== false);
ok('服务端拒绝确认免核对的台', strpos($openSrc, '本来就免核对') !== false);
ok('页面上列出了当前生效的免核对规则', strpos($openSrc, '当前的判定规则') !== false);

// ---- 页面：酒水核对 ----
ok('酒水口径从 config 读取', strpos($openSrc, 'Report::drinkItems($menuItems, $printClasses, $cfg)') !== false);
ok('酒水菜品清单传给了明细查询',
   strpos($openSrc, 'Biz::orderComboCounts($ids, $comboIds, $drinkIds)') !== false);
ok('每人至少几份来自配置', strpos($openSrc, "drink_min_per_guest") !== false);
ok('酒水不足的台也能人工确认', strpos($openSrc, "!\$r['bad'] || \$r['state'] === Report::OPEN_SKIP") !== false);
ok('页面列出酒水口径命中的岗位', strpos($openSrc, '酒水口径') !== false);
ok('一个岗位都没命中时给出提示', strpos($openSrc, '当前一个岗位都没命中') !== false);
ok('页面说明多点不算问题', strpos($openSrc, '多了不算问题') !== false);

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
ok('酒水不足状态有样式',
   strpos($css, '.state.s-dshort') !== false && strpos($css, '.state.s-dnone') !== false);
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
