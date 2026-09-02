<?php
/**
 * 期间对比 —— 本期 vs 上期
 *
 * 两种用法：
 *   1. 快捷：近 7 天 vs 前 7 天。今天周三，就是「上周四~今天」对「上上周四~上周三」，
 *      两段都是 7 天，星期几自然对齐。近 14 / 30 天同理。
 *   2. 自选：自己填本期起止；对比期默认自动取紧挨着的等长一段，
 *      也可以勾「手动指定对比期」自己填（比如今年 5 月 vs 去年 5 月）。
 *
 * 查询策略仍然是「每次只统计一张表」：
 *   营业额  —— 只查账单头表，本期上期各一次（+ 可选实时表）
 *   菜品    —— 只查明细表，默认不查，勾选后才查
 *   岗位    —— 只查明细表，默认不查，勾选后才查
 * 菜品和岗位要扫明细大表，所以默认关着，需要时再勾。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$cfg      = Db::config();
$cutHour  = (int) $cfg['day_cut_hour'];
$today    = date('Y-m-d', time() - $cutHour * 3600);

$preset   = q('preset', '7');            // 快捷天数：7 / 14 / 30；'' 表示用自选日期
$manual   = qbool('manual');             // 手动指定对比期
$withDish = qbool('with_dish');
$withStn  = qbool('with_station');
$withLive = !isset($_GET['go']) || qbool('with_live');
$seg      = in_array(q('seg', 'total'), ['day', 'night', 'total'], true) ? q('seg', 'total') : 'total';

// 本期：快捷模式按天数往前推，自选模式用填进来的日期
if ($preset !== '' && !isset($_GET['start'])) {
    [$curStart, $curEnd] = Biz::lastDays((int) $preset, $today);
} else {
    $curStart = q('start', $today);
    $curEnd   = q('end', $today);
}
// 上期：默认紧挨着本期的等长一段
[$autoPrevStart, $autoPrevEnd] = Biz::prevRange($curStart, $curEnd);
$prevStart = $manual ? q('pstart', $autoPrevStart) : $autoPrevStart;
$prevEnd   = $manual ? q('pend',   $autoPrevEnd)   : $autoPrevEnd;

$error = null;
$cmp = null;        // 营业额对比
$daily = [];        // 逐日对照
$dishRows = null;   // 菜品对比
$stnRows = null;    // 岗位对比
$meta = [];
$curDays = 0;
$prevDays = 0;

if (isset($_GET['go'])) {
    $error = Biz::validateRange($curStart, $curEnd) ?? Biz::validateRange($prevStart, $prevEnd);
    if ($error === null) {
        try {
            $curDays  = Biz::rangeDays($curStart, $curEnd);
            $prevDays = Biz::rangeDays($prevStart, $prevEnd);

            // ---- 营业额：账单头表，两期各查一次 ----
            $pivot = [];
            foreach ([['cur', $curStart, $curEnd], ['prev', $prevStart, $prevEnd]] as [$k, $s, $e]) {
                [$from, $to] = Biz::range($s, $e);
                $t0 = microtime(true);
                $hist = Biz::salesByDay($from, $to, 'history_order_head');
                $meta['queries'][] = ["history_order_head({$k})", count($hist), microtime(true) - $t0];

                $live = [];
                if ($withLive && Biz::needLiveTables($to)) {
                    $t1 = microtime(true);
                    $live = Biz::salesByDay($from, $to, 'order_head');
                    $meta['queries'][] = ["order_head({$k})", count($live), microtime(true) - $t1];
                }
                $pivot[$k] = Report::pivotSales($hist, $live);
            }
            $cmp = Report::compareSales($pivot['cur'], $pivot['prev']);
            $daily = Report::compareDaily(
                Biz::dateList($curStart, $curEnd), Biz::dateList($prevStart, $prevEnd),
                $pivot['cur']['days'], $pivot['prev']['days'], $seg
            );

            // ---- 菜品：明细表，勾选后才查 ----
            if ($withDish) {
                $menuItems = Biz::menuItems();
                $pcs = Biz::printClasses();
                $flat = [];
                foreach ([['cur', $curStart, $curEnd], ['prev', $prevStart, $prevEnd]] as [$k, $s, $e]) {
                    [$from, $to] = Biz::range($s, $e);
                    $t0 = microtime(true);
                    $h = Biz::dishTotals($from, $to, 'history_order_detail');
                    $meta['queries'][] = ["history_order_detail({$k})", count($h), microtime(true) - $t0];
                    $l = [];
                    if ($withLive && Biz::needLiveTables($to)) {
                        $t1 = microtime(true);
                        $l = Biz::dishTotals($from, $to, 'order_detail');
                        $meta['queries'][] = ["order_detail({$k})", count($l), microtime(true) - $t1];
                    }
                    $flat[$k] = Report::flattenDishes(Report::buildDishes($menuItems, $pcs, $h, $l)['items'], $seg);
                }
                $dishRows = Report::compareItems($flat['cur'], $flat['prev'], 'qty');
            }

            // ---- 岗位：明细表，勾选后才查 ----
            if ($withStn) {
                $menuItems = $menuItems ?? Biz::menuItems();
                $pcs = $pcs ?? Biz::printClasses();
                $pcOf = [];
                foreach ($menuItems as $id => $m) {
                    $pcOf[$id] = $m['print_class'];
                }
                $flatS = [];
                foreach ([['cur', $curStart, $curEnd], ['prev', $prevStart, $prevEnd]] as [$k, $s, $e]) {
                    [$from, $to] = Biz::range($s, $e);
                    $t0 = microtime(true);
                    $h = Biz::stationVolume($from, $to, 'history_order_detail', $pcOf);
                    $meta['queries'][] = ["history_order_detail 岗位({$k})", count($h), microtime(true) - $t0];
                    $l = [];
                    if ($withLive && Biz::needLiveTables($to)) {
                        $t1 = microtime(true);
                        $l = Biz::stationVolume($from, $to, 'order_detail', $pcOf);
                        $meta['queries'][] = ["order_detail 岗位({$k})", count($l), microtime(true) - $t1];
                    }
                    $flatS[$k] = Report::flattenStations(Report::buildStations($pcs, $h, $l)['stations'], $seg);
                }
                $stnRows = Report::compareItems($flatS['cur'], $flatS['prev'], 'qty');
            }
        } catch (Throwable $e) {
            $error = '查询失败：' . $e->getMessage();
        }
    }
}

/** 涨跌显示：正数绿、负数红，上期为 0 时显示「新增」而不是硬算百分比 */
function trend(?float $diff, ?float $rate, string $fmt = 'money'): string
{
    if ($diff === null) {
        return '<span class="dim">—</span>';
    }
    if (abs($diff) < 0.005) {
        return '<span class="trend flat">持平</span>';
    }
    $cls  = $diff > 0 ? 'up' : 'down';
    $sign = $diff > 0 ? '+' : '−';
    $val = $fmt === 'money' ? money(abs($diff)) : num(abs($diff));
    $pct = $rate === null
        ? ($diff > 0 ? '新增' : '')
        : ($rate > 0 ? '+' : ($rate < 0 ? '−' : '')) . number_format(abs($rate) * 100, 1) . '%';
    return '<span class="trend ' . $cls . '">' . $sign . $val
         . ($pct !== '' ? ' <em>' . h($pct) . '</em>' : '') . '</span>';
}

pageHeader('期间对比', 'compare');
?>

<form class="panel" method="get" action="compare.php">
  <input type="hidden" name="go" value="1">
  <div class="row">
    <label>快捷对比
      <select name="preset" onchange="this.form.submit()">
        <option value="7"  <?= $preset === '7'  ? 'selected' : '' ?>>近 7 天 vs 前 7 天</option>
        <option value="14" <?= $preset === '14' ? 'selected' : '' ?>>近 14 天 vs 前 14 天</option>
        <option value="30" <?= $preset === '30' ? 'selected' : '' ?>>近 30 天 vs 前 30 天</option>
        <option value=""   <?= $preset === ''   ? 'selected' : '' ?>>自选日期</option>
      </select>
    </label>
    <label>本期开始
      <input type="date" name="start" value="<?= h($curStart) ?>"></label>
    <label>本期结束
      <input type="date" name="end" value="<?= h($curEnd) ?>"></label>
    <label>时段
      <select name="seg">
        <option value="total" <?= $seg === 'total' ? 'selected' : '' ?>>全天</option>
        <option value="day"   <?= $seg === 'day'   ? 'selected' : '' ?>>白天</option>
        <option value="night" <?= $seg === 'night' ? 'selected' : '' ?>>晚上</option>
      </select>
    </label>
    <button type="submit">对比</button>
  </div>
  <div class="row opts">
    <label class="cb"><input type="checkbox" name="manual" value="1"
      <?= $manual ? 'checked' : '' ?> onchange="this.form.submit()"> 手动指定对比期</label>
    <?php if ($manual): ?>
      <label>对比期开始
        <input type="date" name="pstart" value="<?= h($prevStart) ?>"></label>
      <label>对比期结束
        <input type="date" name="pend" value="<?= h($prevEnd) ?>"></label>
    <?php else: ?>
      <span class="hint" style="margin-left:0">对比期自动取
        <strong><?= h($prevStart) ?> ~ <?= h($prevEnd) ?></strong>（紧挨着本期的等长一段）</span>
    <?php endif; ?>
  </div>
  <div class="row opts">
    <label class="cb"><input type="checkbox" name="with_live" value="1"
      <?= $withLive ? 'checked' : '' ?>> 包含当天未日结数据</label>
    <label class="cb"><input type="checkbox" name="with_dish" value="1"
      <?= $withDish ? 'checked' : '' ?>> 同时对比菜品点单量</label>
    <label class="cb"><input type="checkbox" name="with_station" value="1"
      <?= $withStn ? 'checked' : '' ?>> 同时对比岗位单量</label>
    <span class="hint">菜品与岗位要扫明细大表，默认不查</span>
  </div>
</form>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($cmp !== null): ?>

  <p class="periods">
    <span class="pcur">本期 <strong><?= h($curStart) ?></strong>（<?= h(Report::dow($curStart)) ?>）
      ~ <strong><?= h($curEnd) ?></strong>（<?= h(Report::dow($curEnd)) ?>）· <?= num($curDays) ?> 天</span>
    <span class="vs">对比</span>
    <span class="pprev">上期 <strong><?= h($prevStart) ?></strong>（<?= h(Report::dow($prevStart)) ?>）
      ~ <strong><?= h($prevEnd) ?></strong>（<?= h(Report::dow($prevEnd)) ?>）· <?= num($prevDays) ?> 天</span>
  </p>

  <?php if ($curDays !== $prevDays): ?>
    <p class="err">两期天数不一样（<?= num($curDays) ?> 天 vs <?= num($prevDays) ?> 天），
      合计数直接比会失真，请重点看下面的<strong>日均</strong>。</p>
  <?php endif; ?>

  <?php if ($curEnd === $today): ?>
    <p class="note" style="margin-top:-6px">本期含<strong>今天</strong>，而今天还没营业完 ——
      拿半天比上期完整的一天，数字天然偏低，看趋势时心里有数即可。
      想比完整的天，把本期结束日改成昨天。</p>
  <?php endif; ?>

  <?php $S = $cmp[$seg]; ?>
  <section class="cards cmp">
    <?php
    $tiles = [
        ['营业额', 'actual',    'money'],
        ['人数',   'guests',    'num'],
        ['单数',   'checks',    'num'],
        ['人均',   'per_guest', 'money'],
        ['单均',   'per_check', 'money'],
    ];
    foreach ($tiles as [$label, $key, $fmt]):
        $m = $S[$key];
        $cls = abs($m['diff']) < 0.005 ? '' : ($m['diff'] > 0 ? 'good' : 'bad');
    ?>
      <div class="card <?= $cls ?>">
        <h3><?= h($label) ?><?= $seg !== 'total' ? '（' . h(Biz::segLabel($seg)) . '）' : '' ?></h3>
        <div class="big"><?= $fmt === 'money' ? money($m['cur']) : num($m['cur']) ?></div>
        <dl>
          <dt>上期</dt><dd><?= $fmt === 'money' ? money($m['prev']) : num($m['prev']) ?></dd>
          <dt>涨跌</dt><dd><?= trend($m['diff'], $m['rate'], $fmt) ?></dd>
          <?php if ($curDays > 0 && $prevDays > 0 && in_array($key, ['actual', 'guests', 'checks'], true)): ?>
            <dt>日均</dt>
            <dd><?= $fmt === 'money' ? money($m['cur'] / $curDays) : num(round($m['cur'] / $curDays)) ?>
              <span class="dim">/ <?= $fmt === 'money' ? money($m['prev'] / $prevDays) : num(round($m['prev'] / $prevDays)) ?></span></dd>
          <?php endif; ?>
        </dl>
      </div>
    <?php endforeach; ?>
  </section>

  <h2>分时段对比</h2>
  <div class="tablewrap"><table class="grid stick">
    <thead><tr>
      <th>时段</th>
      <th class="n">本期营业额</th><th class="n">上期营业额</th><th class="n">涨跌</th>
      <th class="n">本期人数</th><th class="n">上期人数</th><th class="n">涨跌</th>
      <th class="n hide-sm">本期单数</th><th class="n hide-sm">上期单数</th><th class="n hide-sm">涨跌</th>
      <th class="n">本期人均</th><th class="n">上期人均</th>
    </tr></thead>
    <tbody>
    <?php foreach (['day', 'night', 'gap', 'total'] as $sg):
        $r = $cmp[$sg];
        // 时段外通常只有个位数账单，全为 0 时不占版面
        if ($sg === 'gap' && $r['actual']['cur'] == 0 && $r['actual']['prev'] == 0) continue; ?>
      <tr <?= $sg === 'total' ? 'class="sum"' : '' ?>>
        <td><strong><?= $sg === 'total' ? '全天合计' : h(Biz::segLabel($sg)) ?></strong></td>
        <td class="n"><?= money($r['actual']['cur']) ?></td>
        <td class="n dim"><?= money($r['actual']['prev']) ?></td>
        <td class="n"><?= trend($r['actual']['diff'], $r['actual']['rate']) ?></td>
        <td class="n"><?= num($r['guests']['cur']) ?></td>
        <td class="n dim"><?= num($r['guests']['prev']) ?></td>
        <td class="n"><?= trend($r['guests']['diff'], $r['guests']['rate'], 'num') ?></td>
        <td class="n hide-sm"><?= num($r['checks']['cur']) ?></td>
        <td class="n hide-sm dim"><?= num($r['checks']['prev']) ?></td>
        <td class="n hide-sm"><?= trend($r['checks']['diff'], $r['checks']['rate'], 'num') ?></td>
        <td class="n"><?= money($r['per_guest']['cur']) ?></td>
        <td class="n dim"><?= money($r['per_guest']['prev']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h2>逐日对照<?= $seg !== 'total' ? '（' . h(Biz::segLabel($seg)) . '）' : '' ?>（<?= num(count($daily)) ?> 天）</h2>
  <?php
  $mismatch = false;
  foreach ($daily as $d) { if ($d['paired'] && !$d['same_dow']) { $mismatch = true; break; } }
  ?>
  <?php if ($mismatch): ?>
    <p class="note" style="margin:-4px 0 10px">两期的星期几对不上（自选的日期不是整周），
      下表按<strong>位置</strong>配对：本期第 1 天对上期第 1 天。餐饮的周末和平日差别很大，
      这样比要留意。</p>
  <?php endif; ?>
  <div class="tablewrap"><table class="grid stick">
    <thead><tr>
      <th>本期</th><th class="n">营业额</th><th class="n hide-sm">人数</th>
      <th>上期</th><th class="n">营业额</th><th class="n hide-sm">人数</th>
      <th class="n">营业额涨跌</th><th class="n hide-sm">人数涨跌</th>
    </tr></thead>
    <tbody>
    <?php foreach ($daily as $d): ?>
      <tr class="<?= $d['paired'] ? '' : 'row-warn' ?>">
        <td class="date"><?= $d['cur_date'] !== null
              ? h(substr($d['cur_date'], 5)) . ' <span class="dim">' . h($d['cur_dow']) . '</span>'
              : '<span class="dim">无对应</span>' ?></td>
        <td class="n"><?= $d['cur_amt'] === null ? '—' : money($d['cur_amt']) ?></td>
        <td class="n hide-sm"><?= $d['cur_gue'] === null ? '—' : num($d['cur_gue']) ?></td>
        <td class="date"><?= $d['prev_date'] !== null
              ? h(substr($d['prev_date'], 5)) . ' <span class="dim">' . h($d['prev_dow']) . '</span>'
              : '<span class="dim">无对应</span>' ?></td>
        <td class="n dim"><?= $d['prev_amt'] === null ? '—' : money($d['prev_amt']) ?></td>
        <td class="n hide-sm dim"><?= $d['prev_gue'] === null ? '—' : num($d['prev_gue']) ?></td>
        <td class="n"><?= trend($d['amt_diff'], $d['amt_rate']) ?></td>
        <td class="n hide-sm"><?= trend($d['gue_diff'], $d['gue_rate'], 'num') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($dishRows !== null): ?>
    <h2>菜品点单量变化（<?= num(count($dishRows)) ?> 个菜有变化）</h2>
    <?php if (!$dishRows): ?>
      <p class="empty">两期都没有点单记录。</p>
    <?php else: ?>
    <?php
    // 按涨跌方向分开取，不要用 array_slice 掐头去尾 ——
    // 菜品不足 30 个时首尾两段会重叠，同一个菜会同时出现在「卖得更多」和「卖得更少」里
    $up   = array_slice(array_values(array_filter($dishRows, fn($r) => $r['diff'] > 0)), 0, 15);
    $down = array_slice(array_reverse(array_values(array_filter($dishRows, fn($r) => $r['diff'] < 0))), 0, 15);
    ?>
    <div class="cols">
      <?php foreach ([['卖得更多了', $up], ['卖得更少了', $down]] as [$title, $list]): ?>
        <div>
          <h4><?= h($title) ?>（<?= num(count($list)) ?>）</h4>
          <?php if (!$list): ?>
            <p class="empty">没有<?= $title === '卖得更多了' ? '涨' : '跌' ?>的菜品。</p>
          <?php else: ?>
          <div class="tablewrap"><table class="grid small stick">
            <thead><tr><th>菜品</th><th class="hide-sm">岗位</th>
              <th class="n">本期</th><th class="n">上期</th><th class="n">涨跌</th></tr></thead>
            <tbody>
            <?php foreach ($list as $r): ?>
              <tr>
                <td class="iname"><span title="<?= h($r['name']) ?>"><?= h($r['name']) ?></span></td>
                <td class="dim hide-sm"><?= h($r['pc_name']) ?></td>
                <td class="n"><?= qty($r['cur']) ?></td>
                <td class="n dim"><?= qty($r['prev']) ?></td>
                <td class="n"><?= trend($r['diff'], $r['rate'], 'num') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="note">按<strong>点单量的绝对变化</strong>排序，不是按百分比 ——
      一个菜从 1 份涨到 3 份是 +200%，但没什么意义。要看金额变化请切到菜品页单独查。</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($stnRows !== null): ?>
    <h2>岗位单量变化</h2>
    <?php if (!$stnRows): ?>
      <p class="empty">两期都没有出品记录。</p>
    <?php else: ?>
    <div class="tablewrap"><table class="grid stick">
      <thead><tr><th>岗位</th>
        <th class="n">本期单量</th><th class="n">上期单量</th><th class="n">涨跌</th>
        <th class="n hide-sm">本期金额</th><th class="n hide-sm">上期金额</th></tr></thead>
      <tbody>
      <?php foreach ($stnRows as $r): ?>
        <tr>
          <td><strong><?= h($r['name']) ?></strong></td>
          <td class="n"><?= num($r['cur']) ?></td>
          <td class="n dim"><?= num($r['prev']) ?></td>
          <td class="n"><?= trend($r['diff'], $r['rate'], 'num') ?></td>
          <td class="n hide-sm"><?= money($r['cur_amt'] ?? 0) ?></td>
          <td class="n hide-sm dim"><?= money($r['prev_amt'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="note">「单量」= 该岗位出品涉及了多少张单，一张单里同岗位点了几个菜也只算一单。</p>
    <?php endif; ?>
  <?php endif; ?>

  <p class="note">
    涨跌率 = （本期 − 上期）÷ 上期。上期为 0 时算不出百分比，显示成「新增」。
    人均、单均是各期各自算完再比，不是把差额相除。
    <?php if (!$withDish || !$withStn): ?>
      <br>菜品与岗位对比要扫明细大表，默认不查 —— 需要时在上面勾选。
    <?php endif; ?>
  </p>

  <?php if ($meta): ?>
  <p class="meta">
    <?php foreach ($meta['queries'] as [$t, $n, $ms]): ?>
      <code><?= h($t) ?></code> <?= (int) $n ?> 组 / <?= number_format($ms * 1000, 0) ?>ms
    <?php endforeach; ?>
  </p>
  <?php endif; ?>

<?php elseif (!$error): ?>
  <p class="empty">选好期间后点「对比」。默认是<strong>近 7 天 vs 前 7 天</strong> ——
    今天<?= h(Report::dow($today)) ?>，就是
    <?= h($curStart) ?>（<?= h(Report::dow($curStart)) ?>）~ 今天
    对比 <?= h($autoPrevStart) ?>（<?= h(Report::dow($autoPrevStart)) ?>）~ <?= h($autoPrevEnd) ?>。</p>
<?php endif; ?>

<?php pageFooter(); ?>
