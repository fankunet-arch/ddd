<?php
/**
 * 库存 —— 录入与明细
 *
 * ⚠️ 本页写的是【自有的 SQLite】，与 POS 主库无关。
 *    主库那条只读线一个字节都不动，见「注意事项.md」铁律二。
 *
 * 只有两个动作：
 *   存入   把东西放进库里
 *   盘点   实际数一遍（绝对数）
 * 取出不用记 —— 两次盘点之间少掉的就是取出量（= 已使用）。
 *
 * 录入是一条一条来的，所以这一页做了三件事帮着别录错：
 *   1. 动作没有默认值，每次都得明确选（选错会把整段用量算反）
 *   2. 日期/时间/时点在保存后留在表单上，一次盘点连着录好几条不用重填
 *   3. 录完一条就显示「这次盘点已盘 N 项，还差 X、Y」——
 *      漏一项的后果是下次盘点把两段用量合成一段，看着像某段暴增
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/stock.php';
require_once __DIR__ . '/lib/report.php';   // 只用它的 Report::dow()（星期几）
require_once __DIR__ . '/lib/view.php';

$cfg     = Db::config();
$today   = date('Y-m-d', time() - (int) $cfg['day_cut_hour'] * 3600);
$items   = Stock::items();
$moments = Stock::moments();
// 「存入即用量」的品类名单，表单说明里要点名，不然没人知道为什么它们不能盘
$direct  = array_map(static fn($m) => $m['name'],
    array_filter($items, static fn($m) => $m['mode'] === Stock::MODE_DIRECT));

// 筛选条件
$fFrom = q('from', date('Y-m-d', strtotime($today . ' -14 day')));
$fTo   = q('to', $today);
$fItem = q('item');
$fKind = q('mk');
$fDel  = qbool('deleted');

$editId   = (int) q('edit', '0');
$storeErr = null;
$flash    = null;
$errors   = [];
$form     = [];
$progress = null;

/** 提交后跳回来，避免刷新重复提交 */
$selfUrl = static function (array $extra = []): string {
    $qs = array_merge(array_diff_key($_GET, ['edit' => 1, 'at' => 1]), $extra);
    return 'stock.php' . ($qs ? '?' . http_build_query($qs) : '');
};

// ---------------------------------------------------------------- 写操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    $id  = (int) ($_POST['id'] ?? 0);

    if (!Auth::csrfValid($_POST['csrf'] ?? null)) {
        $msg = ['err', '表单已过期，请重新提交'];
    } else {
        try {
            if ($act === 'delete' && $id > 0) {
                $msg = Stock::softDelete($id)
                    ? ['ok', "已作废 #{$id}（数据还在，可以恢复）"]
                    : ['err', '这条记录不存在或已经作废了'];
            } elseif ($act === 'restore' && $id > 0) {
                $msg = Stock::restore($id) ? ['ok', "已恢复 #{$id}"] : ['err', '恢复失败'];
            } elseif ($act === 'save') {
                [$clean, $errors] = Stock::validate($_POST);
                if ($errors) {
                    $form   = $_POST;          // 填过的内容留着，别让人重打
                    $msg    = null;
                    $editId = $id;             // 编辑时出错要留在编辑态
                } elseif ($id > 0) {
                    $msg = Stock::update($id, $clean) ? ['ok', "已保存 #{$id}"] : ['err', '这条记录不存在'];
                } else {
                    $newId = Stock::create($clean);
                    $msg = ['ok', '已记录 #' . $newId . '：'
                          . Stock::moveLabel($clean['move_kind']) . ' '
                          . Stock::itemLabel($clean['item']) . ' '
                          . qty($clean['qty']) . Stock::itemUnit($clean['item'])
                          . '（' . $clean['happened_at'] . '）'];
                }
            } else {
                $msg = null;
            }
        } catch (Throwable $e) {
            $msg = ['err', '保存失败：' . $e->getMessage()];
        }
    }

    if (empty($errors) && !empty($msg)) {
        Auth::boot();
        $_SESSION['flash'] = $msg;
        // 连续录入：日期、时间、时点、动作都带回表单 —— 一次盘点要录好几条，
        // 每条都重填这四项的话没人受得了
        $keep = [];
        if ($act === 'save' && $id === 0) {
            $keep = ['d'  => (string) ($_POST['happened_date'] ?? ''),
                     't'  => (string) ($_POST['happened_time'] ?? ''),
                     'm'  => (string) ($_POST['moment'] ?? ''),
                     'k'  => (string) ($_POST['move_kind'] ?? '')];
            $keep = array_filter($keep, static fn($v) => $v !== '');
            // 盘点时把「这轮盘到哪儿了」也带过去
            if (($_POST['move_kind'] ?? '') === Stock::COUNT && !empty($clean['happened_at'])) {
                $keep['at'] = $clean['happened_at'];
            }
        }
        header('Location: ' . $selfUrl($keep));
        exit;
    }
}

if (PHP_SAPI !== 'cli') {
    Auth::boot();
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
}

// ---------------------------------------------------------------- 读
$rows  = [];
$ready = Store::isReady();
if (!$ready) {
    $storeErr = Store::lastError();
} else {
    try {
        $rows = Stock::listRows(['from' => $fFrom, 'to' => $fTo, 'item' => $fItem,
                                 'move_kind' => $fKind, 'with_deleted' => $fDel]);
        $at = q('at');
        if ($at !== '') {
            $progress = Stock::countProgress($at);
        }
    } catch (Throwable $e) {
        $storeErr = $e->getMessage();
    }
}

// 编辑时把原值填进表单
if ($editId > 0 && !$form && $ready) {
    $row = Stock::find($editId);
    if ($row !== null) {
        $form = $row;
        $form['happened_date'] = substr((string) $row['happened_at'], 0, 10);
        $form['happened_time'] = substr((string) $row['happened_at'], 11, 5);
    } else {
        $editId = 0;
    }
}
$fv = static fn(string $k, $d = '') => h((string) ($form[$k] ?? $d));

// 当前筛选范围内的小结
$sum = ['rows' => 0, 'in' => 0, 'count' => 0];
foreach ($rows as $r) {
    if ($r['deleted_at'] !== null) {
        continue;
    }
    $sum['rows']++;
    $sum[(string) $r['move_kind'] === Stock::IN ? 'in' : 'count']++;
}

pageHeader('库存', 'stock');
?>

<nav class="subtabs">
  <a href="stock.php" class="on">录入与明细</a>
  <a href="stocknow.php">当前库存</a>
</nav>

<?php storeBanner(); ?>

<?php if ($storeErr): ?>
  <p class="err"><strong>数据文件不可用：</strong><?= h($storeErr) ?><br>
    数据文件路径：<code><?= h(Store::path()) ?></code><br>
    本程序对 POS 主库始终只读；库存记录存在这个独立的 SQLite 文件里，
    需要该目录对 Web 服务器账号可写。路径可在 config.php 的 <code>store_path</code> 改。</p>
<?php endif; ?>

<?php if ($flash): ?>
  <p class="<?= $flash[0] === 'ok' ? 'okmsg' : 'err' ?>"><?= h($flash[1]) ?></p>
<?php endif; ?>

<?php if ($progress !== null): ?>
  <p class="note" style="margin:-6px 0 14px">
    <strong>这一轮盘点（<?= h($progress['at']) ?>）</strong>
    已盘 <strong><?= num(count($progress['done'])) ?></strong> 项<?php
    if ($progress['missing']): ?>，还差 <strong class="stale"><?php
      $names = array_map(static fn($c) => Stock::itemLabel((string) $c), $progress['missing']);
      echo h(implode('、', $names)); ?></strong>
      —— 漏一项的话，下次盘点会把两段用量合成一段，看着像某段暴增。
    <?php else: ?>，<span class="state s-ok">全部盘完</span>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($ready): ?>

<form class="panel" method="post" action="<?= h($selfUrl()) ?>" id="entry">
  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="id" value="<?= (int) $editId ?>">

  <h3 style="margin:0 0 12px;font-size:15px">
    <?= $editId > 0 ? '修改 #' . (int) $editId : '记一笔库存' ?>
    <?php if ($editId > 0): ?>
      <a class="btn-mini" style="margin-left:10px" href="<?= h($selfUrl()) ?>">取消，改为新增</a>
    <?php endif; ?>
  </h3>

  <?php
  // 动作故意不给默认值：存入是「加上去」，盘点是「就是这么多」，
  // 选反了整段用量会算错方向，所以每次都要明确点一下
  $mk = (string) ($form['move_kind'] ?? q('k', ''));
  ?>
  <div class="row">
    <div class="pick">
      <span class="picklabel">动作 <span class="req">*</span></span>
      <label class="pickopt <?= $mk === Stock::IN ? 'on' : '' ?>">
        <input type="radio" name="move_kind" value="in" required <?= $mk === Stock::IN ? 'checked' : '' ?>>
        <span class="txt"><strong>存入</strong><small>放进库里，会加到账面上</small></span>
      </label>
      <label class="pickopt <?= $mk === Stock::COUNT ? 'on' : '' ?>">
        <input type="radio" name="move_kind" value="count" required <?= $mk === Stock::COUNT ? 'checked' : '' ?>>
        <span class="txt"><strong>盘点</strong><small>数出来现在有多少，是个绝对数</small></span>
      </label>
    </div>
    <?php if (isset($errors['move_kind'])): ?><em class="fe"><?= h($errors['move_kind']) ?></em><?php endif; ?>
  </div>

  <div class="row fields">
    <label class="w-md"><span class="cap">日期 <span class="req">*</span></span>
      <input type="date" name="happened_date" required
             value="<?= $fv('happened_date', q('d', $today)) ?>">
      <?php if (isset($errors['happened_date'])): ?><em class="fe"><?= h($errors['happened_date']) ?></em><?php endif; ?>
    </label>
    <label class="w-sm">时点
      <select name="moment"
              onchange="var o=this.options[this.selectedIndex],t=o.getAttribute('data-time');
                        if(t){this.form.happened_time.value=t;}">
        <option value="">不指定</option>
        <?php foreach ($moments as $code => $m): ?>
          <option value="<?= h($code) ?>" data-time="<?= h($m['time']) ?>"
            <?= (string) ($form['moment'] ?? q('m', '')) === $code ? 'selected' : '' ?>><?= h($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['moment'])): ?><em class="fe"><?= h($errors['moment']) ?></em><?php endif; ?>
    </label>
    <label class="w-sm"><span class="cap">时间 <span class="req">*</span></span>
      <input type="time" name="happened_time" required
             value="<?= $fv('happened_time', q('t', date('H:i'))) ?>">
      <?php if (isset($errors['happened_time'])): ?><em class="fe"><?= h($errors['happened_time']) ?></em><?php endif; ?>
    </label>
    <label class="w-lg"><span class="cap">品类 <span class="req">*</span></span>
      <select name="item" required>
        <option value="">请选择…</option>
        <?php foreach ($items as $code => $m): ?>
          <option value="<?= h($code) ?>" <?= ($form['item'] ?? '') === $code ? 'selected' : '' ?>>
            <?= h($m['name']) ?><?= $m['unit'] !== '' ? '（' . h($m['unit']) . '）' : '' ?><?php
            /* 口径直接写在选项里 —— 选完才被拒绝很烦，不如选之前就看见 */
            if ($m['mode'] === Stock::MODE_DIRECT) { echo ' · 存入即用量'; } ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['item'])): ?><em class="fe"><?= h($errors['item']) ?></em><?php endif; ?>
    </label>
    <label class="w-xs"><span class="cap">数量 <span class="req">*</span></span>
      <input type="text" inputmode="decimal" name="qty" placeholder="例 3"
             value="<?= $fv('qty') ?>">
      <?php if (isset($errors['qty'])): ?><em class="fe"><?= h($errors['qty']) ?></em><?php endif; ?>
    </label>
  </div>

  <div class="row">
    <label class="grow">备注
      <input type="text" name="note" placeholder="选填，比如「处理了一条大的」"
             value="<?= $fv('note') ?>"></label>
    <button type="submit"><?= $editId > 0 ? '保存修改' : '记录' ?></button>
  </div>

  <p class="note" style="margin:10px 0 0">
    <strong>单位每个品类是固定的</strong>（三文鱼条按箱、黑皮按盒…），选了品类就定了，不用填。
    要改单位或加品类，改 <code>lib/settings.php</code> 的 <code>stock_items</code>。
    <br>
    <strong>取出不用记</strong> —— 两次盘点之间少掉的就是取出量：
    <code>取出 = 上次盘点 + 期间存入 − 本次盘点</code>。
    比如原本 4 箱、今天存入 3 箱、盘点数出 5 箱，那就是用掉了 2 箱。
    <?php if ($direct): ?>
      <br>
      标着 <strong>· 存入即用量</strong> 的品类（<?= h(implode('、', $direct)) ?>）
      <strong>不参与盘点</strong> —— 它们切开之后大小不一、数不清，硬盘只会盘出假数字。
      这些只记<strong>存入</strong>，进多少就算用掉多少。
    <?php endif; ?>
  </p>
</form>

<form class="panel" method="get" action="stock.php">
  <div class="row">
    <label>从 <input type="date" name="from" value="<?= h($fFrom) ?>"></label>
    <label>到 <input type="date" name="to" value="<?= h($fTo) ?>"></label>
    <label>品类
      <select name="item">
        <option value="">全部</option>
        <?php foreach ($items as $code => $m): ?>
          <option value="<?= h($code) ?>" <?= $fItem === $code ? 'selected' : '' ?>><?= h($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>动作
      <select name="mk">
        <option value="">全部</option>
        <option value="in"    <?= $fKind === 'in'    ? 'selected' : '' ?>>只看存入</option>
        <option value="count" <?= $fKind === 'count' ? 'selected' : '' ?>>只看盘点</option>
      </select>
    </label>
    <label class="cb"><input type="checkbox" name="deleted" value="1" <?= $fDel ? 'checked' : '' ?>> 含已作废</label>
    <button type="submit">筛选</button>
  </div>
</form>

<section class="cards">
  <div class="card total"><h3>记录条数</h3><div class="big"><?= num($sum['rows']) ?></div>
    <dl><dt>范围</dt><dd><?= h($fFrom) ?> ~ <?= h($fTo) ?></dd></dl></div>
  <div class="card day"><h3>盘点</h3><div class="big"><?= num($sum['count']) ?>
    <span style="font-size:14px">条</span></div></div>
  <div class="card night"><h3>存入</h3><div class="big"><?= num($sum['in']) ?>
    <span style="font-size:14px">条</span></div></div>
</section>

<h2>库存流水（<?= num(count($rows)) ?>）</h2>
<?php if (!$rows): ?>
  <p class="empty">这个范围里还没有记录。</p>
<?php else: ?>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>时间</th><th class="hide-sm">时点</th><th>动作</th><th>品类</th>
    <th class="n">数量</th><th class="hide-sm">备注</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $gone = $r['deleted_at'] !== null;
      $isIn = (string) $r['move_kind'] === Stock::IN;
      $d    = substr((string) $r['happened_at'], 0, 10);
  ?>
    <tr class="<?= $gone ? 'row-skip' : '' ?>">
      <td class="date"><strong><?= h(substr($d, 5)) ?></strong>
        <span class="dim"><?= h(Report::dow($d)) ?></span>
        <span class="wd"><?= h(substr((string) $r['happened_at'], 11, 5)) ?></span></td>
      <td class="hide-sm dim"><?= h(Stock::momentLabel($r['moment'])) ?></td>
      <td><span class="state <?= $isIn ? 's-ok' : 's-noguest' ?>"><?= h(Stock::moveLabel($r['move_kind'])) ?></span></td>
      <td><strong><?= h(Stock::itemLabel((string) $r['item'])) ?></strong></td>
      <td class="n strong"><?= $isIn ? '+' : '' ?><?= qty($r['qty']) ?>
        <span class="dim"><?= h(Stock::itemUnit((string) $r['item'])) ?></span></td>
      <td class="hide-sm dim iname"><span title="<?= h((string) $r['note']) ?>"><?= h((string) $r['note']) ?></span></td>
      <td><?= $gone ? '<span class="state s-skip">已作废</span>' : '<span class="state s-ok">有效</span>' ?></td>
      <td class="ackcell">
        <?php if ($gone): ?>
          <form method="post" class="ackform">
            <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
            <input type="hidden" name="act" value="restore">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn-mini" type="submit">恢复</button></form>
        <?php else: ?>
          <a class="btn-mini" href="<?= h($selfUrl(['edit' => $r['id']])) ?>#entry">修改</a>
          <form method="post" class="ackform"
                onsubmit="return confirm('作废这条记录？数据会保留，之后可以恢复。');">
            <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn-mini" type="submit">作废</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<p class="note">
  这些记录存在<strong>独立的 SQLite 文件</strong>里（<?= storeWhere() ?>），
  与 POS 主库完全无关 —— 本程序对主库始终只读。
  「作废」是软删除，数据还在库里，可以恢复；每次新增、修改、作废都有留痕。
  <br>
  库存和采购是<strong>两本各自独立的账</strong>：采购买的是「一条三文鱼」，
  进冰箱前要分割处理，出来的是鱼条、黑皮、鱼沫 —— 数量和名字都对不上，
  所以程序里不做对应，也不要去加。
</p>

<?php endif; ?>

<?php pageFooter(); ?>
