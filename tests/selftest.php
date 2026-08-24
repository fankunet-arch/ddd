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
$byTime = Report::sortOpenTables($ot['rows'], false);
ok('关闭问题优先后按开台时间排', $byTime[0]['start'] <= $byTime[1]['start']);

foreach ([Report::OPEN_OK, Report::OPEN_SHORT, Report::OPEN_OVER,
          Report::OPEN_NONE, Report::OPEN_NOGUEST] as $st) {
    ok("状态 {$st} 有中文标签", Report::openStateLabel($st) !== $st);
}

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
