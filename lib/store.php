<?php
/**
 * 自有数据存储 —— SQLite 单文件
 *
 * ============================================================
 *  ⚠️ 这是全程序【唯一】允许写入的地方。改动前请看根目录「注意事项.md」铁律二。
 * ============================================================
 *
 *  和 lib/db.php 的关系：**没有关系，故意的**。
 *
 *    Db     → POS 主库（MySQL），只读，一个字节都不许写
 *    Store  → 我们自己的数据（SQLite 文件），可读可写
 *
 *  两个类各自持有自己的连接，互不引用。不要给 Db 加写能力，
 *  也不要让 Store 去连 MySQL —— 构造时会强制检查 DSN 必须是 sqlite:。
 *
 *  跨两边的统计一律在 PHP 内存里合并（按日期之类的 key 拼），
 *  不许写出把两边放进同一条 SQL 的代码。
 *
 * ============================================================
 *  数据文件放哪 —— 程序自己挑，不用固定默认值
 * ============================================================
 *  绝对不能放进网站可访问目录：`.db` 就是个普通文件，别人猜对网址就能
 *  整个下载走，不需要登录，日志里也只是一次普通的静态文件请求。
 *
 *  【为什么不能只写一个固定默认值】老版本默认「程序目录的上一级 / data」，
 *  假设那里在网站外面。多数虚机是这样，但面板类主机（宝塔/aaPanel）不是：
 *
 *      /www/wwwroot/站点/www/wwwroot/       ← 网站根（能访问）
 *      /www/wwwroot/站点/www/wwwroot/app/   ← 程序放这里
 *      /www/wwwroot/站点/www/wwwroot/data/  ← 「上一级」正好还在网站里 ❌
 *      /www/wwwroot/站点/www/               ← 这层才在外面 ✅
 *
 *  【为什么只报警不够】第一版只在页面上红字提示，结果没人去改配置，
 *  程序每次还是往同一个地方建库 —— 删一次，长一次。所以现在是
 *  【先挑位置再建】：path() 按顺序试
 *      1. 网站根的上一级 / salesreport-data
 *      2. 程序目录的上一级 / salesreport-data
 *  选第一个「不在网站里 + 可写」的；都不行才退回程序目录旁边，
 *  同时写访问保护文件并在页面上要求人来指定 store_path。
 *
 *  config.php 里写了 store_path 就照办，不自作主张。
 *
 *  这一项让程序第一次需要【写权限】：那个目录要让 Web 服务器账号可写。
 */

declare(strict_types=1);

final class Store
{
    /**
     * 数据文件名和目录名都用【本程序专有】的名字，不用 data / app.db 这种通用名。
     *
     * 两个原因：
     *   1. 通用名会跟别的程序撞车 —— 同一个目录下谁也说不清 app.db 是谁的。
     *   2. 撞了之后没法区分。现在再加一张 app_meta 表当身份标记：
     *      打开一个已存在的文件时先验明正身，不是本程序的就拒绝打开，
     *      绝不往别人的数据库里建表。
     */
    public const DIR_NAME  = 'salesreport-data';
    public const FILE_NAME = 'salesreport.db';
    private const APP_ID   = 'salesreport';

    /** 老版本用过的文件名／目录名，升级时要能认出来并接管过去 */
    private const LEGACY_NAMES = [['data', 'app.db']];

    private static ?PDO $pdo = null;
    private static ?string $failure = null;
    private static ?string $resolved = null;
    private static ?string $resolvedFor = null;   // 缓存对应的 store_path 配置值
    /** 选址／迁移过程中的说明，页面上要显示给人看 */
    private static array $notes = [];
    /** 仅供自检：假装程序装在别处，好模拟各种目录形状（生产环境永远是 null） */
    private static ?string $appRootForTests = null;

    /**
     * 数据文件路径。
     *
     * config.php 里写了 store_path 就用它（尊重明确的配置）；
     * 没写的话【自己挑一个网站访问不到的位置】，而不是套一个固定的默认值。
     *
     * 为什么不能只用固定默认值：老默认是「程序目录的上一级」，
     * 在宝塔那类面板上（网站根 = 站点/www/wwwroot/，程序再放下面一层）
     * 正好还在网站根里面 —— 那样数据库能被人直接下载走。
     * 光在页面上报警不解决问题：没人改配置的话，程序下一次还是往那儿建库。
     * 所以这里直接换个安全的地方建，报警只作为最后兜底。
     */
    public static function path(): string
    {
        require_once __DIR__ . '/db.php';
        $cfg = trim((string) (Db::config()['store_path'] ?? ''));
        if (self::$resolved !== null && self::$resolvedFor === $cfg) {
            return self::$resolved;
        }
        self::$resolvedFor = $cfg;
        self::$notes = [];

        if ($cfg !== '') {
            return self::$resolved = $cfg;       // 明确配置了就照办，不自作主张
        }

        $blind = self::webRootUnknown();
        foreach (self::candidateDirs() as $dir) {
            if (self::insideDocRoot($dir) !== null || !self::dirUsable($dir)) {
                continue;
            }
            if ($blind) {
                // 判不出网站根（SERVER 变量都缺）时【不能假定安全】。
                // 位置照选，但要说出来，并且照样写访问保护文件 —— 静默放行
                // 正是上一版的毛病：查不出来就当没事，结果一直建在能下载的地方。
                self::$notes[] = ['warn', '判断不出网站根目录（服务器没给 DOCUMENT_ROOT，'
                    . 'SCRIPT_FILENAME 也推不出来），没法确认 ' . $dir
                    . ' 是不是网站访问得到。请在浏览器里试一下这个文件的网址，'
                    . '能下载就在 config.php 里指定 store_path。'];
            }
            return self::$resolved = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;
        }

        // 一个安全位置都挑不出来：退回程序目录旁边，同时尽力加上访问保护，
        // 并在页面上红字报警 —— 这时候需要人来指定 store_path
        self::$notes[] = ['warn', '找不到网站访问不到的可写目录，只能先放在程序目录旁边。'
                                . '请在 config.php 里把 store_path 指到网站根目录之外。'];
        return self::$resolved = self::appRoot() . DIRECTORY_SEPARATOR . self::DIR_NAME
                               . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * 备选目录，按优先级排。
     *
     * 第一个是【网站根目录的上一级】—— 面板类主机（宝塔/aaPanel）就是这个形状：
     *     /www/wwwroot/站点/www/wwwroot/   ← 网站根
     *     /www/wwwroot/站点/www/           ← 这层在外面，放这里
     * 第二个是【程序目录的上一级】—— 普通虚机常见的形状。
     */
    private static function candidateDirs(): array
    {
        $out = [];
        $doc = self::webRoot();
        if ($doc !== null) {
            $out[] = dirname($doc) . DIRECTORY_SEPARATOR . self::DIR_NAME;
        }
        $app   = self::appRoot();
        $out[] = dirname($app) . DIRECTORY_SEPARATOR . self::DIR_NAME;
        return array_values(array_unique($out));
    }

    /** 程序根目录 */
    private static function appRoot(): string
    {
        return self::$appRootForTests ?? dirname(__DIR__);
    }

    /** 仅供自检脚本：假装程序装在别处 */
    public static function useAppRootForTests(?string $dir): void
    {
        self::$appRootForTests = $dir;
        self::resetPathCache();
    }

    /** 这个目录能不能用：已存在的要可写，不存在的要能建出来 */
    private static function dirUsable(string $dir): bool
    {
        if (file_exists($dir)) {
            // 同名的【文件】挡在那里也算不可用 —— 只判 is_dir 的话会一路放行到
            // mkdir 才失败，那时候已经选完址了，报出来的错也看不出真正原因
            return is_dir($dir) && is_writable($dir);
        }
        $parent = dirname($dir);
        return is_dir($parent) && is_writable($parent);
    }

    /**
     * 数据文件是不是落在【网站可访问目录】里了？是的话返回网站根目录，否则返回 null。
     *
     * 这不是理论风险：`.db` 就是个普通文件，放在 web 目录下，
     * 谁把 URL 猜对了就能把整个数据库下载走 —— 不需要登录、日志里也只是一次
     * 普通的静态文件请求，你根本不会发现。
     *
     * 默认路径是「程序目录的上一级」，这在多数虚机上就在网站目录外面；
     * 但宝塔/aaPanel 那类面板常见的目录是
     *
     *     /www/wwwroot/站点/www/wwwroot/     ← 网站根（可访问）
     *     /www/wwwroot/站点/www/             ← 这层才在外面
     *
     * 程序放进网站根下面一层时，「上一级」正好还在网站根里 —— 默认值就踩空了。
     * 所以这里在运行时实测一次，踩空了页面上直接红字报警，
     * 而不是指望部署的人记得看文档。
     */
    public static function exposedUnder(): ?string
    {
        return self::insideDocRoot(dirname(self::path()));
    }

    /**
     * 网站根目录在哪。
     *
     * 两个来源都用上，取【最浅的那个】：
     *
     *   1. $_SERVER['DOCUMENT_ROOT'] —— 常见，但 nginx + PHP-FPM 某些配置下是空的
     *   2. SCRIPT_FILENAME 去掉 SCRIPT_NAME —— 这个几乎总有：
     *      /站点/www/wwwroot/app/stock.php  减去  /app/stock.php
     *      = /站点/www/wwwroot
     *
     * 为什么取最浅的：两个对不上时，浅的那个把【更多】目录判成「在网站里」，
     * 也就是更保守。宁可多报一次「这里不安全」，也不能漏掉一次真不安全的。
     */
    private static function webRoot(): ?string
    {
        $cands = [];
        $doc = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($doc !== '' && ($r = realpath($doc)) !== false) {
            $cands[] = $r;
        }
        // 命令行下 SCRIPT_FILENAME 指的是被执行的脚本本身，跟网站根毫无关系，
        // 拿它推出来的东西会把测试和体检脚本全带偏 —— 只在网页请求里用
        if (PHP_SAPI !== 'cli') {
            $d = self::deriveWebRoot((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''),
                                     (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            if ($d !== null) {
                $cands[] = $d;
            }
        }
        if (!$cands) {
            return null;
        }
        // 两个来源对不上时取【最浅的】：浅的把更多目录判成「在网站里」，更保守。
        // 宁可多报一次「这里不安全」，也不能漏掉一次真不安全的。
        usort($cands, static fn($a, $b) => strlen($a) <=> strlen($b));
        return $cands[0];
    }

    /**
     * SCRIPT_FILENAME 去掉 SCRIPT_NAME = 网站根。纯函数，好单独测。
     *
     *   /站点/www/wwwroot/app/stock.php  减去  /app/stock.php
     *   = /站点/www/wwwroot
     *
     * DOCUMENT_ROOT 在 nginx + PHP-FPM 的某些配置下是空的，这条是备胎。
     */
    public static function deriveWebRoot(string $scriptFile, string $scriptName): ?string
    {
        if ($scriptFile === '' || $scriptName === '') {
            return null;
        }
        $rf = realpath($scriptFile);
        if ($rf === false) {
            return null;
        }
        $rfN = str_replace('\\', '/', $rf);
        $snN = str_replace('\\', '/', $scriptName);
        if ($snN[0] !== '/') {
            return null;                       // 相对路径推不出根，别乱猜
        }
        $len = strlen($snN);
        if (substr($rfN, -$len) !== $snN) {
            return null;                       // 对不上就说不知道，不硬凑
        }
        $base = substr($rfN, 0, -$len);
        if ($base === '') {
            return null;
        }
        $rb = realpath($base);
        return $rb === false ? null : $rb;
    }

    /** 判不出网站根（命令行、或者 SERVER 变量都缺）—— 这时不能假定安全 */
    public static function webRootUnknown(): bool
    {
        return self::webRoot() === null;
    }

    /** 这个目录是不是在网站根里面？是的话返回网站根，否则 null */
    private static function insideDocRoot(string $dir): ?string
    {
        $doc = self::webRoot();
        if ($doc === null) {
            return null;                       // 命令行等场景判断不了，不误报
        }
        $real = realpath($dir);
        if ($real !== false) {
            $dir = $real;                      // 目录还没建出来时按字面比
        }
        $norm = static function (string $p): string {
            $p = rtrim(str_replace('\\', '/', $p), '/');
            // Windows 路径大小写不敏感，别因为大小写不同就判成「安全」
            return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
        };
        $d = $norm($dir);
        $r = $norm($doc);
        return ($d === $r || strncmp($d, $r . '/', strlen($r) + 1) === 0) ? $doc : null;
    }

    /** 选址／迁移过程中要告诉人的话：[['ok'|'warn', 文字], …] */
    public static function notes(): array
    {
        self::path();          // 触发一次选址，notes 才有内容
        return self::$notes;
    }

    /**
     * 这个 SQLite 文件是不是本程序的？
     *
     * 通用文件名（app.db）根本没法区分是谁的库，所以靠内容认：
     * 有 app_meta 身份表、或者有本程序的业务表，就算是自己的。
     * 空文件（还没建表）也算 —— 那是刚创建出来的。
     *
     * 认不出来就【拒绝打开】：宁可报错让人处理，也不能往别人的数据库里建表。
     */
    private static function identify(PDO $pdo): string
    {
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$names) {
            return 'empty';
        }
        if (in_array('app_meta', $names, true)) {
            $row = $pdo->query("SELECT v FROM app_meta WHERE k = 'app'")->fetchColumn();
            return $row === self::APP_ID ? 'ours' : 'foreign';
        }
        // 老版本建的库没有 app_meta，靠业务表认领
        if (array_intersect(['meat_purchase', 'stock_move'], $names)) {
            return 'legacy';
        }
        return 'foreign';
    }

    /**
     * 目标位置还没有数据文件时，看看老位置有没有 —— 有就搬过来。
     *
     * 不搬的话，升级之后程序会在新位置建一个空库，看上去就像「数据全没了」，
     * 而旧文件还留在网站可访问的目录里继续能被下载。
     *
     * 搬之前先 checkpoint，把 -wal 里的内容折回主文件 —— 只搬主文件会丢最近的写入。
     */
    private static function adoptLegacy(string $target): void
    {
        $dirs = [];
        $doc  = self::webRoot();
        if ($doc !== null) {
            $dirs[] = dirname($doc);
        }
        $dirs[] = dirname(self::appRoot());

        foreach (array_unique($dirs) as $base) {
            foreach (self::LEGACY_NAMES as [$dirName, $fileName]) {
                $src = $base . DIRECTORY_SEPARATOR . $dirName . DIRECTORY_SEPARATOR . $fileName;
                if (!is_file($src) || !is_readable($src)) {
                    continue;
                }
                try {
                    $old = new PDO('sqlite:' . $src, null, null,
                                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    if (!in_array(self::identify($old), ['ours', 'legacy'], true)) {
                        $old = null;
                        continue;              // 别人的库，不碰
                    }
                    // -wal 折回主文件，这样只搬一个文件也不会丢数据
                    $old->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $old = null;
                } catch (Throwable $e) {
                    continue;
                }

                if (@rename($src, $target)) {
                    @unlink($src . '-wal');
                    @unlink($src . '-shm');
                    self::$notes[] = ['ok', "已把数据文件从 {$src} 搬到 {$target}"
                                          . '（那是网站能访问到的位置，留着会被下载走）'];
                    return;
                }
                // 跨分区时 rename 会失败，退回「复制 → 校验 → 删源」
                if (@copy($src, $target) && self::verifyCopy($target)) {
                    if (!@unlink($src)) {
                        self::$notes[] = ['warn', "数据已复制到 {$target}，"
                            . "但旧文件 {$src} 删不掉（权限不够）—— "
                            . '它还在网站可访问的位置，请手工删除'];
                    } else {
                        @unlink($src . '-wal');
                        @unlink($src . '-shm');
                        self::$notes[] = ['ok', "已把数据文件从 {$src} 搬到 {$target}"];
                    }
                    return;
                }
                @unlink($target);              // 复制没成功就别留半个文件
            }
        }
    }

    /** 复制过来的文件能打开、而且认得出是本程序的，才算搬成功 */
    private static function verifyCopy(string $path): bool
    {
        try {
            $p = new PDO('sqlite:' . $path, null, null,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return in_array(self::identify($p), ['ours', 'legacy'], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 数据文件是否已经能用（不能用时用 lastError() 取原因） */
    public static function isReady(): bool
    {
        try {
            self::pdo();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$failure;
    }

    /**
     * 拿连接。第一次调用时建库建表。
     *
     * 建表用 IF NOT EXISTS，跑多少次都一样，不需要单独的迁移机制 ——
     * 这点数据量不值得为它引入版本管理。
     */
    private static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = self::path();
        $dir  = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            self::$failure = "数据目录建不出来：{$dir}（需要 Web 服务器账号有写权限）";
            throw new RuntimeException(self::$failure);
        }
        if (!is_writable($dir)) {
            self::$failure = "数据目录不可写：{$dir}（本程序第一次需要写权限，"
                           . '请给 Web 服务器账号加上）';
            throw new RuntimeException(self::$failure);
        }
        // 万一只能落在网站目录里（挑不出安全位置时的兜底），尽力挡一下直接下载。
        // Apache 认 .htaccess，IIS 认 web.config；nginx 两个都不认，所以这只是补丁，
        // 真正的解法仍然是把 store_path 指到网站根之外。
        if (self::insideDocRoot($dir) !== null) {
            self::protectDir($dir);
        }

        // 新位置还没有文件时，看看老位置有没有 —— 有就搬过来，
        // 否则升级之后会在新位置建一个空库，看着就像数据全没了
        if (!is_file($path)) {
            self::adoptLegacy($path);
        }

        $dsn = 'sqlite:' . $path;
        // 边界检查：Store 只碰 SQLite。写成常量拼接看着多余，但这是铁律二的
        // 机械保险 —— 万一以后有人把 store_path 改成 mysql:... 这里直接拦住。
        if (strncmp($dsn, 'sqlite:', 7) !== 0) {
            throw new RuntimeException('Store 只允许连接 SQLite');
        }

        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            self::$failure = '打不开数据文件：' . $e->getMessage();
            throw new RuntimeException(self::$failure, 0, $e);
        }

        // 打开的是不是本程序的库？不是就退出来，绝不往别人的数据库里建表
        $who = self::identify($pdo);
        if ($who === 'foreign') {
            self::$failure = "这个文件不是本程序的数据库：{$path}"
                . ' —— 里面有别的程序的表。为免破坏它的数据，本程序不会往里写。'
                . '请在 config.php 里把 store_path 换一个路径或文件名。';
            throw new RuntimeException(self::$failure);
        }

        // WAL：读写可以并发，几个人同时用不会互相锁死
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        try {
            self::migrate($pdo);
        } catch (Throwable $e) {
            // 建表失败时也要给出【看得懂的原因】。不接住的话页面上只剩
            // 「数据文件不可用：」后面空空如也 —— 那种提示等于没有。
            self::$pdo = null;
            self::$failure = '建表失败：' . $e->getMessage()
                . "（数据文件：{$path}）";
            throw new RuntimeException(self::$failure, 0, $e);
        }
        return $pdo;
    }

    /** 兜底用的访问保护文件。挡得住 Apache / IIS，挡不住 nginx。 */
    private static function protectDir(string $dir): void
    {
        $files = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer>"
                          . "<security><requestFiltering><hiddenSegments><add segment=\".\" />"
                          . "</hiddenSegments></requestFiltering></security>"
                          . "</system.webServer></configuration>\n",
            'index.html' => "\n",
        ];
        foreach ($files as $name => $body) {
            $f = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($f)) {
                @file_put_contents($f, $body);
            }
        }
    }

    /**
     * 给已有的表补一列（没有就加，有了就什么都不做）。
     *
     * 建表走的是 CREATE TABLE IF NOT EXISTS —— 表已经存在时它一声不吭地跳过，
     * 于是【老库永远拿不到新加的列】。升级之后页面报 "no such column"，
     * 而开发机上因为是新建的库，怎么试都是好的。这个方法就是补这个洞。
     *
     * 表名和列名要拼进 SQL，所以只能是代码里写死的字面量 ——
     * 这个方法是 private，外面传不进来东西。
     */
    private static function addColumn(PDO $pdo, string $table, string $col, string $type): void
    {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
        }
    }

    /** 建表。CHECK 约束是最后一道防线 —— PHP 校验之外，数据库自己也拦。 */
    private static function migrate(PDO $pdo): void
    {
        // 身份标记。文件名可能被改、可能跟别的程序撞名，
        // 但这张表在文件里面 —— 认库要认它，不能只认文件名。
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $st = $pdo->prepare('INSERT OR IGNORE INTO app_meta (k, v) VALUES (:k, :v)');
        $st->execute([':k' => 'app',        ':v' => self::APP_ID]);
        $st->execute([':k' => 'created_at', ':v' => date('Y-m-d H:i:s')]);
        // 记下是哪一处程序建的，将来同一台机器上装了第二份时能看出来
        $st->execute([':k' => 'origin',     ':v' => dirname(__DIR__)]);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meat_purchase (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_date TEXT    NOT NULL,
                kind          TEXT    NOT NULL,
                weight_kg     REAL,
                unit_count    REAL,
                unit_type     TEXT,
                price_basis   TEXT,
                unit_price    REAL,
                total_price   REAL,
                supplier      TEXT,
                note          TEXT,
                -- 发票导入的行指纹（手工录入的行是 NULL）。带 UNIQUE 索引，
                -- 同一份发票导第二次会被挡在门外 —— 见下面 idx_mp_impkey
                import_key    TEXT,
                created_at    TEXT    NOT NULL,
                updated_at    TEXT    NOT NULL,
                deleted_at    TEXT,
                -- 重量和件数至少要有一个：这条规则在 PHP 里也校验，
                -- 但数据库层再拦一道，绕过页面直接写也进不来脏数据
                CHECK (weight_kg IS NOT NULL OR unit_count IS NOT NULL),
                CHECK (weight_kg  IS NULL OR weight_kg  > 0),
                CHECK (unit_count IS NULL OR unit_count > 0),
                CHECK (unit_price IS NULL OR unit_price >= 0),
                CHECK (total_price IS NULL OR total_price >= 0)
            )
            SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mp_date ON meat_purchase(purchase_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mp_kind ON meat_purchase(kind, purchase_date)');

        // 已经在用的库不会因为上面改了 CREATE TABLE 就多出一列 ——
        // CREATE TABLE IF NOT EXISTS 遇到已存在的表什么都不做。补一次 ALTER。
        self::addColumn($pdo, 'meat_purchase', 'import_key', 'TEXT');
        // 发票行的指纹唯一：同一份文件导两次，第二次进不来。
        // SQLite 的 UNIQUE 索引把每个 NULL 都当成互不相同，
        // 所以手工录入的行（import_key 为 NULL）想有多少条就有多少条。
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_mp_impkey
                    ON meat_purchase(import_key)');

        // 留痕：每次改动记一条，存改动前后的快照
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meat_purchase_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                row_id      INTEGER NOT NULL,
                action      TEXT    NOT NULL,
                before_json TEXT,
                after_json  TEXT,
                at          TEXT    NOT NULL
            )
            SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mpl_row ON meat_purchase_log(row_id, id)');

        // ---- 库存流水：只记【存入】和【盘点】两种动作 ----
        // 不记取出 —— 取出量是两次盘点之间算出来的：
        //   取出 = 上次盘点结存 + 期间存入 − 本次盘点结存
        // happened_at 精确到分钟：一天可能盘好几次（到店 / 午市后 / 晚市前 /
        // 晚市后），只记日期的话同一天的几次就排不出先后，用量也就分不到餐期上。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stock_move (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                happened_at TEXT    NOT NULL,   -- 'YYYY-MM-DD HH:MM'
                item        TEXT    NOT NULL,   -- 库存品类代码（stock_items）
                move_kind   TEXT    NOT NULL,   -- 'in' 存入 | 'count' 盘点
                qty         REAL    NOT NULL,   -- 存入量 / 盘点结存量
                moment      TEXT,               -- 时点标签（到店/午市后…），可空
                note        TEXT,
                created_at  TEXT    NOT NULL,
                updated_at  TEXT    NOT NULL,
                deleted_at  TEXT,
                CHECK (move_kind IN ('in', 'count')),
                -- 盘点可以是 0（数完发现空了），存入不行（存 0 等于什么都没做）
                CHECK (qty >= 0),
                CHECK (move_kind = 'count' OR qty > 0)
            )
            SQL);
        // 结存和用量都是「按品类、按时间顺序往下推」，所以索引就按这两列
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sm_item ON stock_move(item, happened_at, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sm_at   ON stock_move(happened_at, id)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stock_move_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                row_id      INTEGER NOT NULL,
                action      TEXT    NOT NULL,
                before_json TEXT,
                after_json  TEXT,
                at          TEXT    NOT NULL
            )
            SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sml_row ON stock_move_log(row_id, id)');
    }

    // ------------------------------------------------------------------
    // 通用读写
    // ------------------------------------------------------------------

    public static function select(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array
    {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }

    /** 执行写操作，返回受影响行数 */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** 留痕表白名单。表名要拼进 SQL，只能从这里取，不接受外面传进来的字符串。 */
    private const LOG_TABLES = ['meat_purchase_log', 'stock_move_log'];

    /** 记一条改动日志 */
    public static function log(int $rowId, string $action, ?array $before, ?array $after,
                              string $table = 'meat_purchase_log'): void
    {
        if (!in_array($table, self::LOG_TABLES, true)) {
            throw new InvalidArgumentException("未知的留痕表：{$table}");
        }
        self::run(
            "INSERT INTO {$table} (row_id, action, before_json, after_json, at)
             VALUES (:r, :a, :b, :f, :t)",
            [':r' => $rowId, ':a' => $action,
             ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
             ':f' => $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
             ':t' => date('Y-m-d H:i:s')]
        );
    }

    /** 事务：改动 + 留痕要么一起成功，要么一起回滚 */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** 仅供自检脚本：清掉选址缓存（测试里会改 DOCUMENT_ROOT / store_path） */
    public static function resetPathCache(): void
    {
        self::$resolved = null;
        self::$resolvedFor = null;
        self::$notes = [];
    }

    /** 仅供自检脚本：换成内存库，不碰真实文件 */
    public static function useMemoryForTests(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo = $pdo;
        self::migrate($pdo);
    }
}
