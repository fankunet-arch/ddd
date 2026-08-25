<?php
/** 页面公共小工具 */

declare(strict_types=1);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
<link rel="stylesheet" href="assets/app.css">
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
      'dish'    => ['dish.php',    '菜品点单统计', '菜品'],
      'station' => ['station.php', '岗位单量排名', '岗位'],
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
