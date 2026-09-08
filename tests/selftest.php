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
echo "\n【0】铁律（见 注意事项.md）\n";
// 这一组把「注意事项.md」里能机械检查的规矩变成测试。
// 光写文档是会被忘的，写成测试才拦得住。
// =====================================================================

$ROOT = __DIR__ . '/..';
$phpFiles = [];
foreach (['', '/lib', '/tests'] as $dir) {
    foreach ((array) glob($ROOT . $dir . '/*.php') as $f) {
        $phpFiles[] = $f;
    }
}
ok('扫到了程序文件', count($phpFiles) >= 15);

// ---- 铁律一 & 二：数据库出口只有两个，各管各的 ----
//   lib/db.php    → POS 主库（MySQL），只读
//   lib/store.php → 自有数据（SQLite），可写
// 除这两个文件，任何地方都不许自己建连接。
$dbOut = [];
foreach ($phpFiles as $f) {
    $base = basename($f);
    if (in_array($base, ['db.php', 'store.php', 'env.php'], true)) {
        continue;                       // 两个出口 + env.php（只做扩展探测）
    }
    if ($base === 'selftest.php') {
        // 自检脚本要自己造一个「别人的数据库」来验证 Store 会拒绝打开它。
        // 它不在网页那条链路上，但也不能因此放行 MySQL —— 单独查一道：
        // 所有 new PDO 的 DSN 必须是 sqlite:，而且不许出现 mysqli。
        // 关键词用拼接写，否则这几行自己会被自己的检查匹配到（第一版就栽在这）。
        $sf = (string) file_get_contents($f);
        preg_match_all('/new\\s+PDO\\s*\\(\\s*([\'"])(.*?)\\1/', $sf, $mm);
        $badDsn = array_values(array_filter($mm[2],
            static fn($d) => strncmp($d, 'sqlite:', 7) !== 0));
        eq('自检脚本里的 PDO 全是 SQLite', $badDsn, []);
        // 只找【真的实例化】：new + 空白 + mysqli + (。
        // 单纯搜 "mysqli" 会命中上面那几行【用来检查别的文件的正则】，永远失败。
        ok('自检脚本不实例化 mysqli',
           preg_match('/new\\s+my' . 'sqli\\s*\\(/i', $sf) === 0);
        continue;
    }
    $src = (string) file_get_contents($f);
    if (preg_match('/\bnew\s+(PDO|mysqli)\b|\bmysqli_connect\s*\(/i', $src)) {
        $dbOut[] = $base;
    }
}
eq('除两个出口外没有第三个数据库连接', $dbOut, []);
// 主库出口不许碰 SQLite，自有出口不许碰 MySQL —— 两边物理隔离
$storeSrc = (string) file_get_contents($ROOT . '/lib/store.php');
ok('Store 只连 SQLite', strpos($storeSrc, "'sqlite:'") !== false
   && !preg_match('/new\s+mysqli|mysqli_connect|mysql:host/i', $storeSrc));
ok('Store 会拒绝非 sqlite 的 DSN', strpos($storeSrc, 'Store 只允许连接 SQLite') !== false);
// 没配置 store_path 时，程序自己挑位置 —— 挑出来的必须在【程序目录之外】。
// 光报警不解决问题：没人改配置的话，下一次还是往同一个地方建库。
$docSaved0 = $_SERVER['DOCUMENT_ROOT'] ?? null;
unset($_SERVER['DOCUMENT_ROOT']);
require_once $ROOT . '/lib/store.php';
Db::forTests(['store_path' => '']);
Store::resetPathCache();
$autoPath = Store::path();
// $ROOT 是 __DIR__.'/..'，没规范化过；Store 返回的是规范路径。
// 直接比前缀等于什么都没比 —— 这条断言第一版就是这么白跑的。
$rootReal = (string) realpath($ROOT);
ok('没配置时自动选的位置在程序目录之外',
   $rootReal !== ''
   && strncmp($autoPath, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) !== 0);
// 通用文件名（app.db）没法区分是谁的库 —— 用本程序专有的名字
ok('用专有文件名而不是通用的 app.db',
   basename($autoPath) === Store::FILE_NAME && Store::FILE_NAME !== 'app.db');
ok('目录名也是专有的', basename(dirname($autoPath)) === Store::DIR_NAME);
if ($docSaved0 !== null) { $_SERVER['DOCUMENT_ROOT'] = $docSaved0; }
Db::forTests(null);
Store::resetPathCache();
// 跨源不许 JOIN：Store 的 SQL 里不许出现任何主库表名
foreach (['history_order_head', 'history_order_detail', 'order_head', 'order_detail',
          'menu_item', 'print_class'] as $posTable) {
    ok("Store 不碰主库的表 {$posTable}", strpos($storeSrc, $posTable) === false);
}

// ---- 铁律一：Db 不许提供写入口 ----
$dbSrc = (string) file_get_contents($ROOT . '/lib/db.php');
foreach (['exec', 'beginTransaction', 'multi_query', 'prepare_multi'] as $bad) {
    ok("Db 没有暴露 {$bad}()", !preg_match('/function\s+' . $bad . '\s*\(/i', $dbSrc));
}
ok('Db 只对外提供 select/selectOne',
   preg_match('/public static function select\s*\(/', $dbSrc) === 1
   && preg_match('/public static function selectOne\s*\(/', $dbSrc) === 1);
ok('多语句执行被显式关掉', strpos($dbSrc, 'MYSQL_ATTR_MULTI_STATEMENTS') !== false);
ok('每条 SQL 都要过只读检查', substr_count($dbSrc, 'assertReadOnly') >= 2);

// ---- 铁律二：写语句只允许出现在 Store 那一侧 ----
// 规矩不是「全程序不许有写语句」（自有数据当然要写），而是写语句只能待在
// 这两个文件里。别的地方一旦冒出 INSERT/UPDATE/CREATE TABLE，就说明有人
// 把写操作接到了主库那条线上。
$WRITE_OK = ['store.php', 'meat.php', 'stock.php'];
foreach ($phpFiles as $f) {
    $base = basename($f);
    if (in_array($base, ['selftest.php', 'db.php'], true)) {
        continue;                       // 自检脚本本身要写这些字符串来做测试
    }
    if (in_array($base, $WRITE_OK, true)) {
        continue;                       // 自有存储那一侧，允许写
    }
    $src = (string) file_get_contents($f);
    ok("{$base} 不含写库语句",
       !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE)\b/i', $src));
}
// 允许写的那两个文件，反过来必须完全不认识主库
foreach ($WRITE_OK as $base) {
    $src = (string) file_get_contents($ROOT . '/lib/' . $base);
    ok("{$base} 不引用主库的 Db::select", strpos($src, 'Db::select') === false);
}
ok('Db 不引用 Store', strpos($dbSrc, 'Store::') === false);

// ---- 铁律三：功能默认值必须在 settings.php，config.php 不许重复 ----
$defaults = require $ROOT . '/lib/settings.php';
$shipCfg  = require $ROOT . '/config.php';
$dup = array_intersect(array_keys($defaults), array_keys($shipCfg));
eq('随包 config.php 不重复功能参数', $dup, []);
// 每个页面读到的功能参数，都必须在 settings.php 里有默认值
$usedKeys = [];
foreach (['/index.php', '/dish.php', '/station.php', '/open.php', '/compare.php',
          '/lib/biz.php', '/lib/ack.php', '/lib/report.php', '/lib/view.php'] as $f) {
    // 只认 $cfg['x'] 和 config()['x']。用单引号字符串写正则，双引号里 \$ 的转义
    // 极易写错 —— 这条断言第一版就写成了 /\\$cfg/（$cfg 前面多一个反斜杠），
    // 永远匹配不到任何东西，等于没检查。用 . 匹配引号，省掉一层转义。
    if (preg_match_all('/\\$cfg\\[.([a-z_]+).\\]|config\\(\\)\\[.([a-z_]+).\\]/',
                       (string) file_get_contents($ROOT . $f), $m)) {
        foreach (array_merge($m[1], $m[2]) as $k) {
            if ($k !== '') { $usedKeys[$k] = true; }
        }
    }
}
$siteOnly = ['host', 'port', 'dbname', 'user', 'pass', 'charset', 'password'];
$missing = array_diff(array_keys($usedKeys), array_keys($defaults), $siteOnly);
eq('页面用到的功能参数都在 settings.php 里有默认值', array_values($missing), []);

// ---- 铁律四：时区必须显式设定 ----
ok('settings.php 有 timezone', array_key_exists('timezone', $defaults));
ok('读配置时会套用时区', strpos($dbSrc, 'date_default_timezone_set') !== false);

// ---- 铁律五：不许 JOIN ----
// 真去生成一遍 SQL 来查，不能写成 ok(..., true) 那种恒真的占位断言
[$jf, $jt] = Biz::range('2026-01-01', '2026-01-05');
$joinSqls = [
    '营业额'   => Biz::buildSalesSql($jf, $jt, 'history_order_head')[0],
    '菜品汇总' => Biz::buildDishTotalsSql($jf, $jt, 'history_order_detail')[0],
    '单菜品'   => Biz::buildDishByDaySql($jf, $jt, 'history_order_detail', 431)[0],
    '岗位单量' => Biz::buildStationSql($jf, $jt, 'history_order_detail', [1 => 11])[0],
    '开台列表' => Biz::buildOpenTablesSql(true)[0],
    '套餐酒水' => Biz::buildComboCountSql([1], [1890], [431])[0],
];
foreach ($joinSqls as $label => $sqlText) {
    ok("{$label} SQL 不含 JOIN", !preg_match('/\bjoin\b/i', $sqlText));
    ok("{$label} SQL 未对时间列套函数",
       !preg_match('/WHERE[^)]*\b(DATE|TIME|YEAR|MONTH)\s*\(\s*order_(start_)?time/i', $sqlText));
}

// ---- 铁律七：界面不能只靠颜色 ----
$cmpSrc0 = (string) file_get_contents($ROOT . '/compare.php');
ok('涨跌除颜色外还有箭头',
   strpos($cmpSrc0, '▲') !== false && strpos($cmpSrc0, '▼') !== false);
$cssSrc0 = (string) file_get_contents($ROOT . '/assets/app.css');
ok('输入框字号不低于 16px',
   preg_match('/input\[type=date\][^{]*\{[^}]*font-size:16px/s', $cssSrc0) === 1);

// ---- 数据文件落在网站可访问目录时必须报警 ----
// 铁律二第 4 条以前只写在文档里，实际部署踩过：宝塔那类面板的目录是
//   /www/wwwroot/站点/www/wwwroot/   ← 网站根
//   /www/wwwroot/站点/www/           ← 这层才在外面
// 程序放进网站根下面一层时，默认的「程序目录上一级」正好还在网站根里。
// 文档拦不住这种事，得让程序自己在页面上喊出来。
require_once $ROOT . '/lib/store.php';
$docSaved = $_SERVER['DOCUMENT_ROOT'] ?? null;
$tmpRoot  = sys_get_temp_dir() . '/selftest_doc_' . getmypid();
@mkdir($tmpRoot . '/sub/data', 0777, true);
@mkdir(dirname($tmpRoot) . '/outside_' . getmypid(), 0777, true);

$_SERVER['DOCUMENT_ROOT'] = $tmpRoot;
Db::forTests(['store_path' => $tmpRoot . '/sub/data/app.db']);
eq('数据文件在网站根里面 → 报警', Store::exposedUnder(), realpath($tmpRoot));
Db::forTests(['store_path' => $tmpRoot . '/data/app.db']);
@mkdir($tmpRoot . '/data', 0777, true);
eq('就在网站根那一层 → 也报警', Store::exposedUnder(), realpath($tmpRoot));
Db::forTests(['store_path' => dirname($tmpRoot) . '/outside_' . getmypid() . '/app.db']);
eq('放在网站根外面 → 不报警', Store::exposedUnder(), null);
// 名字前缀相同但不是子目录，不能误判（/var/www 与 /var/wwwdata）
@mkdir($tmpRoot . 'x/data', 0777, true);
Db::forTests(['store_path' => $tmpRoot . 'x/data/app.db']);
eq('只是名字前缀像，不算在里面', Store::exposedUnder(), null);
// 命令行等场景取不到网站根目录时不许误报
unset($_SERVER['DOCUMENT_ROOT']);
Db::forTests(['store_path' => $tmpRoot . '/sub/data/app.db']);
eq('取不到网站根目录时不误报', Store::exposedUnder(), null);

if ($docSaved !== null) { $_SERVER['DOCUMENT_ROOT'] = $docSaved; }
Db::forTests(null);
foreach ([$tmpRoot . '/sub/data', $tmpRoot . '/sub', $tmpRoot . '/data', $tmpRoot,
          $tmpRoot . 'x/data', $tmpRoot . 'x',
          dirname($tmpRoot) . '/outside_' . getmypid()] as $d) {
    @rmdir($d);
}

// ---- 网站根的第二个来源：SCRIPT_FILENAME 减去 SCRIPT_NAME ----
// nginx + PHP-FPM 某些配置下 DOCUMENT_ROOT 是空的。只认它的话，
// 「在不在网站里」永远判不出来，于是什么位置都被当成安全的 —— 静默放行。
$wr = sys_get_temp_dir() . '/selftest_wr_' . getmypid();
@mkdir($wr . '/wwwroot/app', 0777, true);
file_put_contents($wr . '/wwwroot/app/stock.php', '<?php');
eq('从脚本路径反推出网站根',
   Store::deriveWebRoot($wr . '/wwwroot/app/stock.php', '/app/stock.php'),
   realpath($wr . '/wwwroot'));
eq('程序就在网站根时也推得对',
   Store::deriveWebRoot($wr . '/wwwroot/app/stock.php', '/stock.php'),
   realpath($wr . '/wwwroot/app'));
// 推不出来就说不知道，别硬凑一个 —— 凑错了比不知道更糟
eq('对不上时返回 null',
   Store::deriveWebRoot($wr . '/wwwroot/app/stock.php', '/别的/路径.php'), null);
eq('相对的 SCRIPT_NAME 不硬猜',
   Store::deriveWebRoot($wr . '/wwwroot/app/stock.php', 'app/stock.php'), null);
eq('文件不存在时返回 null',
   Store::deriveWebRoot($wr . '/没有这个文件.php', '/x.php'), null);
eq('参数为空时返回 null', Store::deriveWebRoot('', ''), null);
@unlink($wr . '/wwwroot/app/stock.php');
foreach ([$wr . '/wwwroot/app', $wr . '/wwwroot', $wr] as $d) { @rmdir($d); }

// ---- 自动选址：面板类主机的目录形状（程序在网站根【下面一层】）----
// 这正是线上踩到的那种：老默认「程序目录上一级」算出来还在网站根里。
$panel = sys_get_temp_dir() . '/selftest_panel_' . getmypid();
@mkdir($panel . '/www/wwwroot/app/lib', 0777, true);
$_SERVER['DOCUMENT_ROOT'] = $panel . '/www/wwwroot';
// 假装程序就装在网站根下面一层 —— 这正是线上那台机器的形状
Store::useAppRootForTests($panel . '/www/wwwroot/app');
Db::forTests(['store_path' => '']);
Store::resetPathCache();
$auto = Store::path();
eq('面板形状下选到网站根的上一级',
   dirname($auto), $panel . '/www/' . Store::DIR_NAME);
eq('选出来的位置不在网站可访问目录里', Store::exposedUnder(), null);

// 首选位置被占住时，【不能】退而求其次选一个仍在网站目录里的地方。
// 这里用「同名文件挡路」来制造首选不可用 —— 比改权限可靠（测试可能以 root 跑，
// root 无视权限位，chmod 挡不住）。
$blocked = $panel . '/www/' . Store::DIR_NAME;
@rmdir($blocked);
file_put_contents($blocked, 'x');       // 变成文件，占住名字
Db::forTests(['store_path' => '']);
Store::resetPathCache();
$fallback = Store::path();
// 次选是「程序目录的上一级」，在这种目录形状下仍在网站根里面 —— 不许选它
// 次选是「程序目录的上一级」= 网站根本身，还在网站里 —— 不许选它
ok('首选被占住时不会退到仍在网站目录里的次选',
   dirname($fallback) !== $panel . '/www/wwwroot/' . Store::DIR_NAME);
eq('挑不出来时退到程序目录旁边（并报警）',
   dirname($fallback), $panel . '/www/wwwroot/app/' . Store::DIR_NAME);
$noteTxt = implode(' ', array_column(Store::notes(), 1));
ok('实在挑不出安全位置时会明确报警', strpos($noteTxt, '找不到网站访问不到的可写目录') !== false);
unlink($blocked);

// 显式配置优先，不自作主张改人家指定的路径
Db::forTests(['store_path' => $panel . '/我指定的/x.db']);
Store::resetPathCache();
eq('配了 store_path 就照办', Store::path(), $panel . '/我指定的/x.db');

// ---- 认库：不是本程序的文件就拒绝打开 ----
$foreign = $panel . '/foreign.db';
$fp = new PDO('sqlite:' . $foreign);
$fp->exec('CREATE TABLE wp_posts (id INTEGER PRIMARY KEY)');
$fp = null;
Db::forTests(['store_path' => $foreign]);
Store::resetPathCache();
$refClass = new ReflectionClass('Store');
$pdoProp  = $refClass->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue(null, null);
ok('别人的数据库不打开', !Store::isReady());
ok('说清了为什么不打开', strpos((string) Store::lastError(), '不是本程序的数据库') !== false);

// 自己建的库能认出来，而且带上身份标记
$mine = $panel . '/mine.db';
Db::forTests(['store_path' => $mine]);
Store::resetPathCache();
$pdoProp->setValue(null, null);
ok('自己的库正常打开', Store::isReady());
eq('写上了身份标记',
   Store::selectOne("SELECT v FROM app_meta WHERE k = 'app'")['v'], 'salesreport');
$pdoProp->setValue(null, null);

// ---- 接管老位置的数据：不搬的话升级后看着就像数据全没了 ----
$legacyDir = $panel . '/www/data';
@mkdir($legacyDir, 0777, true);
$legacy = $legacyDir . '/app.db';
// 用 Store 自己在老位置建一个【结构完整】的库，再塞一条数据。
// 手写一张只有两列的假表是不行的：接管之后要跑建表脚本，
// 建索引会因为缺列而失败 —— 那是测试数据太糙，不是程序的问题。
Db::forTests(['store_path' => $legacy]);
Store::resetPathCache();
$pdoProp->setValue(null, null);
Store::run("INSERT INTO meat_purchase (purchase_date, kind, weight_kg, created_at, updated_at)
            VALUES ('2026-01-01', 'salmon', 5, 't', 't')");
$pdoProp->setValue(null, null);

Db::forTests(['store_path' => '']);
Store::resetPathCache();
$pdoProp->setValue(null, null);
$target = Store::path();
@unlink($target);
ok('接管前新位置还没有文件', !is_file($target));
ok('打开时接管了老数据', Store::isReady());
eq('老数据搬过来了',
   (int) Store::selectOne('SELECT COUNT(*) c FROM meat_purchase')['c'], 1);
ok('老位置的文件已经不在了（它在网站能访问到的地方）', !is_file($legacy));
$msg = implode(' ', array_column(Store::notes(), 1));
ok('搬完告诉了人一声', strpos($msg, '搬到') !== false);
$pdoProp->setValue(null, null);

// 收尾
if ($docSaved !== null) { $_SERVER['DOCUMENT_ROOT'] = $docSaved; } else { unset($_SERVER['DOCUMENT_ROOT']); }
Store::useAppRootForTests(null);
Db::forTests(null);
Store::resetPathCache();
foreach ([$foreign, $mine, $target, $target . '-wal', $target . '-shm'] as $f) { @unlink($f); }
foreach ([dirname($target), $legacyDir, $panel . '/我指定的',
          $panel . '/www/wwwroot/app/lib', $panel . '/www/wwwroot/app',
          $panel . '/www/wwwroot', $panel . '/www', $panel] as $d) { @rmdir($d); }
Store::useMemoryForTests();

// 四个用到自有存储的页面都要报警 —— 漏掉一页，部署的人就可能一直以为没事
foreach (['meat.php', 'meatweek.php', 'stock.php', 'stocknow.php'] as $pg) {
    ok("{$pg} 会提示数据文件放错位置",
       strpos((string) file_get_contents($ROOT . '/' . $pg), 'storeBanner()') !== false);
}
ok('警告里写清了怎么改',
   strpos((string) file_get_contents($ROOT . '/lib/view.php'), 'store_path') !== false);
// WAL 模式下还有两个附属文件，只搬走主文件会丢最近的写入
$viewSrc = (string) file_get_contents($ROOT . '/lib/view.php');
ok('警告里提醒了 -wal / -shm 也要一起搬',
   strpos($viewSrc, '-wal') !== false && strpos($viewSrc, '-shm') !== false);
// 页面上要印出【实际用的路径】和安全判定 —— 光说「我挑了个安全位置」不够，
// 得让人一眼能核，这次就是因为看不见才来回折腾了好几轮
ok('页面印出数据文件的实际位置与判定', strpos($viewSrc, 'function storeWhere') !== false);
foreach (['meat.php', 'stock.php'] as $pg) {
    ok("{$pg} 印出了数据文件位置",
       strpos((string) file_get_contents($ROOT . '/' . $pg), 'storeWhere()') !== false);
}

// ---- 静态文件必须带缓存版本号 ----
// 少了它，用户浏览器会一直用缓存里的旧 app.css：新控件完全没样式，页面看着就是坏的，
// 而服务器端一点都看不出来。所有引用都得走 asset()，不许直接写死路径。
foreach ($phpFiles as $f) {
    $base = basename($f);
    if (in_array($base, ['selftest.php', 'view.php'], true)) {
        continue;                       // 自检本身和 asset() 的定义处要写这个字符串
    }
    $src = (string) file_get_contents($f);
    ok("{$base} 的静态文件引用带版本号",
       preg_match('/(?:href|src)="assets\//', $src) === 0);
}
require_once $ROOT . '/lib/view.php';
foreach (['assets/app.css', 'assets/app.js'] as $a) {
    ok("asset('{$a}') 带上了 ?v=", strpos(asset($a), $a . '?v=') === 0);
}
// 文件读不到时也不能退回「不带版本号」—— 那等于这道防线在最需要的时候失效
ok('文件不存在时仍然带版本号',
   strpos(asset('assets/这个文件不存在.css'), '?v=') !== false);
ok('asset_version 有默认值',
   array_key_exists('asset_version', (array) require $ROOT . '/lib/settings.php'));

// ---- 铁律八：手机与桌面共用同一个格式化函数 ----
$openSrc0 = (string) file_get_contents($ROOT . '/open.php');
ok('开台核对两套视图共用 $fmt',
   substr_count($openSrc0, '$fmt($r)') >= 2 && substr_count($openSrc0, '$fmt = static function') === 1);

// ---- 注意事项文档本身要在 ----
ok('注意事项.md 存在', is_file($ROOT . '/注意事项.md'));
$rules = (string) file_get_contents($ROOT . '/注意事项.md');
foreach (['绝对不碰主数据库', '自有数据和主库彻底分开', '配置分两层',
          '时区必须和 POS 一致', '每次只统计一张表', '不能只靠颜色'] as $kw) {
    ok("注意事项.md 写了「{$kw}」", strpos($rules, $kw) !== false);
}
ok('README 指向注意事项.md',
   strpos((string) file_get_contents($ROOT . '/README.md'), '注意事项.md') !== false);

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
echo "\n【2e2a】肉类采购记录（自有 SQLite）\n";
// =====================================================================

require_once __DIR__ . '/../lib/meat.php';
Store::useMemoryForTests();          // 用内存库，不碰真实数据文件

// 日期一律相对今天算。写死日期是个陷阱：写的时候是过去，跑到那天之后
// 就成了「未来」，被校验拦下，测试自己就红了（这段第一版就踩了）。
$dAgo = static fn(int $n) => date('Y-m-d', strtotime("-{$n} day"));

// ---- 校验：日期与品类必填 ----
$base = ['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'unit_count' => '3',
         'unit_type' => 'piece'];
[$c, $e] = Meat::validate($base);
eq('到货只填条数 → 通过', $e, []);
eq('清洗后日期规范化', $c['purchase_date'], $dAgo(9));
eq('没填重量就是 null，不是 0', $c['weight_kg'], null);

[, $e] = Meat::validate(['kind' => 'salmon', 'weight_kg' => '5']);
ok('缺日期被拒', isset($e['purchase_date']));
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'weight_kg' => '5']);
ok('缺品类被拒', isset($e['kind']));
[, $e] = Meat::validate(['purchase_date' => 'abc', 'kind' => 'salmon', 'weight_kg' => '5']);
ok('日期垃圾串被拒', isset($e['purchase_date']));
[, $e] = Meat::validate(['purchase_date' => date('Y-m-d', strtotime('+30 day')),
                         'kind' => 'salmon', 'weight_kg' => '5']);
ok('未来日期被拒（多半是年份打错）', isset($e['purchase_date']));
[, $e] = Meat::validate(['purchase_date' => date('Y-m-d', strtotime('-400 day')),
                         'kind' => 'salmon', 'weight_kg' => '5']);
eq('往回补录不受限（月底补录、翻旧发票）', $e, []);
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => '不存在', 'weight_kg' => '5']);
ok('品类不在清单里被拒', isset($e['kind']));

// ---- 重量与件数：至少一个 ----
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon']);
ok('重量件数都空被拒', isset($e['weight_kg']));
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon',
                         'weight_kg' => '', 'unit_count' => '']);
ok('都填空串也被拒', isset($e['weight_kg']));
[$c, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'weight_kg' => '8.5']);
eq('只填重量 → 通过', $e, []);
eq('只填重量时件数为 null', $c['unit_count'], null);
eq('只填重量时不带单位', $c['unit_type'], null);
[$c, $e] = Meat::validate(array_merge($base, ['weight_kg' => '12.6']));
eq('两个都填 → 通过', $e, []);
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'weight_kg' => '-1']);
ok('负重量被拒', isset($e['weight_kg']));
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'weight_kg' => '0']);
ok('0 重量被拒', isset($e['weight_kg']));
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'unit_count' => '3']);
ok('填了件数没选单位被拒', isset($e['unit_type']));

// 西语写法：1,5 应当认成 1.5
[$c, ] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'weight_kg' => '12,6']);
eq('逗号小数被认成 12.6', $c['weight_kg'], 12.6);
[$c, ] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon', 'weight_kg' => ' 8.5 ']);
eq('前后空格被去掉', $c['weight_kg'], 8.5);

// ---- 单价口径 ----
[, $e] = Meat::validate(array_merge($base, ['unit_price' => '10']));
ok('填了单价没选口径被拒', isset($e['price_basis']));
[, $e] = Meat::validate(array_merge($base, ['unit_price' => '10', 'price_basis' => 'kg']));
ok('按公斤计价却没填重量被拒', isset($e['price_basis']));
[, $e] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'salmon',
                         'weight_kg' => '10', 'unit_price' => '8', 'price_basis' => 'unit']);
ok('按件计价却没填件数被拒', isset($e['price_basis']));
[$c, $e] = Meat::validate(array_merge($base, ['weight_kg' => '12', 'unit_price' => '10',
                                              'price_basis' => 'kg']));
eq('按公斤计价 + 有重量 → 通过', $e, []);
eq('推算总价 = 单价 × 重量', Meat::derivedTotal($c), 120.0);
[$c, ] = Meat::validate(array_merge($base, ['unit_price' => '40', 'price_basis' => 'unit']));
eq('推算总价 = 单价 × 件数', Meat::derivedTotal($c), 120.0);
eq('没填单价时算不出总价', Meat::derivedTotal(['unit_price' => null]), null);

// ---- 待补发票 ----
ok('缺重量算待补发票', Meat::needsInvoice(['weight_kg' => null, 'total_price' => 10]));
ok('缺总价算待补发票', Meat::needsInvoice(['weight_kg' => 5, 'total_price' => null]));
ok('都齐了就不是待补', !Meat::needsInvoice(['weight_kg' => 5, 'total_price' => 10]));

// ---- 增删改与留痕 ----
[$c, ] = Meat::validate($base);
$id = Meat::create($c);
ok('新增成功', $id > 0);
eq('新增后能查到', Meat::find($id)['kind'], 'salmon');
eq('新增记了一条留痕', count(Meat::history($id)), 1);

[$c2, ] = Meat::validate(array_merge($base, ['weight_kg' => '12.6', 'total_price' => '151.2']));
ok('补齐发票信息', Meat::update($id, $c2));
eq('补齐后重量正确', (float) Meat::find($id)['weight_kg'], 12.6);
ok('补齐后不再是待补发票', !Meat::needsInvoice(Meat::find($id)));
eq('修改也留了痕', count(Meat::history($id)), 2);

ok('软删除成功', Meat::softDelete($id));
ok('软删后数据还在', Meat::find($id) !== null);
ok('软删后有作废时间', Meat::find($id)['deleted_at'] !== null);
ok('重复软删返回 false', !Meat::softDelete($id));
eq('默认列表不含已作废', count(Meat::listRows()), 0);
eq('勾了才看得到已作废', count(Meat::listRows(['with_deleted' => 1])), 1);
ok('能恢复', Meat::restore($id));
ok('恢复后不再是作废态', Meat::find($id)['deleted_at'] === null);
eq('作废与恢复都留了痕', count(Meat::history($id)), 4);
ok('改不存在的记录返回 false', !Meat::update(999999, $c2));

// ---- 平均条重：只用两个都填了的记录算 ----
Store::useMemoryForTests();
foreach ([[$dAgo(9), 12.0], [$dAgo(8), 13.5], [$dAgo(7), 11.4],
          [$dAgo(6), 30.0]] as [$d, $kg]) {
    [$cc, ] = Meat::validate(['purchase_date' => $d, 'kind' => 'salmon',
                              'weight_kg' => (string) $kg, 'unit_count' => '3',
                              'unit_type' => 'piece']);
    Meat::create($cc);
}
// 只填条数的那条不该参与算系数
[$cc, ] = Meat::validate(['purchase_date' => $dAgo(5), 'kind' => 'salmon',
                          'unit_count' => '2', 'unit_type' => 'piece']);
$estId = Meat::create($cc);

$uw = Meat::unitWeights();
ok('算出了三文鱼的条重', isset($uw['salmon']));
eq('样本数只算两个都填了的', $uw['salmon']['n'], 4);
// 每条 = 4.0 / 4.5 / 3.8 / 10.0 → 中位数 (4.0+4.5)/2 = 4.25
eq('用中位数，不被 30kg 那一批带跑', round($uw['salmon']['per'], 2), 4.25);
eq('范围下限', round($uw['salmon']['min'], 1), 3.8);
eq('范围上限', round($uw['salmon']['max'], 1), 10.0);

[$w, $est] = Meat::statWeight(Meat::find($estId), $uw);
ok('缺重量的记录被估算', $est);
eq('估算值 = 件数 × 中位数', round((float) $w, 2), 8.5);
[$w2, $est2] = Meat::statWeight(Meat::find(1), $uw);
ok('有实测重量的不估算', !$est2);
eq('实测重量原样返回', $w2, 12.0);

// 样本不足就不估算 —— 宁可报「缺重量」也不编数字
Store::useMemoryForTests();
[$cc, ] = Meat::validate(['purchase_date' => $dAgo(9), 'kind' => 'beef',
                          'weight_kg' => '10', 'unit_count' => '2', 'unit_type' => 'pack']);
Meat::create($cc);
[$cc, ] = Meat::validate(['purchase_date' => $dAgo(8), 'kind' => 'beef',
                          'unit_count' => '3', 'unit_type' => 'pack']);
$few = Meat::create($cc);
$uw2 = Meat::unitWeights();
eq('样本只有 1 条', $uw2['beef']['n'], 1);
[$w3, $est3] = Meat::statWeight(Meat::find($few), $uw2);
eq('样本不足时不估算', $w3, null);
ok('样本不足时也不标成估算', !$est3);
ok('阈值是 3 条', Meat::MIN_SAMPLES === 3);

// 单位对不上不估算（按「条」算的系数不能拿去折算「包」）
Store::useMemoryForTests();
foreach ([$dAgo(9), $dAgo(8), $dAgo(7)] as $d) {
    [$cc, ] = Meat::validate(['purchase_date' => $d, 'kind' => 'salmon',
                              'weight_kg' => '12', 'unit_count' => '3', 'unit_type' => 'piece']);
    Meat::create($cc);
}
[$cc, ] = Meat::validate(['purchase_date' => $dAgo(6), 'kind' => 'salmon',
                          'unit_count' => '2', 'unit_type' => 'pack']);
$mix = Meat::create($cc);
[$w4, ] = Meat::statWeight(Meat::find($mix), Meat::unitWeights());
eq('「包」不能拿「条」的系数折算', $w4, null);

// ---- 筛选 ----
Store::useMemoryForTests();
foreach ([[$dAgo(20), 'salmon'], [$dAgo(10), 'beef'], [$dAgo(2), 'salmon']] as [$d, $k]) {
    [$cc, ] = Meat::validate(['purchase_date' => $d, 'kind' => $k, 'weight_kg' => '5']);
    Meat::create($cc);
}
eq('按日期范围筛', count(Meat::listRows(['from' => $dAgo(5), 'to' => date('Y-m-d', strtotime('+5 day'))])), 1);
eq('按品类筛', count(Meat::listRows(['kind' => 'salmon'])), 2);
eq('列表按日期倒序', Meat::listRows()[0]['purchase_date'], $dAgo(2));
[$cc, ] = Meat::validate(['purchase_date' => $dAgo(1), 'kind' => 'beef', 'unit_count' => '2',
                          'unit_type' => 'pack']);
Meat::create($cc);
eq('只看待补发票', count(Meat::listRows(['only_pending' => 1])), 4);

// ---- 页面与铁律 ----
$meatSrc = (string) file_get_contents(__DIR__ . '/../meat.php');
ok('录入页要求登录', strpos($meatSrc, 'Auth::requireLogin()') !== false);
ok('写操作走 POST + CSRF', strpos($meatSrc, 'Auth::csrfValid') !== false);
ok('提交后跳转，避免刷新重复提交', strpos($meatSrc, "header('Location: '") !== false);
ok('作废前要二次确认', strpos($meatSrc, 'onsubmit="return confirm(') !== false);
ok('页面不碰主库', strpos($meatSrc, 'Db::select') === false);
ok('数据文件路径显示给用户看', strpos($meatSrc, 'Store::path()') !== false);
ok('数据目录不可写时给出明确提示', strpos($meatSrc, 'store_path') !== false);
// Report::dow 用到了，就必须 require —— 第一版漏了，页面渲染到一半直接 fatal
ok('用到 Report 就 require 了 report.php',
   strpos($meatSrc, 'Report::') === false
   || strpos($meatSrc, "require_once __DIR__ . '/lib/report.php'") !== false);

// =====================================================================
echo "\n【2e2a2】肉类周报表的聚合\n";
// =====================================================================

// ---- 周的边界：周一到周日（用户选的），跨年也不能错 ----
eq('周日归上一周', Meat::weekKey('2026-09-06'), '2026-36');
eq('周一开新的一周', Meat::weekKey('2026-09-07'), '2026-37');
eq('周的起止是周一到周日', Meat::weekRange('2026-36'), ['2026-08-31', '2026-09-06']);
// 跨年最容易写错：2021-01-01 是周五，属于 2020 年的第 53 周
eq('元旦可能属于上一年的最后一周', Meat::weekKey('2021-01-01'), '2020-53');
eq('跨年周的起止跨两个年份', Meat::weekRange('2020-53'), ['2020-12-28', '2021-01-03']);
eq('日期无法解析时返回空串', Meat::weekKey('乱写'), '');

$wk = Meat::recentWeeks(4, '2026-09-06');
eq('取近 4 周就是 4 个', count($wk), 4);
eq('最后一个是本周', $wk[3], '2026-36');
eq('第一个是三周前', $wk[0], '2026-33');

// ---- 按周汇总：实测重量和估算重量必须分开存 ----
$uwk = ['salmon' => ['unit' => 'piece', 'per' => 4.0, 'n' => 5, 'min' => 3.5, 'max' => 4.5]];
$rowsW = [
    // 同一周两笔三文鱼，一笔实测一笔只有条数
    ['purchase_date' => '2026-08-31', 'kind' => 'salmon', 'weight_kg' => 10.0,
     'unit_count' => null, 'unit_type' => null, 'total_price' => 150.0, 'deleted_at' => null],
    ['purchase_date' => '2026-09-02', 'kind' => 'salmon', 'weight_kg' => null,
     'unit_count' => 2.0, 'unit_type' => 'piece', 'total_price' => null, 'deleted_at' => null],
    // 牛肉：没有条重样本，缺重量 → 只能记成「缺重量」，不许编数字
    ['purchase_date' => '2026-09-02', 'kind' => 'beef', 'weight_kg' => null,
     'unit_count' => 1.0, 'unit_type' => 'pack', 'total_price' => 40.0, 'deleted_at' => null],
    // 上一周
    ['purchase_date' => '2026-08-25', 'kind' => 'salmon', 'weight_kg' => 6.0,
     'unit_count' => null, 'unit_type' => null, 'total_price' => 90.0, 'deleted_at' => null],
    // 已作废的不该进统计
    ['purchase_date' => '2026-08-25', 'kind' => 'salmon', 'weight_kg' => 99.0,
     'unit_count' => null, 'unit_type' => null, 'total_price' => 999.0, 'deleted_at' => '2026-08-26'],
];
$agg = Meat::weekly($rowsW, $uwk);
eq('归成两周', count($agg), 2);
eq('周按时间正序', array_keys($agg), ['2026-35', '2026-36']);
eq('实测重量单独算', $agg['2026-36']['kg'], 10.0);
eq('估算重量单独算（2 条 × 4kg）', $agg['2026-36']['kg_est'], 8.0);
eq('记下有几笔是估算的', $agg['2026-36']['est_rows'], 1);
eq('缺重量的单独计数', $agg['2026-36']['no_kg'], 1);
eq('缺总价的单独计数', $agg['2026-36']['no_money'], 1);
eq('金额只算填了总价的', $agg['2026-36']['money'], 190.0);
eq('按品类也分开', round($agg['2026-36']['kinds']['salmon']['kg_est'], 1), 8.0);
eq('作废的不进统计', $agg['2026-35']['kg'], 6.0);
eq('作废的金额也不进', $agg['2026-35']['money'], 90.0);

// ---- 滚动平均 ----
$roll = Meat::rolling(['a' => 10.0, 'b' => 20.0, 'c' => 30.0, 'd' => 40.0, 'e' => 50.0], 3);
eq('不足窗口时用已有的几周', array_values($roll), [10.0, 15.0, 20.0, 30.0, 40.0]);
// null = 那一周没数据。当成 0 会把均值拉低，下一周就成了假的「暴涨」
$rollN = Meat::rolling(['a' => 10.0, 'b' => null, 'c' => 20.0], 3);
eq('没数据的周被跳过而不是当 0', $rollN['c'], 15.0);
eq('窗口内全没数据就返回 null', Meat::rolling(['a' => null, 'b' => null], 2)['b'], null);

// ---- 页面：两边分别查、内存里合并，不许 JOIN ----
$mwSrc = (string) file_get_contents(__DIR__ . '/../meatweek.php');
ok('周报表要求登录', strpos($mwSrc, 'Auth::requireLogin()') !== false);
ok('周报表不写任何东西', preg_match('/\b(INSERT|UPDATE|DELETE|CREATE)\b/i', $mwSrc) === 0);
ok('自有数据走 Store', strpos($mwSrc, 'Meat::listRows') !== false);
ok('主库只走 Biz 的只读查询', strpos($mwSrc, 'Biz::salesByDay') !== false);
// 页面自己一句 SQL 都不写，查询全在 Biz（主库只读）和 Meat（自有库）里 ——
// 没有 SQL 就没有把两边写进同一条语句的机会
ok('周报表页自己不拼 SQL', strpos($mwSrc, 'SELECT ') === false);
// 主库挂了不该连采购数据一起看不见 —— 采购是自有的
ok('主库失败被单独捕获', strpos($mwSrc, 'catch (Throwable $e)') !== false
   && strpos($mwSrc, '$posErr') !== false);
ok('数字列表头带 class="n"', preg_match('/<th>(合计 kg|采购额|人均 g|占营业额)/u', $mwSrc) === 0);
// 采购 ≠ 消耗：这句必须留在页面上，不然看的人会把进货节奏当成浪费
ok('页面写明统计的是采购量不是消耗量',
   strpos($mwSrc, '不是「实际消耗量」') !== false);

// =====================================================================
echo "\n【2e2a3】发票导入（xlsx / csv → 采购表）\n";
// =====================================================================

require_once __DIR__ . '/../lib/meatimport.php';
Store::useMemoryForTests();

/** 拿 CSV 当测试样本：内容一眼能看懂，改起来也不用生成 zip */
$csv = static function (array $lines): string {
    $f = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
    file_put_contents($f, implode("\n", $lines));
    return $f;
};

// ---- 按表头文字认列，不是按第几列 ----
// 两份真实文件的列顺序就不一样。写死列号的话，换一份文件全部错位，
// 而且错位是【静默】的：数字照样进库，只是全填进了错的字段。
$f1 = $csv([
    '类别;送货日期;净重;未税金额;税率;发票号;中文名称;类型',
    'Salmón;2026-03-10;10;100;10;A-1;三文鱼整条;Compra',
    'Atún;2026-03-11;5;60;10;A-2;金枪鱼;Compra',
]);
$p1 = MeatImport::parse($f1, 'x.csv');
eq('日期认到了第 2 列', $p1['map']['date'], 'B');
eq('重量认到了第 3 列', $p1['map']['weight'], 'C');
eq('品类认到了第 1 列', $p1['map']['kind'], 'A');
eq('未税金额 + 税率 → 折算口径', $p1['basis'], 'computed');
eq('两条都能导', $p1['summary']['ok'], 2);
// 100 × 1.10 + 60 × 1.10 = 176：存的是【含税】，与 POS 实收同口径
eq('金额折算成含税', round($p1['summary']['money'], 2), 176.0);
eq('重量合计', round($p1['summary']['kg'], 3), 15.0);
eq('均价 = 含税金额 ÷ 公斤', round($p1['summary']['per_kg'], 4), round(176 / 15, 4));
ok('没有误报', $p1['summary']['warn'] === []);

// ---- 「单价」不能被当成「金额」----
// 这个坑在真实文件上踩过：「含税单价」被认成「含税金额」，
// 合计从两万变成七百八 —— 行数、日期全对，只有金额悄悄换了一列。
$f2 = $csv([
    '类别;送货日期;净重;含税单价;含税金额;类型',
    'Salmón;2026-03-10;10;11,50;115;Compra',
]);
$p2 = MeatImport::parse($f2, 'x.csv');
eq('含税金额认的是「金额」列不是「单价」列', $p2['map']['money_inc'], 'E');
eq('金额是行合计', round($p2['summary']['money'], 2), 115.0);

// ---- 照着真实文件的表头形状认列 ----
// 这一段用的是发票文件里【原样】的双语表头。它里面全是陷阱：
//   「Invoice date / 发票日期」里有 invoice —— 认成发票号就把日期写进备注
//   「Unit price ex VAT / 未税单价」里有 VAT   —— 认成税率就会拿单价当税率去折算
//   「含税单价」和「含税金额」只差一个字     —— 认错了金额直接差一个数量级
// 这些都不会报错，只会静默地把另一列的数存进来。排除词就是拦这个的。
$real = $csv([
    'Delivery date / 送货日期;Invoice date / 发票日期;Supplier / 供货商;'
    . 'Invoice / 发票号;Type / 类型;Category / 类别;Weight / 重量(kg);'
    . 'Unit price ex VAT / 未税单价;VAT / 税率;Unit price incl VAT / 含税单价;'
    . 'Line amount ex VAT / 未税金额;Line amount incl VAT / 含税金额',
    '2026-03-10;2026-03-12;Pescados Gaizka;M/960;Purchase;金枪鱼 / Atún;'
    . '15,89;14,85;0,1;16,335;235,97;259,57',
]);
$pr = MeatImport::parse($real, 'x.csv');
eq('送货日期认到 A 列（不是发票日期）',   $pr['map']['date'],      'A');
eq('发票号认的是发票号，不是发票日期',    $pr['map']['invoice'],   'D');
eq('类别认到 F 列',                       $pr['map']['kind'],      'F');
eq('重量认到 G 列',                       $pr['map']['weight'],    'G');
eq('税率认的是税率，不是「未税单价」',    $pr['map']['vat'],       'I');
eq('含税金额认的是金额，不是含税单价',    $pr['map']['money_inc'], 'L');
eq('未税金额认到 K 列',                   $pr['map']['money'],     'K');
eq('供货商认到 C 列',                     $pr['map']['supplier'],  'C');
eq('文件给了含税金额就直接用',            $pr['basis'],            'incl');
// 259.57 是这一行的含税合计；认成含税单价（16.335）就会差一个数量级
eq('金额取的是行合计', round((float) $pr['rows'][0]['clean']['total_price'], 2), 259.57);
eq('重量取的是 kg', round((float) $pr['rows'][0]['clean']['weight_kg'], 2), 15.89);
ok('备注里写的是发票号，不是日期',
   strpos((string) $pr['rows'][0]['clean']['note'], 'M/960') !== false);

// ---- 均价离谱 = 认错列的报警器 ----
// 这是唯一能【自动】发现认错列的信号：行数和日期看不出问题，
// 但把单价当金额算出来的均价会离谱到一眼可见。
$f4 = $csv([
    '类别;送货日期;净重;含税金额',
    'Salmón;2026-03-10;100;5',        // 0.05 €/kg
]);
$p4 = MeatImport::parse($f4, 'x.csv');
ok('均价太低会报警', $p4['summary']['warn'] !== []);
ok('报警说清了怀疑认错列',
   strpos(implode(' ', $p4['summary']['warn']), '认错了列') !== false);
$f5 = $csv([
    '类别;送货日期;净重;含税金额',
    'Salmón;2026-03-10;1;5000',       // 5000 €/kg
]);
ok('均价太高也会报警', MeatImport::parse($f5, 'x.csv')['summary']['warn'] !== []);
$sane = MeatImport::sanePerKg();
ok('合理区间是个真区间', $sane[0] > 0 && $sane[1] > $sane[0]);

// ---- 退货行：跳过并列出来，绝不做抵扣 ----
// 悄悄扣掉会让合计和发票对不上，而对不上的时候没人知道是哪里扣的。
$f6 = $csv([
    '类别;送货日期;净重;含税金额;类型',
    'Salmón;2026-03-10;10;115;Compra',
    'Salmón;2026-03-15;-4;-46;Return',
]);
$p6 = MeatImport::parse($f6, 'x.csv');
eq('退货行不算进可导入', $p6['summary']['ok'], 1);
eq('退货行被跳过', $p6['summary']['skip'], 1);
ok('跳过的原因写给人看', strpos((string) $p6['rows'][1]['skip'], '退货') !== false);
eq('金额没有被退货抵掉', round($p6['summary']['money'], 2), 115.0);

// 只有负数、没写 Return 的行也要拦住（数据库 CHECK 也不收负数）。
// 光看「跳过了几条」不够 —— 通用校验（重量要大于 0）顺手也会挡下它，
// 于是把「认出这是退货」这条检查演成一个永远通过的空检查。要看【原因】。
$f7 = $csv(['类别;送货日期;净重;含税金额', 'Salmón;2026-03-10;-4;-46']);
$p7 = MeatImport::parse($f7, 'x.csv');
eq('光是负数也跳过', $p7['summary']['ok'], 0);
ok('而且认出这是退货／负数行，不是笼统的「校验没过」',
   strpos((string) $p7['rows'][0]['skip'], '退货／负数') !== false,
   '实际原因：' . (string) $p7['rows'][0]['skip']);
// 金额为负、重量正常的行同样要被认成退货
$f7b = $csv(['类别;送货日期;净重;含税金额', 'Salmón;2026-03-10;4;-46']);
ok('金额为负也认成退货行',
   strpos((string) MeatImport::parse($f7b, 'x.csv')['rows'][0]['skip'], '退货／负数') !== false);

// ---- 认不出的东西宁可跳过，也不猜 ----
$f8 = $csv([
    '类别;送货日期;净重;含税金额',
    'Pulpo;2026-03-10;10;115',        // 品类清单里没有
    'Salmón;不是日期;10;115',
    'Salmón;2026-03-10;;115',         // 没重量
    'Salmón;2026-03-10;abc;115',
]);
$p8 = MeatImport::parse($f8, 'x.csv');
eq('四条都跳过，一条都不猜', $p8['summary']['ok'], 0);
eq('跳过四条', $p8['summary']['skip'], 4);
ok('认不出品类说清楚了', strpos((string) $p8['rows'][0]['skip'], '认不出品类') !== false);
ok('日期读不出说清楚了', strpos((string) $p8['rows'][1]['skip'], '日期') !== false);
ok('缺重量说清楚了', strpos((string) $p8['rows'][2]['skip'], '重量') !== false);
eq('认不出的品类返回 null', MeatImport::kind('Pulpo'), null);
eq('西语带重音认得出', MeatImport::kind('Atún'), 'atun');
eq('中文认得出', MeatImport::kind('三文鱼整条'), 'salmon');
eq('直接写代码也认', MeatImport::kind('beef'), 'beef');

// ---- 认不出表头就直接说，不硬凑 ----
throws('认不出表头会报错', static function () use ($csv) {
    MeatImport::parse($csv(['随便;写点;什么', '1;2;3']), 'x.csv');
});

// ---- 数字：西语的逗号小数和千分位 ----
$f9 = $csv([
    '类别;送货日期;净重;含税金额',
    'Salmón;2026-03-10;12,5;1.234,50',
]);
$p9 = MeatImport::parse($f9, 'x.csv');
eq('逗号当小数点', (float) $p9['rows'][0]['clean']['weight_kg'], 12.5);
eq('点是千分位', (float) $p9['rows'][0]['clean']['total_price'], 1234.5);

// ---- 文件内部的完全重复行 ----
$f10 = $csv([
    '类别;送货日期;净重;含税金额;发票号',
    'Salmón;2026-03-10;10;115;A-1',
    'Salmón;2026-03-10;10;115;A-1',
]);
$p10 = MeatImport::parse($f10, 'x.csv');
eq('文件里重复的行只导一条', $p10['summary']['ok'], 1);
ok('说清了和第几行重复', strpos((string) $p10['rows'][1]['skip'], '重复') !== false);
// 同一天进两批【不同规格】不算重复 —— 只用「日期+品类」做键就会误杀
$f11 = $csv([
    '类别;送货日期;净重;含税金额;发票号',
    'Salmón;2026-03-10;10;115;A-1',
    'Salmón;2026-03-10;8;92;A-1',
]);
eq('同一天不同批次不算重复', MeatImport::parse($f11, 'x.csv')['summary']['ok'], 2);

// ---- 导入：整批一个事务，重复导入不会翻倍 ----
Store::useMemoryForTests();
$items = static function (array $res): array {
    $out = [];
    foreach ($res['rows'] as $r) {
        if ($r['clean'] !== null) { $out[] = ['clean' => $r['clean'], 'key' => $r['key']]; }
    }
    return $out;
};
$r1 = Meat::createMany($items($p1));
eq('第一次导入进了两条', $r1['inserted'], 2);
$r2 = Meat::createMany($items($p1));
eq('同一批再导一次，一条都不进', $r2['inserted'], 0);
eq('而且如实报出重复条数', $r2['duplicate'], 2);
eq('库里还是两条',
   (int) Store::selectOne('SELECT COUNT(*) c FROM meat_purchase')['c'], 2);
eq('每条都有留痕', (int) Store::selectOne(
   "SELECT COUNT(*) c FROM meat_purchase_log WHERE action = 'import'")['c'], 2);

// 手工录入的行 import_key 是 NULL；NULL 之间不算冲突，多少条都行
Meat::create(Meat::validate(['purchase_date' => '2026-03-01', 'kind' => 'salmon',
                             'weight_kg' => '3'])[0]);
Meat::create(Meat::validate(['purchase_date' => '2026-03-02', 'kind' => 'salmon',
                             'weight_kg' => '4'])[0]);
eq('手工录入不受唯一索引影响',
   (int) Store::selectOne('SELECT COUNT(*) c FROM meat_purchase')['c'], 4);
eq('手工录入的 import_key 为空', (int) Store::selectOne(
   'SELECT COUNT(*) c FROM meat_purchase WHERE import_key IS NULL')['c'], 2);

// 唯一索引是最后一道防线：绕过 createMany 直接插重复指纹也要被拦住
throws('数据库层拦得住重复指纹', static function () {
    $k = Store::selectOne('SELECT import_key FROM meat_purchase
                           WHERE import_key IS NOT NULL')['import_key'];
    Store::run("INSERT INTO meat_purchase
                  (purchase_date, kind, weight_kg, import_key, created_at, updated_at)
                VALUES ('2026-03-01', 'salmon', 1, :k, 't', 't')", [':k' => $k]);
});

// 整批一个事务：中间有一条写不进去，前面已经写进去的也不许留下。
// 第二条要【骗过入口校验、死在数据库那一层】—— 否则异常在事务外面就抛了，
// 根本没进事务，也就测不到回滚，更测不到「错误有没有被吞掉」。
// weight_kg = -5 正好：assertClean 只看「重量和件数至少有一个」，放它过；
// 数据库的 CHECK (weight_kg > 0) 才拦下来。
Store::useMemoryForTests();
throws('批里有一条写不进去，异常要抛出来（不能悄悄吞掉）', static function () {
    Meat::createMany([
        ['clean' => Meat::validate(['purchase_date' => '2026-03-01', 'kind' => 'salmon',
                                    'weight_kg' => '3'])[0], 'key' => 'k1'],
        ['clean' => ['purchase_date' => '2026-03-02', 'kind' => 'salmon',
                     'weight_kg' => -5, 'unit_count' => null, 'unit_type' => null,
                     'price_basis' => null, 'unit_price' => null, 'total_price' => null,
                     'supplier' => null, 'note' => null], 'key' => 'k2'],
    ]);
});
eq('回滚之后一条都没留下（包括前面那条好的）',
   (int) Store::selectOne('SELECT COUNT(*) c FROM meat_purchase')['c'], 0);
// 入口校验也还得在：连必填字段都没有的东西，不该等到数据库才发现
throws('缺必填字段在入口就挡下', static function () {
    Meat::createMany([['clean' => ['purchase_date' => '', 'kind' => ''], 'key' => 'k3']]);
});

// ---- 老库要能补上 import_key 这一列 ----
// CREATE TABLE IF NOT EXISTS 碰到已存在的表什么都不做，
// 所以升级之后老库不会自己长出新列 —— 少了这段，页面会报 no such column，
// 而开发机上因为库是新建的，怎么试都是好的。
$oldDb = tempnam(sys_get_temp_dir(), 'oldschema') . '.db';
$op = new PDO('sqlite:' . $oldDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$op->exec('CREATE TABLE meat_purchase (
             id INTEGER PRIMARY KEY AUTOINCREMENT, purchase_date TEXT NOT NULL,
             kind TEXT NOT NULL, weight_kg REAL, unit_count REAL, unit_type TEXT,
             price_basis TEXT, unit_price REAL, total_price REAL, supplier TEXT,
             note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
             deleted_at TEXT)');
$op->exec("INSERT INTO meat_purchase (purchase_date, kind, weight_kg, created_at, updated_at)
           VALUES ('2026-02-01', 'salmon', 7, 't', 't')");
$op = null;
$sRef = new ReflectionClass('Store');
$sPdo = $sRef->getProperty('pdo');
$sPdo->setAccessible(true);
$sPdo->setValue(null, null);
Db::forTests(['store_path' => $oldDb]);
Store::resetPathCache();
ok('老库打得开（认作 legacy）', Store::isReady());
$cols = array_column(Store::select('PRAGMA table_info(meat_purchase)'), 'name');
ok('老库补上了 import_key 列', in_array('import_key', $cols, true));
eq('老数据一条没少',
   (int) Store::selectOne('SELECT COUNT(*) c FROM meat_purchase')['c'], 1);
$r3 = Meat::createMany($items($p1));
eq('补完列就能正常导入', $r3['inserted'], 2);
$sPdo->setValue(null, null);
Db::forTests(null);
Store::resetPathCache();
foreach ([$oldDb, $oldDb . '-wal', $oldDb . '-shm'] as $x) { @unlink($x); }
foreach ([$f1, $f2, $real, $f4, $f5, $f6, $f7, $f7b, $f8, $f9, $f10, $f11] as $x) { @unlink($x); }
Store::useMemoryForTests();

// ---- 页面：核对这一步不能省 ----
$miSrc = (string) file_get_contents($ROOT . '/meatimport.php');
ok('上传后先预览，不直接入库',
   strpos($miSrc, "act === 'upload'") !== false && strpos($miSrc, "act === 'import'") !== false);
ok('上传和导入是两次提交', substr_count($miSrc, "name=\"act\"") >= 3);
ok('校验 CSRF', strpos($miSrc, 'Auth::csrfValid') !== false);
ok('要求登录', strpos($miSrc, 'Auth::requireLogin') !== false);
// 只搜函数名会被【注释里提了一句】蒙混过去 —— 要搜真正的调用
ok('确认上传的确是这次传上来的文件',
   preg_match('/if\s*\(\s*!\s*is_uploaded_file\s*\(/', $miSrc) === 1);
ok('限制文件类型', strpos($miSrc, 'xlsx|csv') !== false);
ok('限制文件大小', strpos($miSrc, 'Xlsx::MAX_BYTES') !== false);
ok('把认到的列摆出来给人核', strpos($miSrc, '认出来的列') !== false);
ok('把均价摆出来（认错列的报警器）', strpos($miSrc, 'per_kg') !== false);
ok('预览和导入必须是同一批', strpos($miSrc, 'stamp') !== false);
ok('入库前二次确认', strpos($miSrc, 'onsubmit="return confirm(') !== false);
ok('页面不碰主库', strpos($miSrc, 'Db::select') === false);
ok('页面自己不拼 SQL', strpos($miSrc, 'SELECT ') === false);
ok('上传的文件不落盘', strpos($miSrc, 'move_uploaded_file') === false);
ok('用到 Report 就 require 了 report.php',
   strpos($miSrc, 'Report::') === false
   || strpos($miSrc, "require_once __DIR__ . '/lib/report.php'") !== false);
// 三个子页要互相通得到，漏一个就等于这个功能不存在
foreach (['meat.php', 'meatweek.php', 'meatimport.php'] as $pg) {
    ok("{$pg} 的子标签里有发票导入",
       strpos((string) file_get_contents($ROOT . '/' . $pg), 'meatimport.php') !== false);
}

// ---- Excel 的日期序号 ----
eq('Excel 序号 45000 = 2023-03-15', Xlsx::toDate(45000), '2023-03-15');
eq('Excel 序号 61 = 1900-03-01', Xlsx::toDate(61), '1900-03-01');
eq('文本日期也认', Xlsx::toDate('2026-03-10'), '2026-03-10');
eq('不是日期就返回 null', Xlsx::toDate('abc'), null);
eq('超出合理范围的序号不当日期', Xlsx::toDate(999999), null);
// 1–60 落在 Excel 那个「1900 年有 2 月 29 日」的错误区间里，算出来会差一天。
// 与其给个差一天的日期，不如说不知道 —— 「件数」那种列里的小数字正好在这段。
eq('小数字不当日期（差一天区间）', Xlsx::toDate(1), null);
eq('60 也不当日期', Xlsx::toDate(60), null);
ok('xlsx 解析禁掉了外部实体（XXE）',
   strpos((string) file_get_contents($ROOT . '/lib/xlsx.php'), 'LIBXML_NONET') !== false);

// =====================================================================
echo "\n【2e2c】库存（存入 / 盘点 / 用量推算）\n";
// =====================================================================

require_once __DIR__ . '/../lib/stock.php';
Store::useMemoryForTests();

$sIn = static function (string $d, string $t, string $item, string $kind, string $q,
                        ?string $moment = null) {
    [$c, $e] = Stock::validate(['happened_date' => $d, 'happened_time' => $t, 'item' => $item,
                                'move_kind' => $kind, 'qty' => $q, 'moment' => $moment]);
    if ($e) {
        throw new RuntimeException('测试数据自己就没过校验：' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    return Stock::create($c);
};

// ---- 校验 ----
$base = ['happened_date' => $dAgo(1), 'happened_time' => '23:30',
         'item' => 'salmon_fillet', 'move_kind' => 'count', 'qty' => '4'];
[$c, $e] = Stock::validate($base);
eq('完整的一条能过', $e, []);
eq('日期和时间合成时刻', $c['happened_at'], $dAgo(1) . ' 23:30');

// 动作故意不给默认值：存入是「加上去」，盘点是「就是这么多」，选反了用量算错方向
[, $e] = Stock::validate(array_merge($base, ['move_kind' => '']));
ok('不选动作被拒', isset($e['move_kind']));
// 传空串和【压根没传】是两回事：给个默认值的话，表单少了这个字段就会
// 悄悄按默认动作存下去，用量整段算反还没人知道
$noKind = $base;
unset($noKind['move_kind']);
[, $e] = Stock::validate($noKind);
ok('压根没传动作也被拒（不许有默认动作）', isset($e['move_kind']));
[, $e] = Stock::validate(array_merge($base, ['move_kind' => 'out']));
ok('乱造的动作被拒', isset($e['move_kind']));

[, $e] = Stock::validate(array_merge($base, ['happened_time' => '']));
ok('不填时间被拒（一天可能盘好几次）', isset($e['happened_time']));
[, $e] = Stock::validate(array_merge($base, ['happened_time' => '25:00']));
ok('小时超范围被拒', isset($e['happened_time']));
[, $e] = Stock::validate(array_merge($base, ['happened_time' => '12:70']));
ok('分钟超范围被拒', isset($e['happened_time']));
[$c, ] = Stock::validate(array_merge($base, ['happened_time' => '9:05']));
eq('个位小时补零', $c['happened_at'], $dAgo(1) . ' 09:05');

[, $e] = Stock::validate(array_merge($base,
    ['happened_date' => date('Y-m-d', strtotime('+30 day'))]));
ok('未来日期被拒', isset($e['happened_date']));
[, $e] = Stock::validate(array_merge($base, ['item' => '不存在']));
ok('品类不在清单里被拒', isset($e['item']));
[, $e] = Stock::validate(array_merge($base, ['qty' => '']));
ok('不填数量被拒', isset($e['qty']));
[, $e] = Stock::validate(array_merge($base, ['qty' => '-1']));
ok('负数量被拒', isset($e['qty']));
// 盘点 0 是有意义的（数完发现空了），存入 0 等于什么都没做
[, $e] = Stock::validate(array_merge($base, ['qty' => '0']));
eq('盘点 0 可以', $e, []);
[, $e] = Stock::validate(array_merge($base, ['move_kind' => 'in', 'qty' => '0']));
ok('存入 0 被拒', isset($e['qty']));
[$c, ] = Stock::validate(array_merge($base, ['qty' => '2,5']));
eq('西语逗号小数被识别', $c['qty'], 2.5);
[, $e] = Stock::validate(array_merge($base, ['moment' => '不存在的时点']));
ok('时点不在清单里被拒', isset($e['moment']));

// ---- 用户给的那个例子：4 箱 → 存入 3 → 盘点 5 → 用掉 2 ----
Store::useMemoryForTests();
$sIn('2026-09-05', '23:30', 'salmon_fillet', 'count', '4', 'dinner_end');
$sIn('2026-09-06', '11:00', 'salmon_fillet', 'in',    '3');
$sIn('2026-09-06', '23:30', 'salmon_fillet', 'count', '5', 'dinner_end');
$ps = Stock::periods(Stock::seriesFor('salmon_fillet'));
eq('两次盘点之间算一段', count($ps), 1);
eq('取出 = 上次盘点 + 期间存入 − 本次盘点', $ps[0]['used'], 2.0);
eq('段的起点是上次盘点', $ps[0]['from'], '2026-09-05 23:30');
eq('段的终点是本次盘点', $ps[0]['to'], '2026-09-06 23:30');
eq('段长 24 小时', round($ps[0]['hours']), 24);
ok('用量为正，不算漏记', !$ps[0]['negative']);

$cur = Stock::current()['salmon_fillet'];
eq('最近盘点数', $cur['last_qty'], 5.0);
eq('盘点后没再存入', $cur['since_in'], 0.0);
eq('账面 = 最近盘点 + 之后存入', $cur['book'], 5.0);
eq('单位跟着品类走', $cur['unit'], '箱');

// 盘点之后又存入，账面要跟着涨（但那只是上限，之后用掉的没人记）
$sIn('2026-09-07', '11:00', 'salmon_fillet', 'in', '2');
$cur = Stock::current()['salmon_fillet'];
eq('盘点后的存入计入账面', $cur['since_in'], 2.0);
eq('账面上限 = 5 + 2', $cur['book'], 7.0);
eq('还是只算了一段（没有新盘点）', count($cur['periods']), 1);

// ---- 一天盘好几次 → 能算出餐期用量 ----
Store::useMemoryForTests();
$sIn('2026-09-06', '11:00', 'beef', 'count', '10', 'arrive');
$sIn('2026-09-06', '16:30', 'beef', 'count', '7',  'lunch_end');
$sIn('2026-09-06', '19:30', 'beef', 'in',    '5');
$sIn('2026-09-06', '23:30', 'beef', 'count', '4',  'dinner_end');
$ps = Stock::periods(Stock::seriesFor('beef'));
eq('同一天分成两段', count($ps), 2);
eq('午市用量 10 − 7 = 3', $ps[0]['used'], 3.0);
eq('晚市用量 7 + 5 − 4 = 8', $ps[1]['used'], 8.0);
eq('晚市那段记下了期间存入', $ps[1]['in'], 5.0);
eq('段带着时点标签', [$ps[0]['from_moment'], $ps[0]['to_moment']], ['arrive', 'lunch_end']);

// ---- 盘出来比账面还多 = 漏记了存入，不能悄悄当 0 ----
Store::useMemoryForTests();
$sIn('2026-09-06', '11:00', 'salmon_skin', 'count', '2', 'arrive');
$sIn('2026-09-06', '23:30', 'salmon_skin', 'count', '6', 'dinner_end');
$ps = Stock::periods(Stock::seriesFor('salmon_skin'));
eq('用量算成负数', $ps[0]['used'], -4.0);
ok('负数被标出来让人回去补', $ps[0]['negative']);

// ---- 作废的记录不参与结存 ----
Store::useMemoryForTests();
$sIn('2026-09-05', '23:30', 'beef', 'count', '10');
$badIn = $sIn('2026-09-06', '11:00', 'beef', 'in', '99');
$sIn('2026-09-06', '23:30', 'beef', 'count', '8');
eq('作废之前：10 + 99 − 8', Stock::periods(Stock::seriesFor('beef'))[0]['used'], 101.0);
Stock::softDelete($badIn);
eq('作废之后那笔存入不算了', Stock::periods(Stock::seriesFor('beef'))[0]['used'], 2.0);
Stock::restore($badIn);
eq('恢复后又算回来', Stock::periods(Stock::seriesFor('beef'))[0]['used'], 101.0);
eq('增改删都留了痕', count(Stock::history($badIn)), 3);

// ---- 只存入没盘过：算不出用量，也不能假装知道库存 ----
Store::useMemoryForTests();
$sIn('2026-09-06', '11:00', 'salmon_mince', 'in', '5');
$cur = Stock::current()['salmon_mince'];
ok('没盘过就标成没盘过', !$cur['counted']);
eq('没盘过时算不出账面', $cur['book'], null);
eq('没盘过时没有分段', count($cur['periods']), 0);
eq('存入总量还是记着的', $cur['in_total'], 5.0);

// ---- 盘点进度：一条一条录，最容易漏项 ----
Store::useMemoryForTests();
$sIn('2026-09-06', '23:30', 'salmon_fillet', 'count', '5');
$pg = Stock::countProgress('2026-09-06 23:30');
eq('这一轮盘了 1 项', count($pg['done']), 1);
// 只数「要盘的」品类 —— 走存入即用量的那些本来就不参与盘点
$needCount = count(array_filter(Stock::items(),
    static fn($m) => $m['mode'] === Stock::MODE_COUNT));
eq('还差的项数 = 要盘的品类数 − 已盘', count($pg['missing']), $needCount - 1);
eq('没有盘点的时刻返回 null', Stock::countProgress('2026-09-06 09:00'), null);

// ---- 品类清单与单位 ----
ok('品类清单读得到', count(Stock::items()) > 0);
eq('每个品类各自固定一个单位', Stock::itemUnit('salmon_fillet'), '箱');
eq('时点有显示名', Stock::momentLabel('lunch_end'), '午市后');
eq('未知时点不炸', Stock::momentLabel('不存在'), '');
eq('未知品类退回代码本身', Stock::itemLabel('不存在'), '不存在');

// ---- 混合口径：数不清的品类走「存入即用量」 ----
// Atún 切成大小不一的小块，盘不出「还剩几块」。硬盘只会盘出假数字，
// 所以这类品类只记存入、进多少算用多少。
$mixItems = [
    'boxed' => ['name' => '盒装货', 'unit' => '盒'],                        // 不写 mode = 盘点法
    'atun'  => ['name' => 'Atún',   'unit' => 'kg', 'mode' => 'direct'],
    'weird' => ['name' => '写错的', 'unit' => '包', 'mode' => '乱写的'],
];
Db::forTests(['stock_items' => $mixItems]);
eq('不写 mode 默认走盘点法', Stock::itemMode('boxed'), Stock::MODE_COUNT);
eq('direct 认得出来', Stock::itemMode('atun'), Stock::MODE_DIRECT);
// 认不出的写法要退回【更严的】那一种。反过来的话，本该盘点的品类会悄悄不盘，
// 而且毫无迹象，等发现时已经缺了几个月的盘点数据
eq('mode 写错时退回盘点法', Stock::itemMode('weird'), Stock::MODE_COUNT);
ok('isDirect 只对 direct 为真',
   Stock::isDirect('atun') && !Stock::isDirect('boxed') && !Stock::isDirect('weird'));
eq('未知品类按盘点法', Stock::itemMode('不存在'), Stock::MODE_COUNT);

$dBase = ['happened_date' => $dAgo(1), 'happened_time' => '11:00', 'qty' => '3'];
[, $e] = Stock::validate($dBase + ['item' => 'atun', 'move_kind' => 'count']);
ok('存入即用量的品类不许盘点', isset($e['item']));
ok('拒绝时说清了该改选什么', strpos($e['item'] ?? '', '存入') !== false);
[, $e] = Stock::validate($dBase + ['item' => 'atun', 'move_kind' => 'in']);
eq('这类品类照常可以存入', $e, []);
[, $e] = Stock::validate($dBase + ['item' => 'boxed', 'move_kind' => 'count']);
eq('盘点法的品类照常可以盘点', $e, []);

// ---- 存入即用量：用量就是存入量 ----
Store::useMemoryForTests();
$sIn(date('Y-m-d', strtotime('-40 day')), '11:00', 'atun', 'in', '5');   // 30 天外
$sIn(date('Y-m-d', strtotime('-10 day')), '11:00', 'atun', 'in', '4');   // 30 天内、7 天外
$sIn(date('Y-m-d', strtotime('-2 day')),  '11:00', 'atun', 'in', '2');
$sIn(date('Y-m-d'),                       '11:00', 'atun', 'in', '1');
$ca = Stock::current()['atun'];
ok('标成 direct', $ca['direct']);
eq('近 7 天用量 = 2 + 1', $ca['in_7'], 3.0);
eq('近 30 天用量 = 4 + 2 + 1', $ca['in_30'], 7.0);
eq('累计用量 = 全部存入', $ca['in_total'], 12.0);
eq('记下最近一次存入的时间', substr((string) $ca['last_in_at'], 0, 10), date('Y-m-d'));
// 这类品类没有「剩多少」这回事，别给出一个会被当成库存的数字
eq('没有账面结存', $ca['book'], null);
eq('没有分段用量', count($ca['periods']), 0);
ok('不标成「从没盘过」的异常', !$ca['counted']);

// ---- 盘点进度不该老提示「还差 Atún」 ----
Store::useMemoryForTests();
$sIn($dAgo(1), '23:30', 'boxed', 'count', '5');
$sIn($dAgo(1), '23:30', 'weird', 'count', '2');   // mode 写错的那个也算盘点法
$pg = Stock::countProgress($dAgo(1) . ' 23:30');
eq('盘完盘点法的品类就算齐了', $pg['missing'], []);
ok('不把不盘点的品类算进「还差」', !in_array('atun', $pg['missing'], true));
Db::forTests(null);
Store::useMemoryForTests();

// ---- 页面与铁律 ----
$stkSrc = (string) file_get_contents(__DIR__ . '/../stock.php');
$nowSrc = (string) file_get_contents(__DIR__ . '/../stocknow.php');
ok('库存录入页要求登录', strpos($stkSrc, 'Auth::requireLogin()') !== false);
ok('写操作走 POST + CSRF', strpos($stkSrc, 'Auth::csrfValid') !== false);
ok('提交后跳转，避免刷新重复提交', strpos($stkSrc, "header('Location: '") !== false);
ok('作废前要二次确认', strpos($stkSrc, 'onsubmit="return confirm(') !== false);
ok('库存页不碰主库', strpos($stkSrc, 'Db::select') === false);
ok('当前库存页不碰主库', strpos($nowSrc, 'Db::select') === false);
// 当前库存页是纯展示，一行写操作都不该有
ok('当前库存页不写任何东西',
   preg_match('/\b(INSERT|UPDATE|DELETE|CREATE)\b/', $nowSrc) === 0);
ok('用到 Report 就 require 了 report.php',
   strpos($stkSrc, 'Report::') === false
   || strpos($stkSrc, "require_once __DIR__ . '/lib/report.php'") !== false);
ok('数字列表头带 class="n"',
   preg_match('/<th>(数量|最近盘点|之后存入|账面上限|上一段用量)/u', $stkSrc . $nowSrc) === 0);
// 账面 ≠ 实时库存，这句话必须留在页面上，不然一定会被当成现在冰箱里的量
ok('页面写明账面不是实时库存',
   strpos($nowSrc, '不等于「现在冰箱里有多少」') !== false);
// 库存和采购是两本账，程序里不做对应 —— 别让人「顺手」接起来
ok('库存那侧不引用采购逻辑',
   strpos($stkSrc, 'Meat::') === false && strpos($nowSrc, 'Meat::') === false);
ok('库存逻辑层也不引用采购逻辑',
   strpos((string) file_get_contents(__DIR__ . '/../lib/stock.php'), 'Meat::') === false);

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
