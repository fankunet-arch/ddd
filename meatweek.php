<?php
/**
 * 肉类采购 —— 周报表
 *
 * 回答三个问题：
 *   1. 每周各品类用了多少（重量、金额）
 *   2. 人均用量 —— 每位客人摊多少克，用来看有没有异常浪费
 *   3. 食材成本占营业额多少
 *
 * ⚠️ 两边数据是【分别查、在 PHP 里按周合并】的，不做 JOIN（见「注意事项.md」铁律二）：
 *     采购  ← 自有 SQLite（Store）
 *     客人数、营业额 ← POS 主库（Db，只读）
 *
 * ⚠️ 口径上最要紧的一件事：我们记的是【采购】，不是【消耗】。
 *    用多少买多少的时候两者约等；一旦开始备货，某周进一大批、下周不进，
 *    单看每周就会忽高忽低 —— 看起来像浪费，其实只是进货节奏。
 *    所以页面同时给「当周」和「近 4 周滚动平均」，并把这个前提写在最显眼处。
 *    要得到真实消耗，得再加每周盘点：消耗 = 上周末库存 + 本周采购 − 本周末库存。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/meat.php';
require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$cfg   = Db::config();
$today = date('Y-m-d', time() - (int) $cfg['day_cut_hour'] * 3600);
$kinds = Meat::kinds();

// 周数：12 周 = 84 天，仍在 92 天的查询上限内
$weeksOpt = [4 => '近 4 周', 8 => '近 8 周', 12 => '近 12 周'];
$nWeeks   = (int) q('weeks', '8');
if (!isset($weeksOpt[$nWeeks])) {
    $nWeeks = 8;
}

$weeks    = Meat::recentWeeks($nWeeks, $today);
[$rangeFrom, ] = Meat::weekRange($weeks[0]);
[, $rangeTo]   = Meat::weekRange($weeks[count($weeks) - 1]);
$curWeek  = Meat::weekKey($today);

$storeErr = null;
$posErr   = null;
$byWeek   = [];
$uw       = [];
$pos      = [];          // 周 => ['guests'=>, 'actual'=>]
$meta     = [];

// ---- 自有数据：采购 ----
if (!Store::isReady()) {
    $storeErr = Store::lastError();
} else {
    try {
        $t0   = microtime(true);
        $rows = Meat::listRows(['from' => $rangeFrom, 'to' => $rangeTo]);
        $uw   = Meat::unitWeights();
        $meta['queries'][] = ['自有 SQLite · 采购', count($rows), microtime(true) - $t0];
        $byWeek = Meat::weekly($rows, $uw);
    } catch (Throwable $e) {
        $storeErr = $e->getMessage();
    }
}

// ---- POS 主库：客人数与营业额（只读，与上面完全分开的两次查询）----
// 主库连不上时不该让整页打不开 —— 采购数据是自有的，照常显示，
// 只是人均和成本占比算不出来。
try {
    [$from, $to] = Biz::range($rangeFrom, $rangeTo);
    $t1   = microtime(true);
    $hist = Biz::salesByDay($from, $to, 'history_order_head');
    $meta['queries'][] = ['history_order_head', count($hist), microtime(true) - $t1];
    $live = [];
    if (Biz::needLiveTables($to)) {
        $t2   = microtime(true);
        $live = Biz::salesByDay($from, $to, 'order_head');
        $meta['queries'][] = ['order_head', count($live), microtime(true) - $t2];
    }
    // 先按营业日合并两张表，再按周汇总
    $daily = Report::pivotSales($hist, $live)['days'];
    foreach ($daily as $d => $cells) {
        $wk = Meat::weekKey((string) $d);
        if (!isset($pos[$wk])) {
            $pos[$wk] = ['guests' => 0, 'actual' => 0.0, 'days' => 0];
        }
        $pos[$wk]['guests'] += (int) $cells['total']['guests'];
        $pos[$wk]['actual'] += (float) $cells['total']['actual'];
        $pos[$wk]['days']++;
    }
} catch (Throwable $e) {
    $posErr = $e->getMessage();
}

// ---- 合并成每周一行 ----
$series = [];        // 周 => 人均克数，用来算滚动平均
$table  = [];
foreach ($weeks as $wk) {
    $m  = $byWeek[$wk] ?? ['kinds' => [], 'kg' => 0.0, 'kg_est' => 0.0, 'money' => 0.0,
                           'rows' => 0, 'est_rows' => 0, 'no_kg' => 0, 'no_money' => 0];
    $p  = $pos[$wk] ?? null;
    $kg = $m['kg'] + $m['kg_est'];
    $g  = $p['guests'] ?? 0;
    [$ws, $we] = Meat::weekRange($wk);
    $table[$wk] = [
        'key'      => $wk,
        'start'    => $ws,
        'end'      => $we,
        'partial'  => $wk === $curWeek,          // 本周还没过完
        'kinds'    => $m['kinds'],
        'kg'       => $kg,
        'kg_est'   => $m['kg_est'],
        'est_rows' => $m['est_rows'],
        'no_kg'    => $m['no_kg'],
        'money'    => $m['money'],
        'no_money' => $m['no_money'],
        'rows'     => $m['rows'],
        'guests'   => $g,
        'actual'   => $p['actual'] ?? null,
        // 那一周一条采购记录都没有时，人均和成本占比一律留空。
        // 显示 0 会被读成「这周一点肉都没用」，但真实含义是「没记」—— 两者差得很远。
        // 人均按【克】显示：0.15 kg/人 不如 150 g/人 直观
        'per_guest_g' => ($g > 0 && $m['rows'] > 0) ? $kg * 1000 / $g : null,
        'cost_pct'    => ($p !== null && $p['actual'] > 0 && $m['rows'] > 0)
                         ? $m['money'] / $p['actual'] : null,
    ];
    // 本周没过完，不进滚动平均 —— 否则会把均值拉低，下周就成了假的「暴涨」
    $series[$wk] = $table[$wk]['partial'] ? null : $table[$wk]['per_guest_g'];
}
$roll = Meat::rolling($series, 4);

// 合计的口径，两条：
//   1. 不含本周 —— 本周没过完，算进去会把周均和占比拉低。
//   2. 从【第一条采购记录所在的那一周】算起 —— 采购记录是从某天才开始记的
//      （早期数据靠翻发票补录），在那之前的空周不是「那周没买肉」，是「那周还没开始记」。
//      把它们算进分母，周均会被摊薄、占营业额会虚低。
//      中间的空周照常计入 —— 那种才可能是真的靠库存撑过去了。
$first = null;
foreach ($table as $wk => $r) {
    if ($r['rows'] > 0) { $first = $wk; break; }
}
$started = false;
$done = [];
foreach ($table as $wk => $r) {
    if ($wk === $first) { $started = true; }
    if ($started && !$r['partial']) { $done[$wk] = $r; }
}
$tot  = ['kg' => 0.0, 'money' => 0.0, 'guests' => 0, 'actual' => 0.0, 'rows' => 0];
foreach ($done as $r) {
    $tot['kg']     += $r['kg'];
    $tot['money']  += $r['money'];
    $tot['guests'] += $r['guests'];
    $tot['actual'] += (float) ($r['actual'] ?? 0);
    $tot['rows']   += $r['rows'];
}
// 同上：没有采购记录时不显示 0，显示「—」
$totPerGuest = ($tot['guests'] > 0 && $tot['rows'] > 0) ? $tot['kg'] * 1000 / $tot['guests'] : null;
$totCostPct  = ($tot['actual'] > 0 && $tot['rows'] > 0) ? $tot['money'] / $tot['actual'] : null;

pageHeader('肉类采购', 'meat');
?>

<nav class="subtabs">
  <a href="meat.php">录入与明细</a>
  <a href="meatweek.php" class="on">周报表</a>
</nav>

<?php storeBanner(); ?>

<?php if ($storeErr): ?>
  <p class="err"><strong>采购数据不可用：</strong><?= h($storeErr) ?></p>
<?php endif; ?>

<form class="panel" method="get" action="meatweek.php">
  <div class="row">
    <label>统计范围
      <select name="weeks" onchange="this.form.submit()">
        <?php foreach ($weeksOpt as $n => $label): ?>
          <option value="<?= (int) $n ?>" <?= $nWeeks === $n ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">刷新</button>
    <span class="hint"><?= h($rangeFrom) ?> ~ <?= h($rangeTo) ?>　周一至周日</span>
  </div>
</form>

<p class="err" style="background:#fff8ec;border-color:#f0dcb4;color:#7a5b12">
  <strong>先说清一件事：这里统计的是「采购量」，不是「实际消耗量」。</strong><br>
  两者中间隔着库存 —— 某周进一大批、下周不进，单看当周就会忽高忽低，
  <strong>看起来像浪费，其实只是进货节奏</strong>。所以下表同时给出
  「当周」和「近 4 周滚动平均」，<strong>判断趋势请看滚动平均那一列</strong>。
  <br>
  要得到真正的消耗量，需要再记每周盘点：
  <code>消耗 = 上周末库存 + 本周采购 − 本周末库存</code>。
  等你开始盘点了告诉我，报表会自动切到消耗口径，采购记录一个字段都不用改。
</p>

<?php if ($posErr): ?>
  <p class="err">POS 主库读取失败，<strong>人均用量和成本占比这两列算不出来</strong>
    （采购数据不受影响，照常显示）：<?= h($posErr) ?></p>
<?php endif; ?>

<section class="cards">
  <div class="card total"><h3>采购重量合计</h3>
    <div class="big"><?= $tot['rows'] > 0 ? qty($tot['kg']) . ' <span style="font-size:14px">kg</span>' : '—' ?></div>
    <dl><dt>统计周数</dt><dd><?= num(count($done)) ?> 个完整周</dd></dl></div>
  <?php /* 「统计周数」只数有记录以来的完整周 —— 见上面 $done 的口径说明 */ ?>
  <div class="card night"><h3>采购金额合计</h3>
    <div class="big"><?= $tot['rows'] > 0 ? money($tot['money']) : '—' ?></div>
    <dl><dt>采购笔数</dt><dd><?= num($tot['rows']) ?></dd></dl></div>
  <div class="card day"><h3>人均用量</h3>
    <div class="big"><?= $totPerGuest === null ? '—' : num(round($totPerGuest)) ?>
      <span style="font-size:14px">g/人</span></div>
    <dl><dt>客人数</dt><dd><?= num($tot['guests']) ?></dd></dl></div>
  <div class="card <?= $totCostPct !== null && $totCostPct > 0.35 ? 'bad' : 'gap' ?>">
    <h3>占营业额</h3>
    <div class="big"><?= $totCostPct === null ? '—' : number_format($totCostPct * 100, 1) . '%' ?></div>
    <dl><dt>营业额</dt><dd><?= money($tot['actual']) ?></dd></dl></div>
</section>

<h2>逐周明细</h2>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>周</th>
    <?php foreach ($kinds as $code => $name): ?>
      <th class="n hide-sm"><?= h($name) ?></th>
    <?php endforeach; ?>
    <th class="n">合计 kg</th>
    <th class="n">采购额</th>
    <th class="n hide-sm">客人数</th>
    <th class="n">人均 g</th>
    <th class="n">近 4 周均值</th>
    <th class="n">占营业额</th>
  </tr></thead>
  <tbody>
  <?php foreach ($table as $wk => $r):
      $rollV = $roll[$wk] ?? null;
      // 当周比 4 周均值高出 25% 以上，值得看一眼是不是有浪费
      $spike = $r['per_guest_g'] !== null && $rollV !== null && $rollV > 0
               && !$r['partial'] && $r['per_guest_g'] > $rollV * 1.25;
      // 第一条采购记录之前的周：那时候还没开始记，不算「那周没买肉」
      $before = !isset($done[$wk]) && !$r['partial'];
  ?>
    <tr class="<?= $r['partial'] || $before ? 'row-skip' : ($spike ? 'row-warn' : '') ?>">
      <td class="date"><strong><?= h(substr($r['start'], 5)) ?></strong>
        <span class="dim">~ <?= h(substr($r['end'], 5)) ?></span>
        <?php if ($r['partial']): ?><span class="tag">本周未完</span><?php endif; ?>
        <?php if ($before): ?><span class="tag">尚无记录</span><?php endif; ?>
      </td>
      <?php foreach ($kinds as $code => $name):
          $k = $r['kinds'][$code] ?? null;
          $kkg = $k ? $k['kg'] + $k['kg_est'] : 0.0; ?>
        <td class="n hide-sm"><?= $kkg > 0 ? qty($kkg) : '<span class="dim">—</span>' ?></td>
      <?php endforeach; ?>
      <td class="n strong"><?= $r['kg'] > 0 ? qty($r['kg']) : '<span class="dim">—</span>' ?>
        <?php if ($r['est_rows'] > 0): ?>
          <span class="est" title="其中 <?= qty($r['kg_est']) ?> kg 是按平均条重估算的">≈</span>
        <?php endif; ?>
        <?php if ($r['no_kg'] > 0): ?>
          <span class="d" title="<?= (int) $r['no_kg'] ?> 条记录缺重量，没算进来">!</span>
        <?php endif; ?>
      </td>
      <td class="n"><?= $r['money'] > 0 ? money($r['money']) : '<span class="dim">—</span>' ?>
        <?php if ($r['no_money'] > 0): ?>
          <span class="d" title="<?= (int) $r['no_money'] ?> 条记录缺总价，没算进来">!</span>
        <?php endif; ?>
      </td>
      <td class="n hide-sm dim"><?= $r['guests'] > 0 ? num($r['guests']) : '—' ?></td>
      <td class="n strong"><?= $r['per_guest_g'] === null ? '<span class="dim">—</span>'
            : num(round($r['per_guest_g'])) ?></td>
      <td class="n dim"><?= $rollV !== null && $rollV > 0 ? num(round($rollV)) : '—' ?></td>
      <td class="n"><?= $r['cost_pct'] === null ? '<span class="dim">—</span>'
            : number_format($r['cost_pct'] * 100, 1) . '%' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<p class="note">
  <span class="est">≈</span> 该周有记录是按平均条重估算重量的。
  <span class="d">!</span> 该周有记录缺重量或缺总价，<strong>没算进合计</strong> ——
  数字会偏低，把它们补齐（<a href="meat.php?pending=1">待补发票 →</a>）之后才准。
  <br>
  <span class="tag">本周未完</span> 的那一行数据不完整，<strong>不计入</strong>上面的合计。
  <span class="tag">尚无记录</span> 是开始记账之前的周，同样<strong>不计入</strong> ——
  否则周均会被这些空周摊薄。中间偶尔有一周没进货是照常计入的。
  某周人均比近 4 周均值高出 25% 以上会标黄 —— 可能是浪费，也可能只是那周进了货，
  <strong>结合采购额和进货节奏一起看</strong>。
  <br>
  人均 = 采购重量 ÷ POS 客人数（已按订单去重）。
  占营业额 = 采购金额 ÷ 实收营业额。外带客人在 POS 里人数记 0，
  但外带同样消耗食材，所以人均会略偏高。
</p>

<h2>各品类小计（<?= num(count($done)) ?> 个完整周）</h2>
<?php
$byKind = [];
foreach ($done as $r) {
    foreach ($r['kinds'] as $code => $k) {
        if (!isset($byKind[$code])) {
            $byKind[$code] = ['kg' => 0.0, 'money' => 0.0, 'rows' => 0];
        }
        $byKind[$code]['kg']    += $k['kg'] + $k['kg_est'];
        $byKind[$code]['money'] += $k['money'];
        $byKind[$code]['rows']  += $k['rows'];
    }
}
uasort($byKind, static fn($a, $b) => $b['money'] <=> $a['money']);
$nDone = max(1, count($done));
?>
<?php if (!$byKind): ?>
  <p class="empty">这个范围里还没有采购记录。<a href="meat.php">去录入 →</a></p>
<?php else: ?>
<div class="tablewrap"><table class="grid stick">
  <thead><tr><th>品类</th><th class="n">合计 kg</th><th class="n">周均 kg</th>
    <th class="n">合计金额</th><th class="n">周均金额</th>
    <th class="n">均价 €/kg</th><th class="n hide-sm">笔数</th>
    <th class="n">占采购额</th></tr></thead>
  <tbody>
  <?php foreach ($byKind as $code => $k): ?>
    <tr>
      <td><strong><?= h(Meat::kindLabel((string) $code)) ?></strong></td>
      <td class="n"><?= qty($k['kg']) ?></td>
      <td class="n dim"><?= qty($k['kg'] / $nDone) ?></td>
      <td class="n"><?= money($k['money']) ?></td>
      <td class="n dim"><?= money($k['money'] / $nDone) ?></td>
      <td class="n strong"><?= $k['kg'] > 0 ? money($k['money'] / $k['kg']) : '—' ?></td>
      <td class="n hide-sm dim"><?= num($k['rows']) ?></td>
      <td class="n"><?= $tot['money'] > 0
            ? number_format($k['money'] / $tot['money'] * 100, 1) . '%' : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="note">
  <strong>均价 €/kg</strong> = 该品类总金额 ÷ 总重量，跟录入时按公斤还是按件计价无关 ——
  统一换算成每公斤才比得了。缺重量或缺总价的记录不参与，所以补齐「待补发票」会让这个数更准。
</p>
<?php endif; ?>

<?php if ($meta): ?>
<p class="meta">
  <?php foreach ($meta['queries'] as [$t, $n, $ms]): ?>
    <code><?= h($t) ?></code> <?= (int) $n ?> 组 / <?= number_format($ms * 1000, 0) ?>ms
  <?php endforeach; ?>
  | 采购来自自有 SQLite，客人数与营业额来自 POS 主库（只读），两边分别查询后在内存里按周合并，不做 JOIN。
</p>
<?php endif; ?>

<?php pageFooter(); ?>
