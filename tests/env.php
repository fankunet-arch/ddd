<?php
/**
 * PHP 环境诊断 —— 完全独立，不 include 任何文件，不依赖任何数据库扩展。
 *
 * 专门用来排查"启用 extension=pdo_mysql 后 500"这类问题：
 * 它会告诉你 PHP 到底读了哪个 php.ini、扩展目录在哪、目录里有哪些
 * 数据库扩展文件、以及本程序能不能跑起来。
 *
 *     php tests/env.php          （命令行）
 *     浏览器访问 tests/env.php    （看 Web 那一侧的 PHP，两者配置常常不同！）
 *
 * 不执行任何 SQL，不连接数据库。
 */

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font:13px/1.6 monospace;padding:16px">';
}

function say(string $m = ''): void { echo $m . "\n"; }
function kv(string $k, string $v): void { printf("  %-26s %s\n", $k, $v); }

say();
say('===== PHP 基本信息 =====');
kv('PHP 版本', PHP_VERSION);
kv('运行方式 (SAPI)', PHP_SAPI);
kv('操作系统', PHP_OS . ' / ' . php_uname('m'));
kv('架构', (PHP_INT_SIZE === 8 ? '64 位' : '32 位'));
kv('线程安全 (ZTS)', (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE) ? '是 (TS)' : '否 (NTS)');

say();
say('===== 配置文件（500 问题通常出在这里）=====');
$loaded = php_ini_loaded_file();
kv('主 php.ini', $loaded !== false ? $loaded : '(没有加载任何 php.ini)');
$scanned = php_ini_scanned_files();
if ($scanned !== false && trim($scanned) !== '') {
    say('  附加配置目录里的 ini 文件:');
    foreach (array_filter(array_map('trim', explode(',', $scanned))) as $f) {
        say('      ' . $f);
    }
} else {
    kv('附加 ini 目录', '(无)');
}
say();
say('  ⚠ 命令行和 Web 服务器经常用【不同的 php.ini】。');
say('    请务必在浏览器里也访问一次本页，对比上面这个路径是否一致 ——');
say('    改错文件是"改了没效果"和"改完 500"最常见的原因。');

say();
say('===== 扩展目录 =====');
$extDir = ini_get('extension_dir');
kv('extension_dir', (string) $extDir);
if ($extDir && is_dir($extDir)) {
    kv('目录是否存在', '存在');
    $found = [];
    foreach ((array) @scandir($extDir) as $f) {
        if (preg_match('/(pdo|mysql)/i', $f) && preg_match('/\.(so|dll)$/i', $f)) {
            $found[] = $f;
        }
    }
    if ($found) {
        say('  目录里与数据库相关的扩展文件:');
        foreach ($found as $f) {
            say('      ' . $f . '  (' . number_format((int) @filesize($extDir . DIRECTORY_SEPARATOR . $f)) . ' 字节)');
        }
    } else {
        say('  ⚠ 目录里找不到任何 pdo_mysql / mysqli 扩展文件！');
        say('    如果 php.ini 里写了 extension=pdo_mysql 但文件不存在，');
        say('    PHP 启动就会失败 —— 这正是 500 的典型成因。');
    }
} else {
    say('  ⚠ extension_dir 指向的目录不存在，任何 extension= 都会加载失败。');
}

say();
say('===== 数据库扩展加载情况 =====');
$exts = [
    'pdo'        => 'PDO 核心（必需）',
    'pdo_mysql'  => 'PDO 的 MySQL 驱动（方案一）',
    'mysqli'     => 'mysqli 扩展（方案二，本程序也支持）',
    'mysqlnd'    => 'MySQL 原生驱动（可选）',
];
foreach ($exts as $e => $desc) {
    kv($e, (extension_loaded($e) ? '已加载' : '未加载') . '   ' . $desc);
}

if (extension_loaded('pdo')) {
    $drv = PDO::getAvailableDrivers();
    kv('PDO 可用驱动', $drv ? implode(', ', $drv) : '(无)');
}

say();
say('===== 本程序能否运行 =====');
$hasPdoMysql = extension_loaded('pdo') && extension_loaded('pdo_mysql')
    && in_array('mysql', class_exists('PDO') ? PDO::getAvailableDrivers() : [], true);
$hasMysqli = extension_loaded('mysqli');

if ($hasPdoMysql) {
    say('  ✓ 可以运行，将使用 PDO 驱动。');
} elseif ($hasMysqli) {
    say('  ✓ 可以运行，将自动改用 mysqli 驱动。');
    say('    pdo_mysql 没有也没关系，不用再折腾 php.ini 了。');
} else {
    say('  ✗ 无法运行：pdo_mysql 和 mysqli 一个都没有。');
    say('    需要在 php.ini 里启用其中之一（推荐先试 mysqli，它更常见）：');
    say('        extension=mysqli');
    say('    改完重启 Web 服务，再刷新本页确认。');
}

say();
say('===== 出现 500 时怎么找到真正的错误 =====');
kv('display_errors', (string) (ini_get('display_errors') ?: '(空/关闭)'));
kv('log_errors', (string) (ini_get('log_errors') ?: '(空/关闭)'));
$errLog = ini_get('error_log');
kv('error_log', $errLog !== false && $errLog !== '' ? $errLog : '(未设置，错误会写进 Web 服务器的日志)');
say();
say('  500 是 PHP 启动或致命错误，页面上通常看不到原因，要去日志里找：');
say('    - 上面 error_log 指向的文件');
say('    - Apache:  error_log / error.log');
say('    - Nginx:   error.log，以及 PHP-FPM 的 php-fpm.log');
say('    - Windows: 事件查看器，或 Apache 的 logs\\error.log');
say();
say('  命令行验证配置有没有写坏（不启动 Web 服务也能测）：');
say('        php -v          能正常输出版本号说明 php.ini 没写坏');
say('        php -m          列出所有已加载的扩展');
say('        php -i | grep -i "loaded configuration"     确认读的是哪个 ini');
say();
say('  常见的 500 原因：');
say('    1. extension=pdo_mysql 写了，但扩展目录里没有对应的 .so/.dll 文件');
say('    2. 重复加载 —— 附加 ini 目录里已经有 pdo_mysql.ini 了，php.ini 里又写了一遍');
say('    3. 扩展与 PHP 版本/线程安全模式不匹配（比如 TS 的 dll 装在 NTS 的 PHP 上）');
say('    4. 改完 php.ini 没有重启 Web 服务（PHP-FPM 需要单独重启）');
say();

if (!$cli) { echo '</pre>'; }
