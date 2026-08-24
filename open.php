<?php
/**
 * 开台核对 —— 当前已开桌、未结算的单子，人数与套餐份数是否对得上
 *
 * 典型问题：开了 4 位客人，却一份套餐都没打，或者只打了 2 份。
 *
 * 数据来自两张实时表，各查一次，不做 JOIN：
 *   order_head    —— 哪些台开着、几个人
 *   order_detail  —— 每台点了几份套餐、几个菜
 *
 * 算作「按人头套餐」的菜品由 config.php 的 combo_item_ids 决定，页面底部会列出来。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$cfg        = Db::config();
$comboIds   = array_map('intval', (array) ($cfg['combo_item_ids'] ?? []));
$warnHours  = (int) ($cfg['open_table_warn_hours'] ?? 4);
$onlyOpen   = !isset($_GET['scope']) || q('scope') !== 'all';
$onlyIssues = qbool('issues');

$error = null;
$data  = null;
$meta  = [];
$menuItems = [];

try {
    $menuItems = Biz::menuItems();

    $t0    = microtime(true);
    $heads = Biz::openTables($onlyOpen);
    $meta['queries'][] = ['order_head', count($heads), microtime(true) - $t0];

    $counts = [];
    if ($heads) {
        $ids = array_column($heads, 'order_head_id');
        $t1  = microtime(true);
        $counts = Biz::orderComboCounts($ids, $comboIds);
        $meta['queries'][] = ['order_detail', count($counts), microtime(true) - $t1];
    }

    $data = Report::buildOpenTables($heads, $counts, $warnHours);
} catch (Throwable $e) {
    $error = '查询失败：' . $e->getMessage();
}

pageHeader('开台核对', 'open');
?>

<form class="panel" method="get" action="open.php">
  <div class="row">
    <label>范围
      <select name="scope">
        <option value="open" <?= $onlyOpen ? 'selected' : '' ?>>只看未结算（已开桌）</option>
        <option value="all"  <?= !$onlyOpen ? 'selected' : '' ?>>实时表里的全部订单</option>
      </select>
    </label>
    <label class="cb" style="margin-bottom:8px"><input type="checkbox" name="issues" value="1"
      <?= $onlyIssues ? 'checked' : '' ?>> 只看有问题的台</label>
    <button type="submit">刷新</button>
    <span class="hint">查询时刻 <?= h(date('Y-m-d H:i:s')) ?></span>
  </div>
</form>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if (!$comboIds): ?>
  <p class="err">config.php 里的 <code>combo_item_ids</code> 是空的，套餐份数会全部显示为 0。
    请先把按人头的套餐菜品 ID 填进去。</p>
<?php endif; ?>

<?php if ($data !== null):
  $S    = $data['sum'];
  $rows = Report::sortOpenTables($data['rows']);
  if ($onlyIssues) {
      $rows = array_values(array_filter($rows, fn($r) => $r['state'] !== Report::OPEN_OK));
  }
?>

  <section class="cards">
    <div class="card total"><h3><?= $onlyOpen ? '开台数' : '订单数' ?></h3>
      <div class="big"><?= num($S['tables']) ?></div></div>
    <div class="card day"><h3>开台人数合计</h3>
      <div class="big"><?= num($S['guests']) ?></div></div>
    <div class="card night"><h3>套餐份数合计</h3>
      <div class="big"><?= qty($S['combo']) ?></div>
      <dl><dt>与人数差额</dt><dd><?= ($S['combo'] - $S['guests'] >= 0 ? '+' : '')
            . qty($S['combo'] - $S['guests']) ?></dd></dl></div>
    <div class="card <?= $S['problem'] > 0 ? 'bad' : 'good' ?>"><h3>需要核对的台</h3>
      <div class="big"><?= num($S['problem']) ?></div>
      <?php if ($S['stale'] > 0): ?>
        <dl><dt>开台超 <?= (int) $warnHours ?> 小时</dt><dd><?= num($S['stale']) ?></dd></dl>
      <?php endif; ?>
    </div>
  </section>

  <?php if (!$rows): ?>
    <p class="empty">
      <?= $onlyIssues ? '所有台的人数与套餐份数都对得上。' :
          ($onlyOpen ? '当前没有未结算的开台记录。' : '实时表里没有订单记录。') ?>
    </p>
  <?php else: ?>

  <h2><?= $onlyIssues ? '有问题的台' : '开台明细' ?>（<?= num(count($rows)) ?>）</h2>
  <div class="tablewrap">
  <table class="grid">
    <thead><tr>
      <th>桌号</th><th class="n">人数</th><th class="n">套餐份数</th><th class="n">差额</th>
      <th>核对结果</th><th class="n">已点菜品</th><th class="n">当前金额</th>
      <th>开台时间</th><th class="n">已开台</th><th>服务员</th>
      <?php if (!$onlyOpen): ?><th>是否已结算</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $cls = ['ok' => '', 'short' => 'row-bad', 'none' => 'row-bad',
                'over' => 'row-warn', 'noguest' => 'row-warn'][$r['state']] ?? ''; ?>
      <tr class="<?= $cls ?>">
        <td><strong><?= h($r['table'] !== '' ? $r['table'] : '#' . $r['id']) ?></strong>
            <?php if ($r['checks'] > 1): ?><span class="tag">分 <?= (int) $r['checks'] ?> 单</span><?php endif; ?>
            <?php if ($r['eat_type'] === 3): ?><span class="tag">外带</span><?php endif; ?></td>
        <td class="n strong"><?= $r['guests'] > 0 ? num($r['guests']) : '—' ?></td>
        <td class="n strong"><?= qty($r['combo']) ?></td>
        <td class="n"><?= $r['guests'] > 0
              ? ($r['diff'] > 0 ? '+' : '') . qty($r['diff']) : '—' ?></td>
        <td><span class="state s-<?= h($r['state']) ?>"><?= h(Report::openStateLabel($r['state'])) ?></span></td>
        <td class="n"><?= qty($r['dishes']) ?> <span class="dim">/ <?= num($r['lines']) ?> 笔</span></td>
        <td class="n"><?= money($r['amount']) ?></td>
        <td class="date"><?= h($r['start'] !== '' ? substr($r['start'], 5, 11) : '—') ?></td>
        <td class="n <?= $r['stale'] ? 'stale' : '' ?>">
          <?= $r['minutes'] === null ? '—'
              : (intdiv($r['minutes'], 60) . ':' . str_pad((string) ($r['minutes'] % 60), 2, '0', STR_PAD_LEFT)) ?>
        </td>
        <td class="dim"><?= h($r['employee']) ?></td>
        <?php if (!$onlyOpen): ?><td class="dim"><?= $r['settled'] ? '已结算' : '未结算' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p class="note">
    <span class="state s-none">未打套餐</span> 一份套餐都没打 ——
    可能刚开台还没下单，也可能真的漏打，看「已点菜品」和「已开台」时长判断。
    <span class="state s-short">套餐打少了</span> 份数比人数少。
    <span class="state s-over">套餐打多了</span> 份数比人数多。
    <span class="state s-noguest">未填人数</span> 开台时没填人数，无法比对。
    <br>
    只点单品不吃自助的客人，本来就不会有套餐 —— 这类会显示成「未打套餐」，属正常，
    请结合金额与菜品数判断。「已开台」按 时:分 显示，超过 <?= (int) $warnHours ?> 小时会标红。
  </p>

  <?php endif; ?>

  <details class="station" style="margin-top:18px">
    <summary><span class="pcname">当前算作「按人头套餐」的菜品</span>
      <span class="pcmeta"><?= num(count($comboIds)) ?> 个 · 在 config.php 的 combo_item_ids 里调整</span></summary>
    <div style="padding:14px 16px">
      <?php if (!$comboIds): ?>
        <p class="empty">清单为空。</p>
      <?php else: ?>
      <div class="tablewrap"><table class="grid small">
        <thead><tr><th class="n">编号</th><th>菜品</th><th class="n">单价</th><th>状态</th></tr></thead>
        <tbody>
        <?php foreach ($comboIds as $cid):
            $m = $menuItems[$cid] ?? null; ?>
          <tr>
            <td class="n dim"><?= (int) $cid ?></td>
            <?php $nm = $m['name'] ?? ('#' . (int) $cid . '（菜单里没有这个 ID）'); ?>
            <td class="iname"><span title="<?= h($nm) ?>"><?= h($nm) ?></span></td>
            <td class="n"><?= $m ? money($m['price']) : '—' ?></td>
            <td><?= $m ? '<span class="state s-ok">正常</span>'
                       : '<span class="state s-short">菜单里查不到这个 ID</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="note">换季改菜单、新增套餐后记得更新这个清单，否则核对结果会失真。</p>
      <?php endif; ?>
    </div>
  </details>

  <?php if ($meta): ?>
  <p class="meta">
    <?php foreach ($meta['queries'] as [$t, $n, $ms]): ?>
      <code><?= h($t) ?></code> <?= (int) $n ?> 组 / <?= number_format($ms * 1000, 0) ?>ms
    <?php endforeach; ?>
    <?php if ($onlyOpen): ?>
      | 判定「未结算」的依据是 <code>order_end_time IS NULL</code>；
      如果这里始终是空的，把范围切到「实时表里的全部订单」看看实际情况。
    <?php endif; ?>
  </p>
  <?php endif; ?>

<?php endif; ?>

<?php pageFooter(); ?>
