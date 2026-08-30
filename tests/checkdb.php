<?php
/**
 * 环境与数据库连接体检 —— 部署后先跑这个，出问题能立刻定位。
 *
 *     php tests/checkdb.php
 *
 * 也可以放到浏览器里访问（会自动输出 <pre>）。
 * 全程只执行 SELECT，不会改动任何数据。
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/biz.php';
require_once __DIR__ . '/../lib/report.php';

// 浏览器访问时要求登录（本页会显示数据库信息）；命令行运行不受影响。
// 必须在任何输出之前完成，否则 header() 会因为「headers already sent」失效。
$cli = PHP_SAPI === 'cli';
if (!$cli && !Auth::isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font:13px/1.6 monospace;padding:16px">';
}

$warn = 0;
$err  = 0;

function line(string $mark, string $msg): void
{
    echo "  {$mark} {$msg}\n";
}
function okmsg(string $m): void  { line('[ OK ]', $m); }
function warnmsg(string $m): void { global $warn; $warn++; line('[警告]', $m); }
function errmsg(string $m): void  { global $err;  $err++;  line('[错误]', $m); }

// =====================================================================
echo "\n===== 1. PHP 环境 =====\n";
// =====================================================================

okmsg('PHP 版本: ' . PHP_VERSION);
if (version_compare(PHP_VERSION, '7.4', '<')) {
    errmsg('本程序需要 PHP 7.4 以上');
}

if (extension_loaded('pdo')) {
    okmsg('pdo 扩展已加载');
} else {
    errmsg('pdo 扩展未加载');
}

// 本程序支持 PDO 与 mysqli 两种驱动，有一个能用就行
okmsg('pdo_mysql 扩展: ' . (extension_loaded('pdo_mysql') ? '已加载' : '未加载'));
okmsg('mysqli   扩展: ' . (extension_loaded('mysqli') ? '已加载' : '未加载'));
okmsg('mbstring 扩展: ' . (extension_loaded('mbstring') ? '已加载' : '未加载（本程序不依赖，无影响）'));

$have      = Db::availableDrivers();
$cfgDriver = Db::config()['driver'] ?? 'auto';
if ($have) {
    $pick = $cfgDriver === 'auto' ? $have[0] . '（自动选择）' : $cfgDriver . '（config.php 指定）';
    okmsg('可用驱动: ' . implode(', ', $have) . '　→ 将使用 ' . $pick);
    if ($cfgDriver !== 'auto' && !in_array($cfgDriver, $have, true)) {
        errmsg("config.php 里指定的 driver = {$cfgDriver} 不可用");
    }
} else {
    errmsg('pdo_mysql 和 mysqli 都未加载 —— 请在 php.ini 启用其中之一'
        . '（推荐 extension=mysqli），改完重启 Web 服务。详见 tests/env.php');
}

// =====================================================================
echo "\n===== 2. 配置文件 =====\n";
// =====================================================================

$cfg = Db::config();
okmsg(sprintf('目标: %s:%d / 库 %s / 账号 %s',
    $cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['user']));
okmsg(sprintf('营业时段: 白天 %s–%s，晚上 %s–次日 %s，营业日切分 %d 点',
    substr($cfg['day_start'], 0, 5), substr($cfg['day_end'], 0, 5),
    substr($cfg['night_start'], 0, 5), substr($cfg['night_end'], 0, 5),
    $cfg['day_cut_hour']));
okmsg('日期跨度上限: ' . $cfg['max_range_days'] . ' 天');
okmsg('免核对的桌号: ' . (implode('、', (array) $cfg['no_combo_tables']) ?: '（无，所有台都核对）'));

// 配置分两层：lib/settings.php 是随程序更新的默认值，config.php 覆盖其中个别项。
// 列出被覆盖的功能参数，方便排查「升级了但没生效」这类问题。
$funcKeys = array_diff(array_keys(Db::defaults()), ['driver']);
$custom   = array_intersect($funcKeys, array_keys(Db::overrides()));
okmsg($custom
    ? '功能参数: config.php 覆盖了 ' . implode('、', $custom) . '（其余用 lib/settings.php 的默认值）'
    : '功能参数: 全部用 lib/settings.php 的默认值，会随程序升级更新');

if ((int) substr($cfg['night_end'], 0, 2) !== (int) $cfg['day_cut_hour']) {
    warnmsg('night_end 与 day_cut_hour 不一致，跨夜账单的营业日归属会出错');
}
if ($cfg['pass'] === '在这里填密码') {
    errmsg('config.php 里的数据库密码还没填');
}

// 登录密码。这里只警告不报错 —— 密码没设好也不该妨碍你先把数据库连通性查清楚。
if (!Auth::isConfigured()) {
    warnmsg('登录密码 password 还没设置 —— 页面谁都登录不进去，请在 config.php 里补上');
} elseif (preg_match('/^\$(2[aby]|argon2)/', (string) $cfg['password'])) {
    okmsg('登录密码: 已设置（bcrypt 哈希）');
} else {
    warnmsg('登录密码: 已设置（明文）—— 建议改用哈希，见 README「登录」一节');
}

if ($err > 0) {
    echo "\n环境有问题，先解决上面的错误再继续。\n";
    if (!$cli) echo '</pre>';
    exit(1);
}

// =====================================================================
echo "\n===== 3. 数据库连接 =====\n";
// =====================================================================

try {
    $t = microtime(true);
    $v = Db::selectOne('SELECT VERSION() AS v, CURRENT_USER() AS u, DATABASE() AS d, NOW() AS n');
    okmsg(sprintf('连接成功（%.0f ms）', (microtime(true) - $t) * 1000));
    okmsg('MySQL 版本: ' . $v['v']);
    okmsg('当前账号: ' . $v['u']);
    okmsg('当前库: ' . $v['d']);
    okmsg('数据库时间: ' . $v['n'] . '（PHP 时间: ' . date('Y-m-d H:i:s') . '）');

    if (abs(strtotime($v['n']) - time()) > 300) {
        warnmsg('数据库与 PHP 的时间相差超过 5 分钟，"今天"的判断可能不准');
    }
} catch (Throwable $e) {
    errmsg('连接失败: ' . $e->getMessage());
    echo "\n请检查 config.php 里的 host / port / dbname / user / pass。\n";
    if (!$cli) echo '</pre>';
    exit(1);
}

// =====================================================================
echo "\n===== 4. 账号权限（应当是只读）=====\n";
// =====================================================================

try {
    $priv = Db::select(
        "SELECT DISTINCT privilege_type FROM information_schema.user_privileges
         WHERE grantee LIKE :g
         UNION SELECT DISTINCT privilege_type FROM information_schema.schema_privileges
         WHERE grantee LIKE :g2 AND table_schema = :db",
        [':g' => '%' . explode('@', (string) $v['u'])[0] . '%',
         ':g2' => '%' . explode('@', (string) $v['u'])[0] . '%',
         ':db' => $cfg['dbname']]
    );
    $types = array_column($priv, 'privilege_type');
    $writes = array_intersect($types, ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE']);
    if (!$types) {
        warnmsg('查不到该账号的权限明细（可能只有表级授权），请自行确认是只读账号');
    } elseif ($writes) {
        warnmsg('该账号具有写权限: ' . implode(', ', $writes)
            . ' —— 程序本身不会写库，但建议换成只有 SELECT 权限的账号（见 README）');
    } else {
        okmsg('账号只有只读权限: ' . implode(', ', $types));
    }
} catch (Throwable $e) {
    warnmsg('无法读取权限信息（不影响使用）: ' . $e->getMessage());
}

// =====================================================================
echo "\n===== 5. 需要用到的表 =====\n";
// =====================================================================

$need = ['history_order_head', 'history_order_detail', 'order_head', 'order_detail',
         'menu_item', 'print_class'];
$sizes = [];
try {
    $rows = Db::select(
        "SELECT table_name AS t, table_rows AS r,
                ROUND((data_length + index_length)/1024/1024, 1) AS mb
         FROM information_schema.tables
         WHERE table_schema = :db AND table_name IN
               ('history_order_head','history_order_detail','order_head','order_detail','menu_item','print_class')",
        [':db' => $cfg['dbname']]
    );
    foreach ($rows as $r) {
        $sizes[$r['t']] = ['rows' => (int) $r['r'], 'mb' => (float) $r['mb']];
    }
    foreach ($need as $t) {
        if (isset($sizes[$t])) {
            okmsg(sprintf('%-22s 约 %s 行 / %s MB',
                $t, number_format($sizes[$t]['rows']), $sizes[$t]['mb']));
        } else {
            errmsg("缺少表: {$t}");
        }
    }
    echo "  （行数为 InnoDB 估算值，非精确值）\n";
} catch (Throwable $e) {
    errmsg('读取表信息失败: ' . $e->getMessage());
}

// =====================================================================
echo "\n===== 6. 实时表体量（README 第五节提到的风险点）=====\n";
// =====================================================================

foreach (['order_head', 'order_detail'] as $t) {
    $n = $sizes[$t]['rows'] ?? 0;
    if ($n > 200000) {
        warnmsg(sprintf('%s 约 %s 行 —— 这两张表没有时间索引，'
            . '勾选"包含当天未日结数据"会全表扫描。建议平时取消勾选。',
            $t, number_format($n)));
    } else {
        okmsg(sprintf('%s 约 %s 行，勾选"包含当天未日结数据"没有问题',
            $t, number_format($n)));
    }
}

// =====================================================================
echo "\n===== 7. 数据时间范围 =====\n";
// =====================================================================

try {
    $r = Db::selectOne('SELECT MIN(order_start_time) AS a, MAX(order_start_time) AS b,
                               COUNT(*) AS c FROM history_order_head');
    okmsg(sprintf('history_order_head: %s 条，%s ~ %s',
        number_format((int) $r['c']), $r['a'] ?? '空', $r['b'] ?? '空'));
    $r2 = Db::selectOne('SELECT MIN(order_time) AS a, MAX(order_time) AS b
                         FROM history_order_detail');
    okmsg(sprintf('history_order_detail: %s ~ %s', $r2['a'] ?? '空', $r2['b'] ?? '空'));
} catch (Throwable $e) {
    errmsg('读取时间范围失败: ' . $e->getMessage());
}

// =====================================================================
echo "\n===== 8. 实跑四条统计查询（近 7 天）=====\n";
// =====================================================================

$today = date('Y-m-d', time() - $cfg['day_cut_hour'] * 3600);
$start = date('Y-m-d', strtotime($today . ' -6 day'));
[$from, $to] = Biz::range($start, $today);
echo "  区间: {$from} ~ {$to}\n";

$jobs = [
    '营业额统计'   => fn() => Biz::salesByDay($from, $to, 'history_order_head'),
    '菜品汇总'     => fn() => Biz::dishTotals($from, $to, 'history_order_detail'),
    '菜品字典'     => fn() => Biz::menuItems(),
    '岗位字典'     => fn() => Biz::printClasses(),
];
$results = [];
foreach ($jobs as $name => $fn) {
    try {
        $t = microtime(true);
        $res = $fn();
        $ms  = (microtime(true) - $t) * 1000;
        $results[$name] = $res;
        $tag = $ms > 3000 ? '[警告]' : '[ OK ]';
        if ($ms > 3000) { $warn++; }
        line($tag, sprintf('%-12s 返回 %s 组，耗时 %.0f ms', $name, number_format(count($res)), $ms));
    } catch (Throwable $e) {
        errmsg(sprintf('%-12s 失败: %s', $name, $e->getMessage()));
    }
}

// 结果抽样，确认口径没跑偏
if (!empty($results['营业额统计'])) {
    echo "\n  近 7 天汇总:\n";
    $sum = [];
    foreach ($results['营业额统计'] as $r) {
        $s = $r['seg'];
        $sum[$s]['checks'] = ($sum[$s]['checks'] ?? 0) + (int) $r['checks'];
        $sum[$s]['guests'] = ($sum[$s]['guests'] ?? 0) + (int) $r['guests'];
        $sum[$s]['actual'] = ($sum[$s]['actual'] ?? 0) + (float) $r['actual'];
    }
    foreach (['day' => '白天', 'night' => '晚上', 'gap' => '时段外'] as $k => $lab) {
        if (isset($sum[$k])) {
            printf("    %-8s %5d 单 / %6d 人 / 营业额 %12s\n",
                $lab, $sum[$k]['checks'], $sum[$k]['guests'], number_format($sum[$k]['actual'], 2));
        }
    }
    if (isset($sum['gap'])) {
        warnmsg('有账单落在白天/晚上两个时段之外，页面会单列"时段外"一行显示');
    }
}

if (!empty($results['菜品汇总']) && !empty($results['菜品字典'])) {
    $b = Report::buildDishes($results['菜品字典'], $results['岗位字典'] ?? [], $results['菜品汇总']);
    $top = Report::rank($b['items'], 'total', 'desc', 3);
    echo "\n  近 7 天点单最多的 3 个菜:\n";
    foreach ($top as $i => $it) {
        // 不用 mb_strimwidth —— 很多环境没有启用 mbstring 扩展
        $nm = preg_replace('/^(.{0,38}).*$/us', '$1', (string) $it['name']);
        if ($nm !== $it['name']) {
            $nm .= '…';
        }
        printf("    %d. %-40s %8s 份   [%s]\n",
            $i + 1, $nm, $it['total']['qty'], $it['pc_name']);
    }
    $noPc = count(array_filter($b['items'], fn($x) => $x['pc_name'] === '未分配岗位'));
    if ($noPc > 0) {
        warnmsg("有 {$noPc} 个菜品在 menu_item 里没有配岗位，会归到「未分配岗位」");
    }
}

// =====================================================================
echo "\n" . str_repeat('=', 52) . "\n";
if ($err > 0) {
    echo "体检结果: {$err} 个错误、{$warn} 个警告 —— 请先解决错误\n";
} elseif ($warn > 0) {
    echo "体检结果: 通过，但有 {$warn} 个警告需要留意\n";
} else {
    echo "体检结果: 全部通过，可以正常使用\n";
}
if (!$cli) echo '</pre>';
exit($err > 0 ? 1 : 0);
