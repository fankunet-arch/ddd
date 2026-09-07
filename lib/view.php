<?php
/** 页面公共小工具 */

declare(strict_types=1);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 静态文件的地址，带上「版本号」防止浏览器拿旧的缓存。
 *
 * 这是踩过的坑：只传了 .php 没传 assets/app.css，或者传了但浏览器还用着
 * 缓存里的旧样式 —— 页面看着就是坏的（新控件完全没样式），
 * 而这种问题从服务器端一点都看不出来。加了版本号，文件一变地址就变，
 * 浏览器自然会重新下载，不需要教人按 Ctrl+F5。
 *
 * 版本号怎么来，看 config 的 asset_version：
 *
 *   'auto'  （默认）文件内容的哈希 —— 改了就立刻更新，没改就一直用缓存。
 *                   最准，也不会白白重下。
 *   'date'          今天的日期 —— 每天最多用一天的旧文件，第二天必定更新。
 *                   FTP 上传会保留原文件时间戳的话，这个最稳妥。
 *   'mtime'         文件的修改时间。
 *   其它字符串       直接当版本号用（比如自己填个 '2026-09-06a'）。
 *
 * 读不到文件时一律退回日期，绝不返回不带版本号的地址。
 */
function asset(string $rel): string
{
    static $cache = [];
    if (isset($cache[$rel])) {
        return $cache[$rel];
    }
    require_once __DIR__ . '/db.php';
    $mode = trim((string) (Db::config()['asset_version'] ?? 'auto'));
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    if ($mode === 'auto') {
        $h = is_file($file) ? md5_file($file) : false;
        $v = $h === false ? date('Ymd') : substr($h, 0, 8);
    } elseif ($mode === 'mtime') {
        $t = is_file($file) ? filemtime($file) : false;
        $v = $t === false ? date('Ymd') : (string) $t;
    } elseif ($mode === 'date' || $mode === '') {
        $v = date('Ymd');
    } else {
        $v = $mode;                       // 自己填的固定版本号
    }
    return $cache[$rel] = $rel . '?v=' . rawurlencode($v);
}

/**
 * 数据文件放错地方时的红字警告。
 *
 * 用到自有存储的页面（采购、库存）都要调一次。放在这里而不是各页各写一份，
 * 是因为这条提醒漏掉一页就等于没有 —— 只要有一个页面不报警，
 * 部署的人就可能一直以为没事。
 */
function storeBanner(): void
{
    if (!class_exists('Store')) {
        return;                       // 不用自有存储的页面，什么都不做
    }

    // 选址／搬迁做了什么，说一声。程序自己动了文件却不吭声，
    // 下次有人发现数据不在老地方会以为出事了。
    foreach (Store::notes() as [$level, $text]) {
        printf('<p class="%s">%s%s</p>' . "\n",
               $level === 'ok' ? 'okmsg' : 'err',
               $level === 'ok' ? '<strong>数据文件已自动挪到安全位置：</strong><br>' : '',
               h($text));
    }

    $doc = Store::exposedUnder();
    if ($doc === null) {
        return;
    }
    $path = Store::path();
    ?>
  <p class="err"><strong>⚠️ 数据文件放在了网站可访问的目录里，请尽快挪走。</strong><br>
    当前位置：<code><?= h($path) ?></code><br>
    网站根目录：<code><?= h($doc) ?></code><br>
    <code>.db</code> 就是个普通文件 —— 放在网站目录下，
    <strong>谁把网址猜对了就能把整个数据库下载走</strong>，不需要登录，
    日志里也只是一次普通的静态文件请求，你不会发现。
    <br>
    程序本来会自己挑一个网站访问不到的位置，这次没挑到 ——
    多半是那几个候选目录都不可写。
    <br>
    改法：在 <code>config.php</code> 里把 <code>store_path</code> 指到
    <strong>网站根目录之外</strong>的路径，然后把已有的数据文件
    （<code><?= h(basename($path)) ?></code>，连同同名的
    <code>-wal</code>、<code>-shm</code>，有就一起）移过去，最后删掉旧目录。
    详见 README「七之三 · 数据存哪」。</p>
    <?php
}

/**
 * 数据文件在哪、安不安全 —— 直接印在页面上。
 *
 * 「程序说它挑了个安全位置」和「你能看到它挑的是哪儿」是两回事。
 * 印出来，出问题时一眼就能核，不用去翻服务器目录。
 */
function storeWhere(): string
{
    if (!class_exists('Store')) {
        return '';
    }
    $p   = Store::path();
    $doc = Store::exposedUnder();
    if ($doc !== null) {
        $tag = '<strong class="stale">⚠️ 在网站可访问目录里，请尽快挪走</strong>';
    } elseif (Store::webRootUnknown()) {
        $tag = '<span class="dim">（判断不出网站根目录，没法确认是否可被访问）</span>';
    } else {
        $tag = '<span class="state s-ok">网站访问不到</span>';
    }
    return '<code>' . h($p) . '</code> ' . $tag;
}

/** 金额格式化 */
function money($v): string
{
    return number_format((float) $v, 2, '.', ',');
}

/** 数量格式化：整数不显示小数位，小数最多两位（称重菜可能是 0.5 份） */
function qty($v): string
{
    $f = (float) $v;
    return abs($f - round($f)) < 0.0001
        ? number_format($f, 0, '.', ',')
        : rtrim(rtrim(number_format($f, 2, '.', ','), '0'), '.');
}

function num($v): string
{
    return number_format((int) $v, 0, '.', ',');
}

/** 人均消费 */
function perGuest(array $cell): string
{
    return $cell['guests'] > 0 ? money($cell['actual'] / $cell['guests']) : '—';
}

/** 单均消费 */
function perCheck(array $cell): string
{
    return $cell['checks'] > 0 ? money($cell['actual'] / $cell['checks']) : '—';
}

/** 读取 GET 参数 */
function q(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function qbool(string $key): bool
{
    return isset($_GET[$key]) && $_GET[$key] !== '' && $_GET[$key] !== '0';
}

/** 页面头部 */
function pageHeader(string $title, string $active): void
{
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1e2836">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-in">
    <span class="brand">营业数据查询</span>
    <span class="ro">只读</span>
    <a class="logout" href="login.php?action=logout">退出</a>
  </div>
  <?php
  // 每项给长短两种写法：宽屏用全称，手机上换成短名，靠 CSS 切换，不用 JS
  $nav = [
      'open'    => ['open.php',    '开台核对',     '开台'],
      'sales'   => ['index.php',   '营业额统计',   '营业额'],
      'compare' => ['compare.php', '期间对比',     '对比'],
      'dish'    => ['dish.php',    '菜品点单统计', '菜品'],
      'station' => ['station.php', '岗位单量排名', '岗位'],
      'meat'    => ['meat.php',    '肉类采购',     '采购'],
      'stock'   => ['stock.php',   '库存盘点',     '库存'],
  ];
  ?>
  <nav class="tabs">
    <?php foreach ($nav as $key => [$href, $long, $short]): ?>
      <a href="<?= h($href) ?>" class="<?= $active === $key ? 'on' : '' ?>">
        <span class="lg"><?= h($long) ?></span><span class="sm"><?= h($short) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
    <?php
}

function pageFooter(): void
{
    ?>
</main>
<footer class="foot">
  本程序仅执行 SELECT 查询，不会对数据库做任何写入、删除或结构变更。
</footer>
</body>
</html>
    <?php
}

/** 快捷日期按钮 */
function presetLinks(string $base): void
{
    // 营业日：凌晨 2 点前算前一天
    $today = date('Y-m-d', time() - Db::config()['day_cut_hour'] * 3600);
    $presets = [
        '今天'     => [$today, $today],
        '昨天'     => [date('Y-m-d', strtotime($today . ' -1 day')), date('Y-m-d', strtotime($today . ' -1 day'))],
        '近 7 天'  => [date('Y-m-d', strtotime($today . ' -6 day')), $today],
        '近 30 天' => [date('Y-m-d', strtotime($today . ' -29 day')), $today],
        '本月'     => [date('Y-m-01', strtotime($today)), $today],
    ];
    echo '<div class="presets">';
    foreach ($presets as $label => [$s, $e]) {
        $qs = $_GET;
        $qs['start'] = $s;
        $qs['end']   = $e;
        $qs['go']    = '1';
        printf('<a href="%s?%s">%s</a>', h($base), h(http_build_query($qs)), h($label));
    }
    echo '</div>';
}
