<?php
/**
 * 肉类采购记录 —— 录入与列表
 *
 * ⚠️ 本页写的是【自有的 SQLite】，与 POS 主库无关。
 *    主库那条只读线一个字节都不动，见「注意事项.md」铁律二。
 *
 * 录入时机有三种，页面都要撑得住：
 *   1. 到货当天就录 —— 那时只有条数，没有公斤和价格
 *   2. 发票到了再录 —— 一次填完
 *   3. 月底一起补录 —— 日期要能往回填，而且要能连着录很多条
 * 所以：重量和件数至少填一个，价格全部选填；
 * 缺重量或缺总价的记录会进「待补发票」栏，月底照着补就不会漏。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/meat.php';
require_once __DIR__ . '/lib/report.php';   // 只用它的 Report::dow()（星期几）
require_once __DIR__ . '/lib/view.php';

$cfg   = Db::config();
$today = date('Y-m-d', time() - (int) $cfg['day_cut_hour'] * 3600);
$kinds = Meat::kinds();
$sups  = Meat::suppliers();

// 筛选条件
$fFrom = q('from', date('Y-m-d', strtotime($today . ' -30 day')));
$fTo   = q('to', $today);
$fKind = q('kind');
$fPend = qbool('pending');
$fDel  = qbool('deleted');

$editId = (int) q('edit', '0');
$storeErr = null;
$flash = null;
$errors = [];
$form = [];

/** 提交后跳回来，避免刷新重复提交 */
$selfUrl = static function (array $extra = []): string {
    $qs = array_merge(array_diff_key($_GET, ['edit' => 1, 'ok' => 1]), $extra);
    return 'meat.php' . ($qs ? '?' . http_build_query($qs) : '');
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
                $msg = Meat::softDelete($id)
                    ? ['ok', "已作废 #{$id}（数据还在，可以恢复）"]
                    : ['err', '这条记录不存在或已经作废了'];
            } elseif ($act === 'restore' && $id > 0) {
                $msg = Meat::restore($id) ? ['ok', "已恢复 #{$id}"] : ['err', '恢复失败'];
            } elseif ($act === 'save') {
                [$clean, $errors] = Meat::validate($_POST);
                if ($errors) {
                    $form = $_POST;                 // 填过的内容留在表单上，别让人重打
                    $msg  = null;
                    $editId = $id;                  // 编辑时出错要留在编辑态
                } elseif ($id > 0) {
                    $msg = Meat::update($id, $clean) ? ['ok', "已保存 #{$id}"] : ['err', '这条记录不存在'];
                } else {
                    $newId = Meat::create($clean);
                    $msg = ['ok', '已记录 #' . $newId . '：'
                          . Meat::kindLabel($clean['kind']) . ' ' . $clean['purchase_date']];
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
        // 连续录入：日期和供应商带回表单，接着录下一条不用重填
        $keep = [];
        if ($act === 'save' && $id === 0) {
            $keep = ['d' => (string) ($_POST['purchase_date'] ?? ''),
                     's' => (string) ($_POST['supplier'] ?? '')];
            $keep = array_filter($keep, static fn($v) => $v !== '');
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
$rows = [];
$uw = [];
$pendingCount = 0;
$ready = Store::isReady();
if (!$ready) {
    $storeErr = Store::lastError();
} else {
    try {
        $rows = Meat::listRows(['from' => $fFrom, 'to' => $fTo, 'kind' => $fKind,
                                'only_pending' => $fPend, 'with_deleted' => $fDel]);
        $uw = Meat::unitWeights();
        $pendingCount = count(Meat::listRows(['only_pending' => 1]));
    } catch (Throwable $e) {
        $storeErr = $e->getMessage();
    }
}

// 编辑时把原值填进表单
if ($editId > 0 && !$form && $ready) {
    $row = Meat::find($editId);
    if ($row !== null) {
        $form = $row;
    } else {
        $editId = 0;
    }
}
$fv = static fn(string $k, $d = '') => h((string) ($form[$k] ?? $d));

// 汇总（只统计当前筛选出来的、未作废的行）
$sum = ['rows' => 0, 'kg' => 0.0, 'kg_est' => 0.0, 'est_rows' => 0,
        'no_kg' => 0, 'money' => 0.0, 'no_money' => 0];
foreach ($rows as $r) {
    if ($r['deleted_at'] !== null) {
        continue;
    }
    $sum['rows']++;
    [$w, $isEst] = Meat::statWeight($r, $uw);
    if ($w === null) {
        $sum['no_kg']++;
    } elseif ($isEst) {
        $sum['kg_est'] += $w;
        $sum['est_rows']++;
    } else {
        $sum['kg'] += $w;
    }
    if ($r['total_price'] !== null) {
        $sum['money'] += (float) $r['total_price'];
    } else {
        $sum['no_money']++;
    }
}

pageHeader('肉类采购', 'meat');
?>

<nav class="subtabs">
  <a href="meat.php" class="on">录入与明细</a>
  <a href="meatweek.php">周报表</a>
</nav>

<?php storeBanner(); ?>

<?php if ($storeErr): ?>
  <p class="err"><strong>数据文件不可用：</strong><?= h($storeErr) ?><br>
    数据文件路径：<code><?= h(Store::path()) ?></code><br>
    本程序对 POS 主库始终只读；采购记录存在这个独立的 SQLite 文件里，
    需要该目录对 Web 服务器账号可写。路径可在 config.php 的 <code>store_path</code> 改。</p>
<?php endif; ?>

<?php if ($flash): ?>
  <p class="<?= $flash[0] === 'ok' ? 'okmsg' : 'err' ?>"><?= h($flash[1]) ?></p>
<?php endif; ?>

<?php if ($ready): ?>

<form class="panel" method="post" action="<?= h($selfUrl()) ?>" id="entry">
  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="id" value="<?= (int) $editId ?>">

  <h3 style="margin:0 0 12px;font-size:15px">
    <?= $editId > 0 ? '修改 #' . (int) $editId : '记一笔采购' ?>
    <?php if ($editId > 0): ?>
      <a class="btn-mini" style="margin-left:10px" href="<?= h($selfUrl()) ?>">取消，改为新增</a>
    <?php endif; ?>
  </h3>

  <div class="row">
    <label><span class="cap">日期 <span class="req">*</span></span>
      <input type="date" name="purchase_date" required
             value="<?= $fv('purchase_date', q('d', $today)) ?>">
      <?php if (isset($errors['purchase_date'])): ?><em class="fe"><?= h($errors['purchase_date']) ?></em><?php endif; ?>
    </label>
    <label><span class="cap">品类 <span class="req">*</span></span>
      <select name="kind" required>
        <option value="">请选择…</option>
        <?php foreach ($kinds as $code => $name): ?>
          <option value="<?= h($code) ?>" <?= ($form['kind'] ?? '') === $code ? 'selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['kind'])): ?><em class="fe"><?= h($errors['kind']) ?></em><?php endif; ?>
    </label>
    <label>重量（kg）
      <input type="text" inputmode="decimal" name="weight_kg" placeholder="例 12.6"
             value="<?= $fv('weight_kg') ?>">
      <?php if (isset($errors['weight_kg'])): ?><em class="fe"><?= h($errors['weight_kg']) ?></em><?php endif; ?>
    </label>
    <label>件数
      <input type="text" inputmode="decimal" name="unit_count" placeholder="例 3"
             value="<?= $fv('unit_count') ?>">
      <?php if (isset($errors['unit_count'])): ?><em class="fe"><?= h($errors['unit_count']) ?></em><?php endif; ?>
    </label>
    <label>单位
      <select name="unit_type">
        <option value="">—</option>
        <option value="piece" <?= ($form['unit_type'] ?? '') === 'piece' ? 'selected' : '' ?>>条</option>
        <option value="pack"  <?= ($form['unit_type'] ?? '') === 'pack'  ? 'selected' : '' ?>>包</option>
      </select>
      <?php if (isset($errors['unit_type'])): ?><em class="fe"><?= h($errors['unit_type']) ?></em><?php endif; ?>
    </label>
  </div>

  <div class="row">
    <label>单价
      <input type="text" inputmode="decimal" name="unit_price" placeholder="选填"
             value="<?= $fv('unit_price') ?>">
      <?php if (isset($errors['unit_price'])): ?><em class="fe"><?= h($errors['unit_price']) ?></em><?php endif; ?>
    </label>
    <label>单价按
      <select name="price_basis">
        <option value="">—</option>
        <option value="kg"   <?= ($form['price_basis'] ?? '') === 'kg'   ? 'selected' : '' ?>>每公斤</option>
        <option value="unit" <?= ($form['price_basis'] ?? '') === 'unit' ? 'selected' : '' ?>>每件</option>
      </select>
      <?php if (isset($errors['price_basis'])): ?><em class="fe"><?= h($errors['price_basis']) ?></em><?php endif; ?>
    </label>
    <label>总价
      <input type="text" inputmode="decimal" name="total_price" placeholder="选填"
             value="<?= $fv('total_price') ?>">
      <?php if (isset($errors['total_price'])): ?><em class="fe"><?= h($errors['total_price']) ?></em><?php endif; ?>
    </label>
    <label class="grow">供应商
      <input type="text" name="supplier" list="suplist" placeholder="选填"
             value="<?= $fv('supplier', q('s')) ?>">
      <?php if ($sups): ?>
        <datalist id="suplist">
          <?php foreach ($sups as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?>
        </datalist>
      <?php endif; ?>
    </label>
  </div>

  <div class="row">
    <label class="grow">备注
      <input type="text" name="note" placeholder="选填，比如「质量不好」「临时补货」"
             value="<?= $fv('note') ?>"></label>
    <button type="submit"><?= $editId > 0 ? '保存修改' : '记录' ?></button>
  </div>

  <p class="note" style="margin:10px 0 0">
    <strong>重量和件数至少填一个</strong> —— 到货时只知道条数就先填条数，
    发票到了再回来补公斤和价格。价格全部选填。
    小数点写成 <code>12,6</code> 或 <code>12.6</code> 都认。
  </p>
</form>

<?php if ($pendingCount > 0): ?>
  <p class="note" style="margin:-6px 0 14px">
    <span class="state s-dshort">待补发票</span>
    全部记录里有 <strong><?= num($pendingCount) ?></strong> 条还缺重量或总价。
    <a href="<?= h($selfUrl(['pending' => 1, 'from' => '', 'to' => ''])) ?>">只看这些 →</a>
  </p>
<?php endif; ?>

<form class="panel" method="get" action="meat.php">
  <div class="row">
    <label>从 <input type="date" name="from" value="<?= h($fFrom) ?>"></label>
    <label>到 <input type="date" name="to" value="<?= h($fTo) ?>"></label>
    <label>品类
      <select name="kind">
        <option value="">全部</option>
        <?php foreach ($kinds as $code => $name): ?>
          <option value="<?= h($code) ?>" <?= $fKind === $code ? 'selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="cb"><input type="checkbox" name="pending" value="1" <?= $fPend ? 'checked' : '' ?>> 只看待补发票</label>
    <label class="cb"><input type="checkbox" name="deleted" value="1" <?= $fDel ? 'checked' : '' ?>> 含已作废</label>
    <button type="submit">筛选</button>
  </div>
</form>

<section class="cards">
  <div class="card total"><h3>记录条数</h3><div class="big"><?= num($sum['rows']) ?></div></div>
  <div class="card day"><h3>重量合计</h3>
    <div class="big"><?= qty($sum['kg'] + $sum['kg_est']) ?> <span style="font-size:14px">kg</span></div>
    <dl>
      <?php if ($sum['est_rows'] > 0): ?>
        <dt>其中估算</dt><dd>≈<?= qty($sum['kg_est']) ?>（<?= num($sum['est_rows']) ?> 条）</dd>
      <?php endif; ?>
      <?php if ($sum['no_kg'] > 0): ?>
        <dt>缺重量</dt><dd><?= num($sum['no_kg']) ?> 条</dd>
      <?php endif; ?>
    </dl>
  </div>
  <div class="card night"><h3>金额合计</h3>
    <div class="big"><?= money($sum['money']) ?></div>
    <?php if ($sum['no_money'] > 0): ?>
      <dl><dt>缺总价</dt><dd><?= num($sum['no_money']) ?> 条</dd></dl>
    <?php endif; ?>
  </div>
</section>

<h2>采购明细（<?= num(count($rows)) ?>）</h2>
<?php if (!$rows): ?>
  <p class="empty">这个范围里还没有记录。</p>
<?php else: ?>
<div class="tablewrap"><table class="grid stick">
  <thead><tr>
    <th>日期</th><th>品类</th>
    <th class="n">重量 kg</th><th class="n">件数</th>
    <th class="n">单价</th><th class="n">总价</th>
    <th class="hide-sm">供应商</th><th class="hide-sm">备注</th>
    <th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      [$w, $isEst] = Meat::statWeight($r, $uw);
      $gone = $r['deleted_at'] !== null;
      $pend = !$gone && Meat::needsInvoice($r);
      // 手填的总价和「单价×数量」对不上就提醒 —— 多半是有一处打错了
      $dt = Meat::derivedTotal($r);
      $mismatch = $dt !== null && $r['total_price'] !== null
                  && abs($dt - (float) $r['total_price']) > max(0.05, $dt * 0.01);
  ?>
    <tr class="<?= $gone ? 'row-skip' : ($pend ? 'row-warn' : '') ?>">
      <td class="date"><?= h(substr((string) $r['purchase_date'], 5)) ?>
        <span class="dim"><?= h(Report::dow((string) $r['purchase_date'])) ?></span></td>
      <td><strong><?= h(Meat::kindLabel((string) $r['kind'])) ?></strong></td>
      <td class="n">
        <?php if ($r['weight_kg'] !== null): ?><?= qty($r['weight_kg']) ?>
        <?php elseif ($w !== null): ?><span class="est" title="按该品类平均条重估算">≈<?= qty($w) ?></span>
        <?php else: ?><span class="dim">—</span><?php endif; ?>
      </td>
      <td class="n"><?= $r['unit_count'] !== null
            ? qty($r['unit_count']) . ' ' . h(Meat::unitLabel($r['unit_type'])) : '—' ?></td>
      <td class="n"><?= $r['unit_price'] !== null
            ? money($r['unit_price']) . ' <span class="dim">/' . h($r['price_basis'] === 'kg' ? 'kg' : '件') . '</span>'
            : '—' ?></td>
      <td class="n"><?= $r['total_price'] !== null ? money($r['total_price']) : '<span class="dim">—</span>' ?>
        <?php if ($mismatch): ?><span class="d" title="单价×数量 = <?= h(money($dt)) ?>，与总价对不上">?</span><?php endif; ?></td>
      <td class="hide-sm dim"><?= h((string) $r['supplier']) ?></td>
      <td class="hide-sm dim iname"><span title="<?= h((string) $r['note']) ?>"><?= h((string) $r['note']) ?></span></td>
      <td>
        <?php if ($gone): ?><span class="state s-skip">已作废</span>
        <?php elseif ($pend): ?><span class="state s-dshort">待补发票</span>
        <?php else: ?><span class="state s-ok">完整</span><?php endif; ?>
      </td>
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

<details class="station" style="margin-top:18px">
  <summary><span class="pcname">各品类的平均条重（用于估算缺重量的记录）</span>
    <span class="pcmeta">只用「重量和件数都填了」的记录算</span></summary>
  <div style="padding:14px 16px">
    <?php if (!$uw): ?>
      <p class="empty">还没有「重量和件数都填了」的记录，暂时算不出来。</p>
    <?php else: ?>
    <div class="tablewrap"><table class="grid small stick">
      <thead><tr><th>品类</th><th class="n">中位数</th><th class="n">样本</th>
        <th class="n">范围</th><th>能否用于估算</th></tr></thead>
      <tbody>
      <?php foreach ($uw as $k => $u): ?>
        <tr>
          <td><strong><?= h(Meat::kindLabel((string) $k)) ?></strong></td>
          <td class="n"><?= qty($u['per']) ?> kg/<?= h(Meat::unitLabel($u['unit'])) ?></td>
          <td class="n"><?= num($u['n']) ?> 条</td>
          <td class="n dim"><?= qty($u['min']) ?> – <?= qty($u['max']) ?></td>
          <td><?= $u['n'] >= Meat::MIN_SAMPLES
                ? '<span class="state s-ok">可以</span>'
                : '<span class="state s-noguest">样本不足 ' . Meat::MIN_SAMPLES . ' 条</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="note">
      用<strong>中位数</strong>不用平均数：偶尔进到特别大或特别小的一批，平均数会被带跑，中位数不会。
      这个系数是<strong>你自家供应商的真实规格</strong>算出来的，不是估的，录得越多越准。
      只有缺重量的记录才会用它折算，结果一律带 <span class="est">≈</span> 标出来，不和实测数混在一起。
      <br>
      范围拉得很开（比如 3.6–8.9）说明这个品类规格不统一，估算值就别太当真；
      某次进货算出来的条重远超历史范围，多半是<strong>录错了</strong>，值得回头核一下。
    </p>
    <?php endif; ?>
  </div>
</details>

<p class="note">
  这些记录存在<strong>独立的 SQLite 文件</strong>里（<code><?= h(Store::path()) ?></code>），
  与 POS 主库完全无关 —— 本程序对主库始终只读。
  「作废」是软删除，数据还在库里，可以恢复；每次新增、修改、作废都有留痕。
</p>

<?php endif; ?>

<?php pageFooter(); ?>
