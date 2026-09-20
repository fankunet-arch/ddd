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
// 默认按【票数】排 —— 自助餐几乎每桌都会点到每个档口，
// 按桌数排的话各岗位挤成一团（实测 117/110/107/105/105/103/100/93/82），
// 排出来的顺序几乎没有信息量；票数的差距才真实（243 … 117）。
$sort  = q('sort', 'tickets');
$includeCombo = qbool('include_combo_child');
$withLive     = !isset($_GET['go']) || qbool('with_live');

if (!in_array($sort, ['tickets', 'orders', 'qty', 'amount', 'lines'], true)) {
    $sort = 'tickets';
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
        <option value="tickets" <?= $sort === 'tickets' ? 'selected' : '' ?>>票数（出了多少张单据）</option>
        <option value="orders"  <?= $sort === 'orders'  ? 'selected' : '' ?>>桌数（涉及多少张账单）</option>
        <option value="qty"     <?= $sort === 'qty'     ? 'selected' : '' ?>>份数</option>
        <option value="lines"   <?= $sort === 'lines'   ? 'selected' : '' ?>>行数</option>
        <option value="amount"  <?= $sort === 'amount'  ? 'selected' : '' ?>>金额</option>
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
  $label = ['tickets' => '票数', 'orders' => '桌数', 'qty' => '份数',
            'lines' => '行数', 'amount' => '金额'][$sort];
  $maxV  = $list ? max(array_map(fn($s) => $s['total'][$sort], $list)) : 0;
  $showGap = $G['gap']['orders'] > 0;
  // 金额压在极少数岗位上时要说明白。自助餐就是这样：菜本身 0 元，
  // 钱记在套餐那一行上，于是「按金额给出品岗位排名」毫无意义。
  // 不写死「自助餐」三个字，而是按实际数据判断 —— 别家店未必这样。
  $topAmt = 0.0;
  foreach ($list as $s2) { $topAmt = max($topAmt, (float) $s2['total']['amount']); }
  $amtSkew = $G['total']['amount'] > 0 && $topAmt / $G['total']['amount'] >= 0.9;
?>
  <p class="note">
    <strong>票数</strong>是该岗位的打印机出了多少张单据 ——
    一桌分三次下单，这个岗位就出三张票，算 3。
    <strong>桌数</strong>是涉及多少张账单，同一桌无论下单几次都只算 1。
    <strong>份数</strong>是数量合计（一行「拉面 ×2」算 2），<strong>行数</strong>是明细行数（算 1）。
    <br>
    各岗位的桌数与票数之和都会大于实际总数 —— 一次下单通常会经过好几个岗位
    （实测平均 2.9 个），每个岗位各自计一次。
  </p>

  <?php if ($amtSkew): ?>
    <p class="note" style="border-left:3px solid #e0b040;padding-left:10px">
      ⚠️ <strong>这个范围里 <?= number_format($topAmt / $G['total']['amount'] * 100, 1) ?>%
      的金额集中在一个岗位上，所以「金额」这一列不能用来比较出品岗位。</strong>
      自助餐就是这种形态：菜本身是 0 元，钱记在套餐那一行上，
      而套餐挂在哪个岗位，钱就全算给哪个岗位。
      要比较各档口的忙闲，请看<strong>票数</strong>或<strong>份数</strong>。
      营业额请以<a href="index.php">营业额统计</a>页为准。
    </p>
  <?php endif; ?>

  <?php if (!$list): ?>
    <p class="empty">所选范围内没有出品记录。</p>
  <?php else: ?>

  <h2>岗位排名 —— 按<?= h($label) ?></h2>
  <div class="tablewrap">
  <table class="grid rank stick">
    <thead><tr>
      <th class="n">#</th><th>岗位（打印机）</th>
      <th class="n hide-sm">白天票数</th><th class="n hide-sm">晚上票数</th>
      <?php if ($showGap): ?><th class="n">时段外</th><?php endif; ?>
      <th class="n">全天票数</th><th class="n">占比</th>
      <th class="n">桌数</th><th class="n hide-sm">每桌票数</th>
      <th class="n">份数</th><th class="n hide-sm">行数</th><th class="n hide-sm">菜品数</th><th class="n">金额</th>
      <th class="barcol"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $i => $s):
        $T = $s['total'];
        $w = $maxV > 0 ? max(2, (int) round($T[$sort] / $maxV * 100)) : 0; ?>
      <tr>
        <td class="n dim"><?= $i + 1 ?></td>
        <td class="iname"><span title="<?= h($s['pc_name']) ?>"><strong><?= h($s['pc_name']) ?></strong></span></td>
        <td class="n hide-sm"><?= num($s['day']['tickets']) ?></td>
        <td class="n hide-sm"><?= num($s['night']['tickets']) ?></td>
        <?php if ($showGap): ?><td class="n"><?= num($s['gap']['tickets']) ?></td><?php endif; ?>
        <td class="n strong"><?= num($T['tickets']) ?></td>
        <td class="n dim"><?= $G['total']['tickets'] > 0
              ? number_format($T['tickets'] / $G['total']['tickets'] * 100, 1) . '%' : '—' ?></td>
        <td class="n"><?= num($T['orders']) ?></td>
        <td class="n dim hide-sm"><?= $T['orders'] > 0
              ? number_format($T['tickets'] / $T['orders'], 2) : '—' ?></td>
        <td class="n"><?= qty($T['qty']) ?></td>
        <td class="n hide-sm"><?= num($T['lines']) ?></td>
        <td class="n dim hide-sm"><?= num($T['items']) ?></td>
        <td class="n"><?= money($T['amount']) ?></td>
        <td class="barcol"><span class="bar top" style="width:<?= $w ?>%"></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th></th><th>合计 <?= num(count($list)) ?> 个岗位</th>
      <th class="n hide-sm"><?= num($G['day']['tickets']) ?></th>
      <th class="n hide-sm"><?= num($G['night']['tickets']) ?></th>
      <?php if ($showGap): ?><th class="n"><?= num($G['gap']['tickets']) ?></th><?php endif; ?>
      <th class="n"><?= num($G['total']['tickets']) ?></th>
      <th class="n">—</th>
      <th class="n"><?= num($G['total']['orders']) ?></th>
      <th class="n hide-sm">—</th>
      <th class="n"><?= qty($G['total']['qty']) ?></th>
      <th class="n hide-sm"><?= num($G['total']['lines']) ?></th>
      <th class="n hide-sm">—</th>
      <th class="n"><?= money($G['total']['amount']) ?></th>
      <th></th>
    </tr></tfoot>
  </table>
  </div>
  <p class="note">
    合计行的票数和桌数都是各岗位相加，一次下单经过多个岗位会被重复计入，
    因此<strong>不等于</strong>实际的总票数和总账单数；
    「菜品数」「每桌票数」是各岗位各自算的，也不能相加，故合计处留空。
    营业额请以<a href="index.php">营业额统计</a>页为准。
    <br>
    <strong>票数是推算出来的</strong>：数据库没有打印记录，靠「同一张单里同一秒写进去的菜
    算一次下单」还原（已在真实数据上验证，见 <code>tests/probe_ticket.php</code>）。
    <strong>重打的单和手工补打的单数不到</strong> —— 数据库里没有痕迹。
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
