<?php
/**
 * 库存 —— 当前库存与用量
 *
 * 只读自有的 SQLite，一行写操作都没有；POS 主库这一页压根不碰。
 *
 * 页面上最要紧的一件事，是把「账面」和「实际」分清楚：
 *
 *   最近一次盘点之后又存入了多少，是记着的；
 *   那之后【用掉了多少没人记】—— 要等下次盘点才知道。
 *
 * 所以账面数是个【上限】，不是「现在冰箱里就是这么多」。
 * 这句话必须写在数字旁边，不然一定会被当成实时库存来用。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/stock.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$storeErr = null;
$cur      = [];
$ready    = Store::isReady();
if (!$ready) {
    $storeErr = Store::lastError();
} else {
    try {
        $cur = Stock::current();
    } catch (Throwable $e) {
        $storeErr = $e->getMessage();
    }
}

// 两种口径分开显示。混在一张表里的话，「账面上限」「上一段用量」这些列
// 对「存入即用量」的品类根本没有意义 —— 同一列两种含义，一定会被读错。
$byCount  = array_filter($cur, static fn($c) => !$c['direct']);
$byDirect = array_filter($cur, static fn($c) => $c['direct']);

// 看哪个品类的分段用量（只有盘点法的品类才有「段」）
$pick = q('item');
if ($pick === '' || !isset($byCount[$pick])) {
    $pick = '';
    foreach ($byCount as $code => $c) {
        if ($c['periods']) { $pick = (string) $code; break; }
    }
}

// 多久没盘就该提醒了。盘点是一切数字的锚，太久没盘的话
// 账面数会离实际越来越远，而页面上看不出来。
$staleHours = 48;

$nCounted = 0;
$nStale   = 0;
$nNeg     = 0;
// 「超 N 小时没盘」「用量负数」只对盘点法的品类有意义 ——
// 把不盘点的品类算进去，等于天天报一个永远修不好的警
foreach ($byCount as $c) {
    if ($c['counted']) {
        $nCounted++;
        if ($c['stale_h'] !== null && $c['stale_h'] > $staleHours) {
            $nStale++;
        }
    }
    foreach ($c['periods'] as $p) {
        if ($p['negative']) { $nNeg++; }
    }
}

pageHeader('库存', 'stock');
?>

<nav class="subtabs">
  <a href="stock.php">录入与明细</a>
  <a href="stocknow.php" class="on">当前库存</a>
</nav>

<?php storeBanner(); ?>

<?php if ($storeErr): ?>
  <p class="err"><strong>数据文件不可用：</strong><?= h($storeErr) ?></p>
<?php endif; ?>

<p class="err" style="background:#fff8ec;border-color:#f0dcb4;color:#7a5b12">
  <strong>「账面」不等于「现在冰箱里有多少」。</strong><br>
  最近一次盘点之后又存入了多少是记着的，但<strong>那之后用掉了多少没人记</strong> ——
  要等下次盘点才知道。所以账面数是个<strong>上限</strong>，
  <strong>越久没盘，它离实际越远</strong>。要知道真实数量，就去盘一次。
  <?php if ($byDirect): ?>
    <br>
    另有 <strong><?= num(count($byDirect)) ?></strong> 个品类走「<strong>存入即用量</strong>」口径
    （数不清的那些），它们不盘点、也没有「剩多少」这个概念，单独列在下面。
  <?php endif; ?>
</p>

<?php if ($nNeg > 0): ?>
  <p class="err">
    有 <strong><?= num($nNeg) ?></strong> 段算出来的用量是<strong>负数</strong> ——
    盘出来的比账面还多，东西不会凭空长出来，多半是<strong>漏记了一笔存入</strong>。
    下面标红的那几段，回去补一条存入记录就对了。
  </p>
<?php endif; ?>

<section class="cards">
  <div class="card total"><h3>品类</h3>
    <div class="big"><?= num(count($cur)) ?></div>
    <dl><dt>盘点法</dt><dd><?= num(count($byCount)) ?> 项（已盘过 <?= num($nCounted) ?>）</dd>
      <?php if ($byDirect): ?>
        <dt>存入即用量</dt><dd><?= num(count($byDirect)) ?> 项</dd>
      <?php endif; ?></dl></div>
  <div class="card <?= $nStale > 0 ? 'bad' : 'day' ?>"><h3>超过 <?= (int) $staleHours ?> 小时没盘</h3>
    <div class="big"><?= num($nStale) ?> <span style="font-size:14px">项</span></div></div>
  <div class="card <?= $nNeg > 0 ? 'bad' : 'gap' ?>"><h3>用量算成负数</h3>
    <div class="big"><?= num($nNeg) ?> <span style="font-size:14px">段</span></div>
    <dl><dt>含义</dt><dd>漏记了存入</dd></dl></div>
</section>

<h2>各品类现状（盘点法）</h2>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>品类</th>
    <th class="n">最近盘点</th>
    <th>盘于</th>
    <th class="n">之后存入</th>
    <th class="n">账面上限</th>
    <th class="n hide-sm">上一段用量</th>
    <th class="hide-sm">上一段区间</th>
    <th>状态</th>
  </tr></thead>
  <tbody>
  <?php foreach ($byCount as $code => $c):
      $stale = $c['stale_h'] !== null && $c['stale_h'] > $staleHours;
      $lp    = $c['last_period'];
  ?>
    <tr class="<?= !$c['counted'] ? 'row-skip' : ($stale || ($lp && $lp['negative']) ? 'row-warn' : '') ?>">
      <td><strong><?= h($c['name']) ?></strong>
        <?php if ($c['unit'] !== ''): ?><span class="dim">（<?= h($c['unit']) ?>）</span><?php endif; ?></td>
      <td class="n strong"><?= $c['counted'] ? qty($c['last_qty']) : '<span class="dim">—</span>' ?></td>
      <td class="date">
        <?php if ($c['counted']): ?>
          <?= h(substr((string) $c['last_at'], 5, 11)) ?>
          <?php $ml = Stock::momentLabel($c['last_moment']);
                if ($ml !== ''): ?><span class="tag"><?= h($ml) ?></span><?php endif; ?>
        <?php else: ?><span class="dim">从没盘过</span><?php endif; ?>
      </td>
      <td class="n"><?= $c['since_in'] > 0 ? '+' . qty($c['since_in']) : '<span class="dim">—</span>' ?></td>
      <td class="n strong"><?= $c['book'] !== null ? qty($c['book']) : '<span class="dim">—</span>' ?></td>
      <td class="n hide-sm <?= $lp && $lp['negative'] ? 'stale' : '' ?>">
        <?= $lp !== null ? qty($lp['used']) : '<span class="dim">—</span>' ?></td>
      <td class="hide-sm dim">
        <?php if ($lp !== null): ?>
          <?= h(substr($lp['from'], 5, 11)) ?> → <?= h(substr($lp['to'], 5, 11)) ?>
          <span class="wd"><?= number_format($lp['hours'], 1) ?>h</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td>
        <?php if (!$c['counted'] && $c['rows'] === 0): ?>
          <span class="state s-skip">还没记录</span>
        <?php elseif (!$c['counted']): ?>
          <span class="state s-noguest">只存入过，没盘过</span>
        <?php elseif ($lp && $lp['negative']): ?>
          <span class="state s-dshort">漏记存入</span>
        <?php elseif ($stale): ?>
          <span class="state s-dshort">超 <?= (int) round($c['stale_h']) ?> 小时没盘</span>
        <?php else: ?>
          <span class="state s-ok">正常</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<p class="note">
  <strong>账面上限</strong> = 最近盘点数 + 之后的存入。<strong>不是实时库存</strong> ——
  盘点之后用掉的部分不在里面。
  <br>
  <strong>上一段用量</strong> = 上次盘点 + 期间存入 − 本次盘点，也就是这段时间<strong>取出、已经用掉</strong>的量。
  「从没盘过」的品类算不出用量 —— 只有存入没有盘点，等于只知道进了多少，不知道剩多少。
</p>

<?php if ($byDirect): ?>
<h2>各品类现状（存入即用量）</h2>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>品类</th>
    <th>最近存入</th>
    <th class="n">近 7 天用量</th>
    <th class="n">近 30 天用量</th>
    <th class="n">累计用量</th>
    <th class="n hide-sm">笔数</th>
    <th>状态</th>
  </tr></thead>
  <tbody>
  <?php foreach ($byDirect as $code => $c): ?>
    <tr class="<?= $c['rows'] === 0 ? 'row-skip' : '' ?>">
      <td><strong><?= h($c['name']) ?></strong>
        <?php if ($c['unit'] !== ''): ?><span class="dim">（<?= h($c['unit']) ?>）</span><?php endif; ?></td>
      <td class="date"><?= $c['last_in_at'] !== null
            ? h(substr((string) $c['last_in_at'], 5, 11)) : '<span class="dim">—</span>' ?></td>
      <td class="n strong"><?= $c['rows'] > 0 ? qty($c['in_7']) : '<span class="dim">—</span>' ?></td>
      <td class="n"><?= $c['rows'] > 0 ? qty($c['in_30']) : '<span class="dim">—</span>' ?></td>
      <td class="n dim"><?= $c['rows'] > 0 ? qty($c['in_total']) : '—' ?></td>
      <td class="n hide-sm dim"><?= num($c['rows']) ?></td>
      <td><?= $c['rows'] === 0
            ? '<span class="state s-skip">还没记录</span>'
            : '<span class="state s-ok">正常</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="note">
  这几个品类<strong>不参与盘点</strong> —— 切开之后大小不一、数不清，
  硬盘只会盘出假数字。所以口径是<strong>进多少就算用掉多少</strong>，
  上面几列写的是<strong>用量</strong>，不是库存。
  <br>
  代价要清楚：这类品类<strong>看不出还剩多少，也看不出浪费</strong>。
  哪天它变得数得清了（比如改成按固定规格的盒装进货），
  把 <code>stock_items</code> 里那一项的 <code>'mode' =&gt; 'direct'</code> 去掉，
  它就回到盘点法，<strong>历史记录照样在</strong>（清单在 <code>config.php</code>，
  没写过就在 <code>lib/settings.php</code>）。
</p>
<?php endif; ?>

<h2>分段用量</h2>
<?php if ($pick === ''): ?>
  <p class="empty">还没有哪个品类盘过两次 —— <strong>至少要两次盘点才算得出用量</strong>。
    <a href="stock.php">去录入 →</a></p>
<?php else: ?>

<form class="panel" method="get" action="stocknow.php">
  <div class="row">
    <label>看哪个品类
      <select name="item" onchange="this.form.submit()">
        <?php foreach ($byCount as $code => $c): ?>
          <option value="<?= h((string) $code) ?>" <?= $pick === (string) $code ? 'selected' : '' ?>>
            <?= h($c['name']) ?><?= $c['periods'] ? '' : '（还算不出用量）' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">查看</button>
    <span class="hint">相邻两次盘点之间算一段</span>
  </div>
</form>

<?php $ps = array_reverse($byCount[$pick]['periods']); $u = $byCount[$pick]['unit']; ?>
<?php if (!$ps): ?>
  <p class="empty"><?= h($byCount[$pick]['name']) ?> 还没盘过两次，算不出用量。</p>
<?php else: ?>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>区间</th><th class="hide-sm">时长</th>
    <th class="n">上次盘点</th><th class="n">期间存入</th><th class="n">本次盘点</th>
    <th class="n">取出（已使用）</th>
  </tr></thead>
  <tbody>
  <?php foreach ($ps as $p): ?>
    <tr class="<?= $p['negative'] ? 'row-bad' : '' ?>">
      <td class="date"><?= h(substr($p['from'], 5, 11)) ?>
        <?php $a = Stock::momentLabel($p['from_moment']);
              if ($a !== ''): ?><span class="tag"><?= h($a) ?></span><?php endif; ?>
        → <?= h(substr($p['to'], 5, 11)) ?>
        <?php $b = Stock::momentLabel($p['to_moment']);
              if ($b !== ''): ?><span class="tag"><?= h($b) ?></span><?php endif; ?>
      </td>
      <td class="hide-sm dim"><?= number_format($p['hours'], 1) ?> 小时</td>
      <td class="n dim"><?= qty($p['from_qty']) ?></td>
      <td class="n"><?= $p['in'] > 0 ? '+' . qty($p['in']) : '<span class="dim">—</span>' ?></td>
      <td class="n dim"><?= qty($p['to_qty']) ?></td>
      <td class="n strong <?= $p['negative'] ? 'stale' : '' ?>">
        <?= qty($p['used']) ?> <span class="dim"><?= h($u) ?></span>
        <?php if ($p['negative']): ?>
          <span class="d" title="盘出来的比账面还多，多半是漏记了一笔存入">!</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="note">
  每一行就是一段：<code>取出 = 上次盘点 + 期间存入 − 本次盘点</code>。
  比如上次 4 <?= h($u) ?>、期间存入 3 <?= h($u) ?>、这次数出 5 <?= h($u) ?>，
  那这段时间就用掉了 2 <?= h($u) ?>。
  <br>
  盘得越密，段就越短，能看到的东西越细 —— 到店盘一次、午市后盘一次，
  中间那一段就是<strong>午市用了多少</strong>。
  <span class="stale">红色</span>的行是用量算成了负数，回去补一条存入记录。
</p>
<?php endif; ?>
<?php endif; ?>

<p class="note">
  库存记录存在独立的 SQLite 文件里，与 POS 主库完全无关；本页只读，不写任何东西。
  库存和采购是两本各自独立的账，程序里不做对应 —— 采购买的是整条鱼，
  库存数的是分割处理之后的鱼条、黑皮、鱼沫。
</p>

<?php pageFooter(); ?>
