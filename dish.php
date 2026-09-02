<?php
/**
 * 菜品点单统计
 *
 * 本页只查询明细表：history_order_detail（+ 可选 order_detail），不做任何 JOIN。
 * 岗位和菜名来自 menu_item / print_class 两张小字典表，单独查询后在内存里映射。
 *
 * 与营业额统计完全分开，避免一次扫描过多数据。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$today = date('Y-m-d', time() - Db::config()['day_cut_hour'] * 3600);
$start = q('start', $today);
$end   = q('end', $today);
$seg   = q('seg', 'total');            // 排行按哪个时段
$pcFilter = q('pc');                   // '' = 全部岗位，或岗位 ID，或 'none' = 未分配
$mode  = q('mode', 'rank');            // rank = 排行榜, item = 单个菜品
$itemId      = (int) q('item_id', '0');
$includeCombo = qbool('include_combo_child');
$withLive     = !isset($_GET['go']) || qbool('with_live');
$showNever    = qbool('show_never');

if (!in_array($seg, ['day', 'night', 'total'], true)) {
    $seg = 'total';
}

$error = null;
$menuItems = $printClasses = [];
$dishes = $itemRows = null;
$meta = [];

// 字典表：无论查不查统计都要加载，供搜索框使用。两张小表，各一次查询。
try {
    $menuItems    = Biz::menuItems();
    $printClasses = Biz::printClasses();
} catch (Throwable $e) {
    $error = '读取菜品字典失败：' . $e->getMessage();
}

if (!$error && isset($_GET['go'])) {
    $error = Biz::validateRange($start, $end);
    if ($error === null) {
        try {
            [$from, $to] = Biz::range($start, $end);
            $opts = ['include_combo_child' => $includeCombo];
            $useLive = $withLive && Biz::needLiveTables($to);

            if ($mode === 'item' && $itemId > 0) {
                // ---- 单个菜品：按营业日 + 时段列出 ----
                $t0 = microtime(true);
                $rows = Biz::dishByDay($from, $to, 'history_order_detail', $itemId, $opts);
                $meta['queries'][] = ['history_order_detail', count($rows), microtime(true) - $t0];

                if ($useLive) {
                    $t1 = microtime(true);
                    $lv = Biz::dishByDay($from, $to, 'order_detail', $itemId, $opts);
                    $meta['queries'][] = ['order_detail', count($lv), microtime(true) - $t1];
                    $rows = array_merge($rows, $lv);
                }
                // 按营业日归并
                $byDay = [];
                $tot = ['day' => 0.0, 'night' => 0.0, 'gap' => 0.0, 'total' => 0.0,
                        'times' => 0, 'amount' => 0.0];
                foreach ($rows as $r) {
                    $d = (string) $r['biz_date'];
                    $byDay[$d] ??= ['day' => 0.0, 'night' => 0.0, 'gap' => 0.0, 'total' => 0.0,
                                    'times' => 0, 'amount' => 0.0];
                    $byDay[$d][(string) $r['seg']] += (float) $r['qty'];
                    $byDay[$d]['total']  += (float) $r['qty'];
                    $byDay[$d]['times']  += (int) $r['times'];
                    $byDay[$d]['amount'] += (float) $r['amount'];
                    $tot[(string) $r['seg']] += (float) $r['qty'];
                    $tot['total']  += (float) $r['qty'];
                    $tot['times']  += (int) $r['times'];
                    $tot['amount'] += (float) $r['amount'];
                }
                ksort($byDay);
                $itemRows = ['days' => $byDay, 'total' => $tot];
            } else {
                // ---- 排行榜：一次查询取回全部菜品，排行在内存里算 ----
                $mode = 'rank';
                $t0 = microtime(true);
                $rows = Biz::dishTotals($from, $to, 'history_order_detail', $opts);
                $meta['queries'][] = ['history_order_detail', count($rows), microtime(true) - $t0];

                $live = [];
                if ($useLive) {
                    $t1 = microtime(true);
                    $live = Biz::dishTotals($from, $to, 'order_detail', $opts);
                    $meta['queries'][] = ['order_detail', count($live), microtime(true) - $t1];
                }
                $dishes = Report::buildDishes($menuItems, $printClasses, $rows, $live);
            }
            $meta['range'] = [$from, $to];
        } catch (Throwable $e) {
            $error = '查询失败：' . $e->getMessage();
        }
    }
}

// 搜索框数据（排除做法/口味项）
$pickList = [];
foreach ($menuItems as $id => $m) {
    if ($m['is_condiment']) {
        continue;
    }
    $pc = $m['print_class'];
    $pickList[] = [
        'id'   => $id,
        'name' => $m['name'],
        'pc'   => ($pc !== null && isset($printClasses[$pc])) ? $printClasses[$pc] : '未分配岗位',
    ];
}

pageHeader('菜品点单统计', 'dish');
?>

<form class="panel" method="get" action="dish.php">
  <input type="hidden" name="go" value="1">
  <input type="hidden" name="mode" id="mode" value="<?= h($mode) ?>">
  <div class="row">
    <label>开始日期<input type="date" name="start" value="<?= h($start) ?>" required></label>
    <label>结束日期<input type="date" name="end" value="<?= h($end) ?>" required></label>
    <label>统计时段
      <select name="seg">
        <option value="total" <?= $seg === 'total' ? 'selected' : '' ?>>全天</option>
        <option value="day"   <?= $seg === 'day'   ? 'selected' : '' ?>>白天</option>
        <option value="night" <?= $seg === 'night' ? 'selected' : '' ?>>晚上</option>
      </select>
    </label>
    <label>岗位
      <select name="pc">
        <option value="" <?= $pcFilter === '' ? 'selected' : '' ?>>全部岗位（逐个列出）</option>
        <?php foreach ($printClasses as $pcId => $pcName): ?>
          <option value="<?= (int) $pcId ?>" <?= $pcFilter === (string) $pcId ? 'selected' : '' ?>>
            <?= h($pcName) ?></option>
        <?php endforeach; ?>
        <option value="none" <?= $pcFilter === 'none' ? 'selected' : '' ?>>未分配岗位</option>
      </select>
    </label>
  </div>

  <div class="row">
    <label class="grow">查询指定菜品（留空则显示排行榜）
      <div class="combo">
        <input type="text" id="itemSearch" autocomplete="off" placeholder="输入菜名或编号搜索，也可直接点开下拉选择"
               value="<?= h($itemId > 0 && isset($menuItems[$itemId]) ? $menuItems[$itemId]['name'] : '') ?>">
        <input type="hidden" name="item_id" id="itemId" value="<?= $itemId > 0 ? (int) $itemId : '' ?>">
        <button type="button" class="clear" id="itemClear" title="清空">×</button>
        <ul id="itemList" class="combo-list" hidden></ul>
      </div>
    </label>
    <button type="submit">查询</button>
  </div>

  <div class="row opts">
    <label class="cb"><input type="checkbox" name="with_live" value="1" <?= $withLive ? 'checked' : '' ?>>
      包含当天未日结数据</label>
    <label class="cb"><input type="checkbox" name="include_combo_child" value="1" <?= $includeCombo ? 'checked' : '' ?>>
      计入套餐内子菜品</label>
    <label class="cb"><input type="checkbox" name="show_never" value="1" <?= $showNever ? 'checked' : '' ?>>
      显示零点单菜品</label>
    <span class="hint">日期跨度上限 <?= (int) Db::config()['max_range_days'] ?> 天</span>
  </div>
  <?php presetLinks('dish.php'); ?>
</form>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($itemRows !== null):
  $nm = $menuItems[$itemId]['name'] ?? ('#' . $itemId);
  $pc = $menuItems[$itemId]['print_class'] ?? null;
  $pcName = ($pc !== null && isset($printClasses[$pc])) ? $printClasses[$pc] : '未分配岗位';
  $T = $itemRows['total'];
?>
  <h2><?= h($nm) ?> <span class="tag"><?= h($pcName) ?></span></h2>

  <section class="cards">
    <div class="card day"><h3>白天点单</h3><div class="big"><?= qty($T['day']) ?></div></div>
    <div class="card night"><h3>晚上点单</h3><div class="big"><?= qty($T['night']) ?></div></div>
    <div class="card total"><h3>合计点单</h3><div class="big"><?= qty($T['total']) ?></div>
      <dl><dt>下单次数</dt><dd><?= num($T['times']) ?></dd>
          <dt>金额</dt><dd><?= money($T['amount']) ?></dd></dl></div>
  </section>

  <?php if (!$itemRows['days']): ?>
    <p class="empty">该菜品在所选范围内没有点单记录。</p>
  <?php else: ?>
  <div class="tablewrap">
  <table class="grid stick">
    <thead><tr><th>营业日</th><th class="n">白天</th><th class="n">晚上</th>
      <?php if ($T['gap'] > 0): ?><th class="n">时段外</th><?php endif; ?>
      <th class="n">合计</th><th class="n">下单次数</th><th class="n">金额</th></tr></thead>
    <tbody>
    <?php foreach ($itemRows['days'] as $d => $c): ?>
      <tr>
        <td class="date"><?= h($d) ?></td>
        <td class="n"><?= qty($c['day']) ?></td>
        <td class="n"><?= qty($c['night']) ?></td>
        <?php if ($T['gap'] > 0): ?><td class="n"><?= qty($c['gap']) ?></td><?php endif; ?>
        <td class="n strong"><?= qty($c['total']) ?></td>
        <td class="n"><?= num($c['times']) ?></td>
        <td class="n"><?= money($c['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th>合计</th><th class="n"><?= qty($T['day']) ?></th><th class="n"><?= qty($T['night']) ?></th>
      <?php if ($T['gap'] > 0): ?><th class="n"><?= qty($T['gap']) ?></th><?php endif; ?>
      <th class="n"><?= qty($T['total']) ?></th><th class="n"><?= num($T['times']) ?></th>
      <th class="n"><?= money($T['amount']) ?></th>
    </tr></tfoot>
  </table>
  </div>
  <?php endif; ?>

<?php elseif ($dishes !== null):
  $items = $dishes['items'];
  $segLabel = ['day' => '白天', 'night' => '晚上', 'total' => '全天'][$seg];

  // 选了具体岗位就只统计该岗位的菜品，否则统计全店
  $scoped   = $pcFilter === '' ? $items : Report::filterByStation($items, $pcFilter);
  $scopeName = $pcFilter === ''
      ? '全店'
      : ($pcFilter === 'none' ? '未分配岗位' : ($printClasses[(int) $pcFilter] ?? ('岗位#' . $pcFilter)));

  $top    = Report::rank($scoped, $seg, 'desc', 10);
  $bottom = Report::rank($scoped, $seg, 'asc', 10);
  $summary  = Report::stationSummary($items, $seg);
  $stations = $pcFilter === '' ? Report::byStation($items, $seg, 10) : [];

  $g = ['qty' => 0.0, 'times' => 0, 'amount' => 0.0];
  foreach ($scoped as $it) {
      $g['qty']    += $it[$seg]['qty'];
      $g['times']  += $it[$seg]['times'];
      $g['amount'] += $it[$seg]['amount'];
  }
?>
  <p class="meta">
    统计时段：<strong><?= h($segLabel) ?></strong>　|
    统计范围：<strong><?= h($scopeName) ?></strong>　|
    有点单记录的菜品 <strong><?= num(count(array_filter($scoped, fn($i) => $i[$seg]['qty'] > 0))) ?></strong> 个　|
    总点单量 <strong><?= qty($g['qty']) ?></strong>　|
    总下单次数 <?= num($g['times']) ?>
  </p>

  <h2><?= h($scopeName) ?> —— 点单最多的 10 个菜品</h2>
  <?= renderRank($top, $seg, 'top') ?>

  <h2><?= h($scopeName) ?> —— 点单最少的 10 个菜品</h2>
  <p class="note">仅统计范围内<strong>有过点单记录</strong>的菜品。完全没被点过的菜品请勾选「显示零点单菜品」。</p>
  <?= renderRank($bottom, $seg, 'bottom') ?>

  <h2>岗位汇总</h2>
  <?php if (!$summary): ?>
    <p class="empty">所选范围内没有任何岗位的点单记录。</p>
  <?php else: ?>
    <div class="tablewrap">
    <table class="grid stick">
      <thead><tr><th>岗位</th><th class="n">菜品数</th><th class="n">点单量</th><th class="n">占比</th><th class="n">下单次数</th><th class="n">金额</th><th>操作</th></tr></thead>
      <tbody>
      <?php $allQty = array_sum(array_column($summary, 'qty')); ?>
      <?php foreach ($summary as $s): ?>
        <tr>
          <td><strong><?= h($s['pc_name']) ?></strong></td>
          <td class="n"><?= num($s['items']) ?></td>
          <td class="n strong"><?= qty($s['qty']) ?></td>
          <td class="n"><?= $allQty > 0 ? number_format($s['qty'] / $allQty * 100, 1) . '%' : '—' ?></td>
          <td class="n"><?= num($s['times']) ?></td>
          <td class="n"><?= money($s['amount']) ?></td>
          <td><a href="<?= h(stationLink($s['pc'])) ?>">只看这个岗位</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <th>合计 <?= num(count($summary)) ?> 个岗位</th>
        <th class="n"><?= num(array_sum(array_column($summary, 'items'))) ?></th>
        <th class="n"><?= qty($allQty) ?></th>
        <th class="n">100%</th>
        <th class="n"><?= num(array_sum(array_column($summary, 'times'))) ?></th>
        <th class="n"><?= money(array_sum(array_column($summary, 'amount'))) ?></th>
        <th></th>
      </tr></tfoot>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($pcFilter === ''): ?>
    <h2>各岗位点单排行（最多 / 最少各 10 个）</h2>
    <?php if (!$stations): ?>
      <p class="empty">所选范围内没有任何岗位的点单记录。</p>
    <?php else: ?>
      <?php foreach ($stations as $st): ?>
        <details class="station" open>
          <summary><span class="pcname"><?= h($st['pc_name']) ?></span>
            <span class="pcmeta"><?= num($st['items']) ?> 个菜品 / 点单 <?= qty($st['qty']) ?></span></summary>
          <div class="cols">
            <div><h4>最多 10 个</h4><?= renderRank($st['top'], $seg, 'top') ?></div>
            <div><h4>最少 10 个</h4><?= renderRank($st['bottom'], $seg, 'bottom') ?></div>
          </div>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php else: ?>
    <p class="note">当前只统计「<?= h($scopeName) ?>」。
      把岗位切回<a href="<?= h(stationLink('')) ?>">「全部岗位」</a>可以逐个查看每个岗位的排行。</p>
  <?php endif; ?>

  <?php if ($showNever):
      $never = Report::neverOrdered($menuItems, $items, $printClasses); ?>
    <h2>零点单菜品（<?= num(count($never)) ?> 个）</h2>
    <?php if (!$never): ?><p class="empty">所有菜品在此范围内都有点单。</p><?php else: ?>
    <div class="tablewrap">
    <table class="grid small stick">
      <thead><tr><th class="n">编号</th><th>菜品</th><th>岗位</th></tr></thead>
      <tbody>
      <?php foreach ($never as $n): ?>
        <tr><td class="n dim"><?= (int) $n['id'] ?></td>
            <td class="iname"><span title="<?= h($n['name']) ?>"><?= h($n['name']) ?></span></td>
            <td><?= h($n['pc_name']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($meta): ?>
  <p class="meta">
    统计区间 <?= h($meta['range'][0]) ?> ~ <?= h($meta['range'][1]) ?>　|
    <?php foreach ($meta['queries'] as [$t, $n, $ms]): ?>
      <code><?= h($t) ?></code> <?= (int) $n ?> 组 / <?= number_format($ms * 1000, 0) ?>ms
    <?php endforeach; ?>
  </p>
  <?php endif; ?>

<?php elseif (!$error): ?>
  <p class="empty">请选择日期范围后点击查询。留空菜品搜索框可查看排行榜。</p>
<?php endif; ?>

<script>
// 菜名来自数据库（店员随手填的），内嵌进 <script> 时必须把 < > & ' " 转成 \uXXXX：
// json_encode 默认只转义 /，菜名里的 <script> 会原样落进来，离「被标签截断」只差一步。
window.MENU_ITEMS = <?= json_encode($pickList, JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/app.js"></script>

<?php
pageFooter();

/** 排行榜表格 */
function renderRank(array $list, string $seg, string $kind): string
{
    if (!$list) {
        return '<p class="empty">无数据。</p>';
    }
    $max = 0.0;
    foreach ($list as $it) {
        $max = max($max, (float) $it[$seg]['qty']);
    }
    $h = '<div class="tablewrap"><table class="grid rank stick"><thead><tr>'
       . '<th class="n">#</th><th>菜品</th><th class="hide-sm">岗位</th><th class="n">点单量</th>'
       . '<th class="n hide-sm">下单次数</th><th class="n">金额</th><th class="barcol"></th>'
       . '</tr></thead><tbody>';
    foreach ($list as $i => $it) {
        $c = $it[$seg];
        $w = $max > 0 ? max(2, (int) round($c['qty'] / $max * 100)) : 0;
        $h .= '<tr>'
            . '<td class="n dim">' . ($i + 1) . '</td>'
            // 菜名过长会把表格撑开，用 CSS 截断并加 title，鼠标悬停可看全名
            . '<td class="iname"><a href="' . h(rankLink((int) $it['id'])) . '"'
            . ' title="' . h($it['name']) . '">' . h($it['name']) . '</a></td>'
            . '<td class="dim hide-sm">' . h($it['pc_name']) . '</td>'
            . '<td class="n strong">' . qty($c['qty']) . '</td>'
            . '<td class="n hide-sm">' . num($c['times']) . '</td>'
            . '<td class="n">' . money($c['amount']) . '</td>'
            . '<td class="barcol"><span class="bar ' . h($kind) . '" style="width:' . $w . '%"></span></td>'
            . '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** 生成"查看该菜品每日明细"的链接，保留当前筛选条件 */
function rankLink(int $itemId): string
{
    $qs = $_GET;
    $qs['mode']    = 'item';
    $qs['item_id'] = $itemId;
    $qs['go']      = '1';
    return 'dish.php?' . http_build_query($qs);
}

/** 生成"只看某个岗位"的链接，保留当前日期与时段 */
function stationLink($pc): string
{
    $qs = $_GET;
    $qs['pc']   = $pc === null ? 'none' : (string) $pc;
    $qs['mode'] = 'rank';
    $qs['go']   = '1';
    unset($qs['item_id']);
    return 'dish.php?' . http_build_query($qs);
}
