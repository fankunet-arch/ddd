<?php
/**
 * 岗位（打印机）单量排名
 *
 * 「单量」= 该岗位出品涉及了多少张单（多少桌）。一张单里同岗位点了几个菜也只算一单。
 *
 * 本页只查询明细表：history_order_detail（+ 可选 order_detail），不做任何 JOIN。
 * 岗位映射来自 menu_item / print_class 两张小字典表，编译进 SQL 的 CASE 表达式，
 * 因此数据库端直接返回十几行汇总，不需要把明细拉回 PHP。
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
$sort  = q('sort', 'orders');           // 按哪个指标排名
$includeCombo = qbool('include_combo_child');
$withLive     = !isset($_GET['go']) || qbool('with_live');

if (!in_array($sort, ['orders', 'qty', 'amount', 'lines'], true)) {
    $sort = 'orders';
}

$error = null;
$rows  = null;
$meta  = [];
$printClasses = [];

try {
    $menuItems    = Biz::menuItems();
    $printClasses = Biz::printClasses();
} catch (Throwable $e) {
    $error = '读取字典失败：' . $e->getMessage();
}

if (!$error && isset($_GET['go'])) {
    $error = Biz::validateRange($start, $end);
    if ($error === null) {
        try {
            [$from, $to] = Biz::range($start, $end);
            $opts = ['include_combo_child' => $includeCombo];

            // 菜品 → 岗位映射（做法/口味项不算菜，先剔除）
            $pcOfItem = [];
            foreach ($menuItems as $id => $m) {
                if (!$m['is_condiment']) {
                    $pcOfItem[$id] = $m['print_class'];
                }
            }

            $t0  = microtime(true);
            $res = Biz::stationVolume($from, $to, 'history_order_detail', $pcOfItem, $opts);
            $meta['queries'][] = ['history_order_detail', count($res), microtime(true) - $t0];

            $live = [];
            if ($withLive && Biz::needLiveTables($to)) {
                $t1   = microtime(true);
                $live = Biz::stationVolume($from, $to, 'order_detail', $pcOfItem, $opts);
                $meta['queries'][] = ['order_detail', count($live), microtime(true) - $t1];
            }

            $rows = Report::buildStations($printClasses, $res, $live);
            $meta['range'] = [$from, $to];
        } catch (Throwable $e) {
            $error = '查询失败：' . $e->getMessage();
        }
    }
}

pageHeader('岗位单量排名', 'station');
?>

<form class="panel" method="get" action="station.php">
  <input type="hidden" name="go" value="1">
  <div class="row">
    <label>开始日期<input type="date" name="start" value="<?= h($start) ?>" required></label>
    <label>结束日期<input type="date" name="end" value="<?= h($end) ?>" required></label>
    <label>排名依据
      <select name="sort">
        <option value="orders" <?= $sort === 'orders' ? 'selected' : '' ?>>单量（涉及多少张单）</option>
        <option value="qty"    <?= $sort === 'qty'    ? 'selected' : '' ?>>出品份数</option>
        <option value="lines"  <?= $sort === 'lines'  ? 'selected' : '' ?>>出品笔数</option>
        <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>金额</option>
      </select>
    </label>
    <button type="submit">查询</button>
  </div>
  <div class="row opts">
    <label class="cb"><input type="checkbox" name="with_live" value="1" <?= $withLive ? 'checked' : '' ?>>
      包含当天未日结数据</label>
    <label class="cb"><input type="checkbox" name="include_combo_child" value="1" <?= $includeCombo ? 'checked' : '' ?>>
      计入套餐内子菜品</label>
    <span class="hint">日期跨度上限 <?= (int) Db::config()['max_range_days'] ?> 天</span>
  </div>
  <?php presetLinks('station.php'); ?>
</form>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($rows !== null):
  $list  = Report::sortStations($rows['stations'], $sort);
  $G     = $rows['grand'];
  $label = ['orders' => '单量', 'qty' => '出品份数', 'lines' => '出品笔数', 'amount' => '金额'][$sort];
  $maxV  = $list ? max(array_map(fn($s) => $s['total'][$sort], $list)) : 0;
  $showGap = $G['gap']['orders'] > 0;
?>
  <p class="note">
    <strong>单量</strong>指该岗位出品涉及了多少张单（多少桌）——一张单里同岗位点了几个菜也只算一单，
    所以各岗位单量之和会大于总单数（一张单通常会经过多个岗位）。
    <strong>出品笔数</strong>是明细行数，<strong>出品份数</strong>是数量合计。
  </p>

  <?php if (!$list): ?>
    <p class="empty">所选范围内没有出品记录。</p>
  <?php else: ?>

  <h2>岗位排名 —— 按<?= h($label) ?></h2>
  <div class="tablewrap">
  <table class="grid rank">
    <thead><tr>
      <th class="n">#</th><th>岗位（打印机）</th>
      <th class="n">白天单量</th><th class="n">晚上单量</th>
      <?php if ($showGap): ?><th class="n">时段外</th><?php endif; ?>
      <th class="n">全天单量</th><th class="n">占比</th>
      <th class="n">出品份数</th><th class="n">出品笔数</th><th class="n">菜品数</th><th class="n">金额</th>
      <th class="barcol"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $i => $s):
        $T = $s['total'];
        $w = $maxV > 0 ? max(2, (int) round($T[$sort] / $maxV * 100)) : 0; ?>
      <tr>
        <td class="n dim"><?= $i + 1 ?></td>
        <td class="iname"><span title="<?= h($s['pc_name']) ?>"><strong><?= h($s['pc_name']) ?></strong></span></td>
        <td class="n"><?= num($s['day']['orders']) ?></td>
        <td class="n"><?= num($s['night']['orders']) ?></td>
        <?php if ($showGap): ?><td class="n"><?= num($s['gap']['orders']) ?></td><?php endif; ?>
        <td class="n strong"><?= num($T['orders']) ?></td>
        <td class="n dim"><?= $G['total']['orders'] > 0
              ? number_format($T['orders'] / $G['total']['orders'] * 100, 1) . '%' : '—' ?></td>
        <td class="n"><?= qty($T['qty']) ?></td>
        <td class="n"><?= num($T['lines']) ?></td>
        <td class="n dim"><?= num($T['items']) ?></td>
        <td class="n"><?= money($T['amount']) ?></td>
        <td class="barcol"><span class="bar top" style="width:<?= $w ?>%"></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th></th><th>合计 <?= num(count($list)) ?> 个岗位</th>
      <th class="n"><?= num($G['day']['orders']) ?></th>
      <th class="n"><?= num($G['night']['orders']) ?></th>
      <?php if ($showGap): ?><th class="n"><?= num($G['gap']['orders']) ?></th><?php endif; ?>
      <th class="n"><?= num($G['total']['orders']) ?></th>
      <th class="n">—</th>
      <th class="n"><?= qty($G['total']['qty']) ?></th>
      <th class="n"><?= num($G['total']['lines']) ?></th>
      <th class="n">—</th>
      <th class="n"><?= money($G['total']['amount']) ?></th>
      <th></th>
    </tr></tfoot>
  </table>
  </div>
  <p class="note">
    合计行的单量是各岗位相加，同一张单经过多个岗位会被重复计入，因此<strong>不等于</strong>总账单数；
    「菜品数」是各岗位各自的去重菜品数，也不能相加，故合计处留空。
    营业额请以<a href="index.php">营业额统计</a>页为准。
  </p>

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
  <p class="empty">请选择日期范围后点击查询。</p>
<?php endif; ?>

<?php pageFooter(); ?>
