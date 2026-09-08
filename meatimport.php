<?php
/**
 * 发票明细导入 —— 上传 → 核对 → 入库
 *
 * ⚠️ 本页写的是【自有的 SQLite】，与 POS 主库无关。
 *    主库那条只读线一个字节都不动，见「注意事项.md」铁律二。
 *
 * ============================================================
 *  为什么中间一定要有「核对」这一步
 * ============================================================
 *  按表头文字认列，认错是【静默】的：行数照样对、日期照样对，
 *  只有金额悄悄变成了另一列的数。真实文件上就出过这事 ——
 *  「含税单价」被认成「含税金额」，合计从两万变成七百八，
 *  不拿工作簿自己的汇总页对一遍根本发现不了。
 *
 *  所以这里把三样东西摆出来让人看：
 *    1. 每个字段认到了哪一列（date=B、weight=J…）
 *    2. 条数、公斤、金额、**均价 €/kg** —— 均价是认错列最灵的报警器
 *    3. 每一条跳过的行和跳过的原因
 *  确认无误再按「导入」。
 *
 * ============================================================
 *  上传的文件不落盘
 * ============================================================
 *  只在 PHP 的临时文件里解析一次，结果放进 session，然后临时文件由 PHP
 *  自己回收。程序不往任何目录写上传文件 —— 少一个能被人猜到网址下载的东西。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/meatimport.php';
require_once __DIR__ . '/lib/report.php';   // 只用它的 Report::dow()（星期几）
require_once __DIR__ . '/lib/view.php';

Auth::boot();

/** session 里暂存的解析结果（上传一次，核对／换表都不用再传） */
const PENDING = 'meatimport';

$flash    = null;
$err      = null;
$storeErr = null;

$ready = Store::isReady();
if (!$ready) {
    $storeErr = Store::lastError();
}

// ---------------------------------------------------------------- 写操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Auth::csrfValid($_POST['csrf'] ?? null)) {
        $err = '表单已过期，请重新提交';
    } elseif ($act === 'drop') {
        unset($_SESSION[PENDING]);
        header('Location: meatimport.php');
        exit;
    } elseif ($act === 'upload') {
        try {
            $_SESSION[PENDING] = uploadAndParse();
            header('Location: meatimport.php');
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } elseif ($act === 'import') {
        try {
            $_SESSION['flash'] = doImport();
            unset($_SESSION[PENDING]);
            header('Location: meatimport.php');
            exit;
        } catch (Throwable $e) {
            $err = '导入失败，一条都没进（整批是一个事务）：' . $e->getMessage();
        }
    }
}

if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/**
 * 收下上传的文件并解析。解析完就不再需要文件本身了。
 *
 * @return array 见 MeatImport::parseAll()，另加 'at'（上传时间）
 */
function uploadAndParse(): array
{
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        // PHP 的上传错误码本身没法看，翻译一下 —— 尤其是「超过 php.ini 上限」，
        // 不说清楚的话人只会看到「上传失败」，不知道该去调什么
        $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        throw new RuntimeException([
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器允许的大小（php.ini 的 upload_max_filesize）',
            UPLOAD_ERR_FORM_SIZE  => '文件太大',
            UPLOAD_ERR_PARTIAL    => '文件只传上来一半，请重试',
            UPLOAD_ERR_NO_FILE    => '请先选一个文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器没有临时目录，传不了文件',
            UPLOAD_ERR_CANT_WRITE => '服务器写不了临时文件',
            UPLOAD_ERR_EXTENSION  => '有 PHP 扩展挡下了这次上传',
        ][$code] ?? "上传失败（错误码 {$code}）");
    }
    $tmp  = (string) $f['tmp_name'];
    $name = basename((string) $f['name']);

    // is_uploaded_file：确认这真是这次请求传上来的文件，
    // 而不是被人构造出来指向服务器上别的文件的路径
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('这不是一次正常的上传');
    }
    if (!preg_match('/\.(xlsx|csv)$/i', $name)) {
        throw new RuntimeException('只认 .xlsx 和 .csv；.xls（老格式）请先另存为 .xlsx');
    }
    if ((int) $f['size'] > Xlsx::MAX_BYTES) {
        throw new RuntimeException('文件超过 ' . (int) (Xlsx::MAX_BYTES / 1048576) . 'MB');
    }

    $all = MeatImport::parseAll($tmp, $name);
    if ($all['best'] === null) {
        throw new RuntimeException('认不出表头 —— 表里要能找到「日期」「类别」「重量」这几列。'
            . (count($all['sheets']) > 1
                ? '这个文件的 ' . count($all['sheets']) . ' 张表都认不出：'
                  . implode('、', $all['sheets'])
                : ''));
    }
    $all['at'] = date('Y-m-d H:i');
    return $all;
}

/** 把当前选中的这张表导进去 */
function doImport(): array
{
    $all = $_SESSION[PENDING] ?? null;
    if (!is_array($all)) {
        throw new RuntimeException('没有待导入的数据，请重新上传');
    }
    $i   = (int) ($_POST['sheet'] ?? -1);
    $res = $all['parsed'][$i] ?? null;
    if (!is_array($res) || isset($res['error'])) {
        throw new RuntimeException('这张表不能导入');
    }
    // 提交的是哪一批要对得上：预览之后有人换了表、或者 session 被别的
    // 标签页覆盖了，就不能照着旧的按钮把另一批数据导进去
    if ((string) ($_POST['stamp'] ?? '') !== stamp($res)) {
        throw new RuntimeException('这批数据和你看到的预览对不上（可能在别的标签页里换过表），请重新核对');
    }

    $items = [];
    foreach ($res['rows'] as $r) {
        if (($r['clean'] ?? null) !== null) {
            $items[] = ['clean' => $r['clean'], 'key' => $r['key']];
        }
    }
    if (!$items) {
        throw new RuntimeException('这张表没有一条能导入的记录');
    }
    $out = Meat::createMany($items);

    $s = $res['summary'];
    $msg = "已导入 {$out['inserted']} 条"
         . ($out['duplicate'] > 0 ? "，另有 {$out['duplicate']} 条早就导过了，跳过" : '')
         . '；合计 ' . qty($s['kg']) . ' kg / ' . money($s['money']) . ' €（含税）';
    if ($s['skip'] > 0) {
        $msg .= "。文件里还有 {$s['skip']} 条没导（原因见上传后的核对页），请人工处理";
    }
    return ['ok', $msg];
}

/** 这一批数据的指纹：预览给人看的和最后导进去的必须是同一批 */
function stamp(array $res): string
{
    return substr(sha1(json_encode([$res['sheet'], $res['header'], $res['map'],
                                    array_column($res['rows'], 'key')])), 0, 16);
}

// ---------------------------------------------------------------- 读
$all   = $_SESSION[PENDING] ?? null;
$view  = null;      // 当前正在核对的那张表
$vIdx  = null;
$known = [];        // 已经在库里的指纹
if (is_array($all)) {
    $vIdx = $_GET['sheet'] ?? null;
    $vIdx = is_numeric($vIdx) ? (int) $vIdx : $all['best'];
    $res  = $all['parsed'][$vIdx] ?? null;
    if (!is_array($res) || isset($res['error'])) {
        $vIdx = $all['best'];
        $res  = $all['parsed'][$vIdx] ?? null;
    }
    $view = is_array($res) && !isset($res['error']) ? $res : null;

    if ($view !== null && $ready) {
        try {
            $known = Meat::existingKeys(array_filter(array_column($view['rows'], 'key')));
        } catch (Throwable $e) {
            $storeErr = $storeErr ?? $e->getMessage();
        }
    }
}

/** 字段的中文名，核对表头用 */
$fieldName = [
    'date' => '日期', 'kind' => '品类', 'weight' => '重量', 'money_inc' => '含税金额',
    'money' => '金额', 'vat' => '税率', 'supplier' => '供货商', 'type' => '类型',
    'invoice' => '发票号', 'desc' => '品名',
];
$basisName = [
    'incl'     => '文件直接给了含税金额，原样存',
    'computed' => '文件给的是未税金额，按表里的税率折算成含税再存',
    'raw'      => '没找到含税金额，也没找到税率 —— 金额原样存',
];

pageHeader('发票导入', 'meat');
?>

<nav class="subtabs">
  <a href="meat.php">录入与明细</a>
  <a href="meatimport.php" class="on">发票导入</a>
  <a href="meatweek.php">周报表</a>
</nav>

<?php storeBanner(); ?>

<?php if ($storeErr): ?>
  <p class="err"><strong>数据文件不可用：</strong><?= h($storeErr) ?><br>
    数据文件路径：<code><?= h(Store::path()) ?></code></p>
<?php endif; ?>

<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if ($flash): ?>
  <p class="<?= $flash[0] === 'ok' ? 'okmsg' : 'err' ?>"><?= h($flash[1]) ?></p>
<?php endif; ?>

<?php if ($ready): ?>

<form class="panel" method="post" enctype="multipart/form-data" action="meatimport.php">
  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="act" value="upload">
  <h3 style="margin:0 0 12px;font-size:15px">上传发票明细</h3>
  <div class="row">
    <label class="grow"><span class="cap">文件（.xlsx 或 .csv）</span>
      <input type="file" name="file" accept=".xlsx,.csv" required></label>
    <button type="submit">读取并核对</button>
  </div>
  <p class="note" style="margin:10px 0 0">
    读进来<strong>先不入库</strong>，会把「每个字段认到了哪一列」和合计摆出来给你核对，
    确认了再按导入。同一份文件导第二次不会翻倍 —— 已经导过的行会被认出来跳过。
  </p>
</form>

<?php if ($view === null): ?>
  <?php if (is_array($all)): ?>
    <p class="err">这个文件里没有能用的表。</p>
  <?php endif; ?>
  <p class="note">
    表里要能找到<strong>日期、类别、重量</strong>这几列（中西文都认）。
    列的先后顺序无所谓 —— 是按表头文字认的，不是按第几列。
    <br>
    金额统一存<strong>含税</strong>：营业额那边取的是 POS 的实收（价内含税），
    两边同口径才比得了。文件只给未税金额时，按表里的税率折算。
    <br>
    <strong>退货行（负数）一律跳过</strong>，不做抵扣 ——
    悄悄扣掉会让合计和发票对不上，而对不上的时候没人知道是哪里扣的。
  </p>
<?php else: ?>

<?php $s = $view['summary']; $st = stamp($view); $sane = MeatImport::sanePerKg(); ?>

<h2>核对：<?= h((string) $all['file']) ?>
  <span class="dim" style="font-weight:400;font-size:13px">
    （<?= h((string) $all['at']) ?> 上传）</span></h2>

<?php if (count($all['sheets']) > 1): ?>
  <div class="presets" style="margin:0 0 14px">
    <?php foreach ($all['sheets'] as $i => $nm):
        $p  = $all['parsed'][$i] ?? null;
        $bad = !is_array($p) || isset($p['error']);
    ?>
      <?php if ($bad): ?>
        <span class="dim" style="font-size:13px;padding:6px 14px"
              title="<?= h(is_array($p) ? (string) $p['error'] : '') ?>"><?= h($nm) ?>（认不出）</span>
      <?php else: ?>
        <a href="meatimport.php?sheet=<?= (int) $i ?>"
           style="<?= $i === $vIdx ? 'border-color:var(--accent);color:var(--accent)' : '' ?>">
          <?= h($nm) ?>（<?= num($p['summary']['ok']) ?> 条）</a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php foreach ($s['warn'] as $w): ?>
  <p class="err"><strong>⚠️ 请核对：</strong><?= h($w) ?></p>
<?php endforeach; ?>

<section class="cards">
  <div class="card total"><h3>可导入</h3><div class="big"><?= num($s['ok']) ?></div>
    <dl>
      <?php if ($s['skip'] > 0): ?><dt>跳过</dt><dd><?= num($s['skip']) ?> 条</dd><?php endif; ?>
      <?php if ($s['from'] !== null): ?>
        <dt>日期范围</dt><dd><?= h($s['from']) ?> ~ <?= h($s['to']) ?></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card day"><h3>重量合计</h3>
    <div class="big"><?= qty($s['kg']) ?> <span style="font-size:14px">kg</span></div></div>
  <div class="card night"><h3>金额合计（含税）</h3>
    <div class="big"><?= money($s['money']) ?></div>
    <dl><dt>均价</dt>
      <dd><?= $s['per_kg'] === null ? '—' : money($s['per_kg']) . ' €/kg' ?></dd></dl>
  </div>
</section>

<div class="panel">
  <h3 style="margin:0 0 10px;font-size:15px">认出来的列</h3>
  <p class="note" style="margin:0 0 10px">
    <strong>这一行是最该核的</strong> —— 认错列不会报错，只会把另一列的数字悄悄存进来。
    对着原始文件看一眼列字母对不对。
  </p>
  <div class="presets" style="margin:0">
    <?php foreach ($fieldName as $f => $cn): ?>
      <span style="font-size:13px;padding:6px 14px;border:1px solid var(--line);
                   border-radius:20px;<?= isset($view['map'][$f]) ? '' : 'color:var(--dim)' ?>">
        <?= h($cn) ?>：<strong><?= isset($view['map'][$f]) ? h($view['map'][$f]) . ' 列' : '没找到' ?></strong>
      </span>
    <?php endforeach; ?>
  </div>
  <p class="note" style="margin:12px 0 0">
    表头在第 <strong><?= (int) $view['header'] ?></strong> 行。
    金额口径：<strong><?= h($basisName[$view['basis']] ?? $view['basis']) ?></strong>。
  </p>
</div>

<?php
$dupInDb = 0;
foreach ($view['rows'] as $r) {
    if (($r['clean'] ?? null) !== null && isset($known[(string) $r['key']])) {
        $dupInDb++;
    }
}
?>
<?php if ($dupInDb > 0): ?>
  <p class="note" style="margin:-6px 0 14px">
    <span class="state s-ok">已导过</span>
    其中 <strong><?= num($dupInDb) ?></strong> 条早就在库里了，导入时会自动跳过，不会重复。
  </p>
<?php endif; ?>

<form method="post" action="meatimport.php" class="panel"
      onsubmit="return confirm('把这 <?= (int) ($s['ok'] - $dupInDb) ?> 条记录写进采购表？');">
  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="act" value="import">
  <input type="hidden" name="sheet" value="<?= (int) $vIdx ?>">
  <input type="hidden" name="stamp" value="<?= h($st) ?>">
  <div class="row" style="align-items:center">
    <button type="submit">确认无误，导入 <?= num($s['ok'] - $dupInDb) ?> 条</button>
    <span class="hint">整批一个事务：要么全进，要么一条不进。导进来的每条都有留痕，可以逐条作废。</span>
  </div>
</form>
<form method="post" action="meatimport.php" style="margin:-8px 0 18px">
  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="act" value="drop">
  <button class="btn-mini" type="submit">放弃这次上传</button>
</form>

<h2>逐条核对（<?= num(count($view['rows'])) ?>）</h2>
<div class="tablewrap"><table class="grid stick small">
  <thead><tr>
    <th>行</th><th>日期</th><th>品类</th>
    <th class="n">重量 kg</th><th class="n">含税金额</th><th class="n">€/kg</th>
    <th class="hide-sm">供货商</th><th class="hide-sm">备注</th><th>状态</th>
  </tr></thead>
  <tbody>
  <?php foreach ($view['rows'] as $r):
      $c    = $r['clean'] ?? null;
      $gone = $c === null;
      $dup  = !$gone && isset($known[(string) $r['key']]);
      $pk   = ($c && $c['total_price'] !== null && (float) $c['weight_kg'] > 0)
              ? (float) $c['total_price'] / (float) $c['weight_kg'] : null;
      $odd  = $pk !== null && ($pk < $sane[0] || $pk > $sane[1]);
  ?>
    <tr class="<?= $gone ? 'row-skip' : ($odd ? 'row-warn' : '') ?>">
      <td class="dim"><?= (int) $r['line'] ?></td>
      <?php if ($gone): ?>
        <td colspan="7"><span class="dim"><?= h(implode(' / ', array_filter([
              $r['raw']['date'], $r['raw']['kind'], $r['raw']['weight'],
              $r['raw']['money']], static fn($x) => (string) $x !== ''))) ?></span></td>
        <td><span class="state s-skip">跳过</span><br>
          <span class="dim" style="font-size:12px"><?= h((string) $r['skip']) ?></span></td>
      <?php else: ?>
        <td class="date"><?= h(substr((string) $c['purchase_date'], 5)) ?>
          <span class="dim"><?= h(Report::dow((string) $c['purchase_date'])) ?></span></td>
        <td><strong><?= h(Meat::kindLabel((string) $c['kind'])) ?></strong></td>
        <td class="n"><?= qty($c['weight_kg']) ?></td>
        <td class="n"><?= $c['total_price'] === null
              ? '<span class="dim">—</span>' : money($c['total_price']) ?></td>
        <td class="n"><?= $pk === null ? '<span class="dim">—</span>' : money($pk) ?></td>
        <td class="hide-sm dim"><?= h((string) $c['supplier']) ?></td>
        <td class="hide-sm dim iname"><span title="<?= h((string) $c['note']) ?>"><?= h((string) $c['note']) ?></span></td>
        <td><?= $dup ? '<span class="state s-ok">已导过</span>'
                     : '<span class="state s-dshort">待导入</span>' ?></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<p class="note">
  <strong>跳过的行不会自己消失</strong> —— 退货、认不出的品类、读不出的日期都列在上面，
  该人工补的请到<a href="meat.php">「录入与明细」</a>手工录一条。
  导进来的记录和手工录的完全一样：能改、能作废、能恢复，每次改动都有留痕。
  <br>
  数据存在<strong>独立的 SQLite 文件</strong>里（<?= storeWhere() ?>），
  与 POS 主库完全无关 —— 本程序对主库始终只读。
</p>

<?php endif; ?>
<?php endif; ?>

<?php pageFooter(); ?>
