<?php
/**
 * 营业额 / 人数统计
 *
 * 本页只查询账单头表：history_order_head（+ 可选 order_head），不做任何 JOIN。
 * 菜品统计请见 dish.php —— 两者数据来源完全分开，避免一次扫描过多数据。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$today = date('Y-m-d', time() - Db::config()['day_cut_hour'] * 3600);
$start = q('start', $today);
$end   = q('end', $today);
$eat   = q('eat_type');
$excludeZero = qbool('exclude_zero');
$withLive    = !isset($_GET['go']) || qbool('with_live');

$error = null;
$data  = null;
$meta  = [];

if (isset($_GET['go'])) {
    $error = Biz::validateRange($start, $end);
    if ($error === null) {
        try {
            [$from, $to] = Biz::range($start, $end);
            $opts = ['eat_type' => $eat, 'exclude_zero' => $excludeZero];

            $t0 = microtime(true);
            // 第一张表：历史（已日结）账单头
            $hist = Biz::salesByDay($from, $to, 'history_order_head', $opts);
            $meta['queries'][] = ['history_order_head', count($hist), microtime(true) - $t0];

            // 第二张表：实时（未日结）账单头 —— 仅当范围覆盖到最近时才查
            $live = [];
            if ($withLive && Biz::needLiveTables($to)) {
                $t1 = microtime(true);
                $live = Biz::salesByDay($from, $to, 'order_head', $opts);
                $meta['queries'][] = ['order_head', count($live), microtime(true) - $t1];
            }

            $data = Report::pivotSales($hist, $live);
            $meta['range'] = [$from, $to];
        } catch (Throwable $e) {
            $error = '查询失败：' . $e->getMessage();
        }
    }
}

pageHeader('营业额统计', 'sales');
?>

<form class="panel" method="get" action="index.php">
  <input type="hidden" name="go" value="1">
  <div class="row">
    <label>开始日期<input type="date" name="start" value="<?= h($start) ?>" required></label>
    <label>结束日期<input type="date" name="end" value="<?= h($end) ?>" required></label>
    <label>就餐方式
      <select name="eat_type">
        <option value=""  <?= $eat === ''  ? 'selected' : '' ?>>全部</option>
        <option value="0" <?= $eat === '0' ? 'selected' : '' ?>>堂食</option>
        <option value="3" <?= $eat === '3' ? 'selected' : '' ?>>外带 (Llevar)</option>
      </select>
    </label>
    <button type="submit">查询</button>
  </div>
  <div class="row opts">
    <label class="cb"><input type="checkbox" name="with_live" value="1" <?= $withLive ? 'checked' : '' ?>>
      包含当天未日结数据</label>
    <label class="cb"><input type="checkbox" name="exclude_zero" value="1" <?= $excludeZero ? 'checked' : '' ?>>
      排除 0 元账单</label>
    <span class="hint">日期跨度上限 <?= (int) Db::config()['max_range_days'] ?> 天</span>
  </div>
  <?php presetLinks('index.php'); ?>
</form>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($data): ?>
  <?php
  $T = $data['total'];
  $segs = ['day' => '白天', 'night' => '晚上', 'total' => '全天'];
  // 时段外只有真的有数据时才显示，避免干扰
  $showGap = $T['gap']['checks'] > 0;
  ?>

  <section class="cards">
    <?php foreach (['day', 'night', 'gap', 'total'] as $s):
        if ($s === 'gap' && !$showGap) continue;
        $c = $T[$s];
        $label = $s === 'total' ? '全天合计' : Biz::segLabel($s);
    ?>
    <div class="card <?= $s ?>">
      <h3><?= h($label) ?></h3>
      <div class="big"><?= money($c['actual']) ?></div>
      <dl>
        <dt>人数</dt><dd><?= num($c['guests']) ?></dd>
        <dt>账单数</dt><dd><?= num($c['checks']) ?></dd>
        <dt>人均</dt><dd><?= perGuest($c) ?></dd>
        <dt>单均</dt><dd><?= perCheck($c) ?></dd>
      </dl>
    </div>
    <?php endforeach; ?>
  </section>

  <h2>每日明细</h2>
  <div class="tablewrap">
  <table class="grid">
    <thead>
      <tr>
        <th rowspan="2">营业日</th>
        <th colspan="3" class="c">白天 <span class="sub"><?= h(substr(Db::config()['day_start'], 0, 5)) ?>–<?= h(substr(Db::config()['day_end'], 0, 5)) ?></span></th>
        <th colspan="3" class="c">晚上 <span class="sub"><?= h(substr(Db::config()['night_start'], 0, 5)) ?>–次日<?= h(substr(Db::config()['night_end'], 0, 5)) ?></span></th>
        <?php if ($showGap): ?><th colspan="3" class="c">时段外</th><?php endif; ?>
        <th colspan="4" class="c">全天</th>
      </tr>
      <tr>
        <th class="n">营业额</th><th class="n">人数</th><th class="n">单数</th>
        <th class="n">营业额</th><th class="n">人数</th><th class="n">单数</th>
        <?php if ($showGap): ?><th class="n">营业额</th><th class="n">人数</th><th class="n">单数</th><?php endif; ?>
        <th class="n">营业额</th><th class="n">人数</th><th class="n">单数</th><th class="n">人均</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($data['days'] as $d => $cells): ?>
      <tr>
        <td class="date"><?= h($d) ?> <span class="wd"><?= ['日','一','二','三','四','五','六'][(int) date('w', strtotime($d))] ?></span></td>
        <?php foreach (($showGap ? ['day','night','gap','total'] : ['day','night','total']) as $s):
              $c = $cells[$s]; ?>
          <td class="n"><?= money($c['actual']) ?></td>
          <td class="n"><?= num($c['guests']) ?></td>
          <td class="n"><?= num($c['checks']) ?></td>
          <?php if ($s === 'total'): ?><td class="n"><?= perGuest($c) ?></td><?php endif; ?>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>合计</th>
        <?php foreach (($showGap ? ['day','night','gap','total'] : ['day','night','total']) as $s):
              $c = $T[$s]; ?>
          <th class="n"><?= money($c['actual']) ?></th>
          <th class="n"><?= num($c['guests']) ?></th>
          <th class="n"><?= num($c['checks']) ?></th>
          <?php if ($s === 'total'): ?><th class="n"><?= perGuest($c) ?></th><?php endif; ?>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
  </div>

  <h2>金额构成（全时段合计）</h2>
  <div class="tablewrap">
  <table class="grid small">
    <thead><tr>
      <th>时段</th>
      <th class="n">原价合计</th><th class="n">折扣</th><th class="n">服务费</th>
      <th class="n">应收</th><th class="n">退单</th>
      <th class="n">实收（营业额）</th><th class="n">其中含税</th>
    </tr></thead>
    <tbody>
    <?php foreach (['day','night','gap','total'] as $s):
        if ($s === 'gap' && !$showGap) continue;
        $c = $T[$s]; ?>
      <tr <?= $s === 'total' ? 'class="sum"' : '' ?>>
        <td><?= $s === 'total' ? '全天' : h(Biz::segLabel($s)) ?></td>
        <td class="n"><?= money($c['original']) ?></td>
        <td class="n"><?= money($c['discount']) ?></td>
        <td class="n"><?= money($c['service']) ?></td>
        <td class="n"><?= money($c['should_amt']) ?></td>
        <td class="n"><?= money($c['ret']) ?></td>
        <td class="n strong"><?= money($c['actual']) ?></td>
        <td class="n dim"><?= money($c['tax']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="note">
    金额关系：<code>应收 = 原价合计 + 折扣</code>（折扣以负数记录），<code>实收 = 应收 − 退单</code>。
    税额为<strong>价内含税</strong>，已包含在实收里，不另外相加。
  </p>

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
