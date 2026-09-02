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
 * 算作「按人头套餐」的菜品由 combo_item_ids 决定（默认在 lib/settings.php，
 * 可在 config.php 里覆盖），页面底部会列出当前生效的清单。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/lib/ack.php';
require_once __DIR__ . '/lib/biz.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/view.php';

$cfg        = Db::config();
$comboIds   = array_map('intval', (array) ($cfg['combo_item_ids'] ?? []));
$warnHours  = (int) ($cfg['open_table_warn_hours'] ?? 4);
// 外带（Llevar）之类的台不按人头核对。默认规则在 lib/settings.php，
// 站点想改就在 config.php 里写同名的键覆盖
$skipRules  = Report::skipRules($cfg);
$skipCustom = array_key_exists('no_combo_tables', Db::overrides());
// 酒水核对：每人至少几份（0 = 只统计不核对）
$minDrink   = (float) ($cfg['drink_min_per_guest'] ?? 1);
$onlyOpen   = !isset($_GET['scope']) || q('scope') !== 'all';
$onlyIssues = qbool('issues');
// 二次确认：ask=订单号 时，该行原地展开「确定吗？」，避免误点就生效
$askId      = (int) q('ask', '0');

$error = null;
$data  = null;
$meta  = [];
$menuItems = [];
$printClasses = [];
$drinks = ['ids' => [], 'classes' => [], 'patterns' => [], 'extra' => [], 'excluded' => []];

/** 回到当前视图（保留筛选条件），用于「提交后跳转」避免刷新重复提交 */
$selfUrl = static function (array $extra = []): string {
    $qs = array_merge(array_diff_key($_GET, ['ask' => 1]), $extra);
    return 'open.php' . ($qs ? '?' . http_build_query($qs) : '');
};

try {
    $menuItems    = Biz::menuItems();
    $printClasses = Biz::printClasses();
    // 酒水口径：按出品岗位名匹配出菜品清单，页面底部会列出来
    $drinks   = Report::drinkItems($menuItems, $printClasses, $cfg);
    $drinkIds = $drinks['ids'];

    $t0    = microtime(true);
    $heads = Biz::openTables($onlyOpen);
    $meta['queries'][] = ['order_head', count($heads), microtime(true) - $t0];

    $counts = [];
    if ($heads) {
        $ids = array_column($heads, 'order_head_id');
        $t1  = microtime(true);
        $counts = Biz::orderComboCounts($ids, $comboIds, $drinkIds);
        $meta['queries'][] = ['order_detail', count($counts), microtime(true) - $t1];
    }

    // ---- 处理人工确认 / 撤销（POST + CSRF，提交后跳转回来）----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fresh = Report::buildOpenTables($heads, $counts, $warnHours, [], $skipRules,
                                         ['min_drink' => $minDrink]);
        $byId  = [];
        foreach ($fresh['rows'] as $r) {
            $byId[$r['id']] = $r;
        }

        $act = (string) ($_POST['act'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);
        $msg = null;

        if (!Auth::csrfValid($_POST['csrf'] ?? null)) {
            $msg = ['err', '表单已过期，请重新操作'];
        } elseif ($act === 'clear_all') {
            Ack::clearAll();
            $msg = ['ok', '已清空全部人工确认'];
        } elseif ($act === 'unack' && $id > 0) {
            Ack::clear($id);
            $msg = ['ok', '已撤销确认'];
        } elseif ($act === 'ack' && $id > 0) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $msg = ['err', '这张单已经不在当前列表里了'];
            } elseif (!empty($row['skip'])) {
                $msg = ['err', '这张单本来就免核对，不需要确认'];
            } elseif ((string) ($_POST['fp'] ?? '') !== $row['fp']) {
                // 从页面渲染到点确认之间，人数或套餐变了 —— 不能拿旧状态盖章
                $msg = ['err', '这张单的人数或套餐份数刚刚变了，请重新核对后再确认'];
            } else {
                Ack::set($id, $row['fp']);
                $msg = ['ok', '已确认「' . ($row['table'] !== '' ? $row['table'] : '#' . $id) . '」'];
            }
        }

        if ($msg !== null) {
            Auth::boot();
            $_SESSION['flash'] = $msg;
        }
        header('Location: ' . $selfUrl());
        exit;
    }

    $data = Report::buildOpenTables($heads, $counts, $warnHours, Ack::all(), $skipRules,
                                    ['min_drink' => $minDrink]);
} catch (Throwable $e) {
    $error = '查询失败：' . $e->getMessage();
}

// 取出并清掉一次性提示
$flash = null;
if (PHP_SAPI !== 'cli') {
    Auth::boot();
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
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

<?php if ($data !== null && $data['sum']['acked'] > 0): ?>
  <form method="post" action="<?= h($selfUrl()) ?>" class="clearack">
    <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
    <input type="hidden" name="act" value="clear_all">
    <span>已人工确认 <b><?= num($data['sum']['acked']) ?></b> 台</span>
    <button class="btn-mini" type="submit">全部撤销</button>
  </form>
<?php endif; ?>

<?php if ($flash): ?>
  <p class="<?= $flash[0] === 'ok' ? 'okmsg' : 'err' ?>"><?= h($flash[1]) ?></p>
<?php endif; ?>

<?php if ($error): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>

<?php if (!$comboIds): ?>
  <p class="err"><code>combo_item_ids</code> 是空的，套餐份数会全部显示为 0。
    请先把按人头的套餐菜品 ID 填进去。</p>
<?php endif; ?>

<?php if ($data !== null):
  $S    = $data['sum'];
  $rows = Report::sortOpenTables($data['rows']);
  if ($onlyIssues) {
      // 套餐或酒水任一不合格就算「有问题」；已人工确认的、外带这类免核对的不算
      $rows = array_values(array_filter($rows,
          fn($r) => $r['bad'] && $r['state'] !== Report::OPEN_SKIP && !$r['acked']));
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
    <div class="card gap"><h3>酒水份数合计</h3>
      <div class="big"><?= qty($S['drink']) ?></div>
      <dl>
        <dt>金额</dt><dd><?= money($S['drink_amount']) ?></dd>
        <?php if ($minDrink > 0): ?>
          <dt>与人数差额</dt><dd><?= ($S['drink'] - $S['guests'] >= 0 ? '+' : '')
                . qty($S['drink'] - $S['guests']) ?></dd>
        <?php endif; ?>
      </dl></div>
    <div class="card <?= $S['problem'] > 0 ? 'bad' : 'good' ?>"><h3>需要核对的台</h3>
      <div class="big"><?= num($S['problem']) ?></div>
      <dl>
        <?php if ($S['combo_problem'] > 0): ?>
          <dt>套餐对不上</dt><dd><?= num($S['combo_problem']) ?></dd>
        <?php endif; ?>
        <?php if ($S['drink_problem'] > 0): ?>
          <dt>酒水不足</dt><dd><?= num($S['drink_problem']) ?></dd>
        <?php endif; ?>
        <?php if ($S['acked'] > 0): ?>
          <dt>已人工确认</dt><dd><?= num($S['acked']) ?></dd>
        <?php endif; ?>
        <?php if ($S['skip'] > 0): ?>
          <dt>外带·免核对</dt><dd><?= num($S['skip']) ?></dd>
        <?php endif; ?>
        <?php if ($S['stale'] > 0): ?>
          <dt>开台超 <?= (int) $warnHours ?> 小时</dt><dd><?= num($S['stale']) ?></dd>
        <?php endif; ?>
      </dl>
    </div>
  </section>

  <?php if (!$rows): ?>
    <p class="empty">
      <?= $onlyIssues ? '所有台的套餐份数与酒水都对得上。' :
          ($onlyOpen ? '当前没有未结算的开台记录。' : '实时表里没有订单记录。') ?>
    </p>
  <?php else: ?>

  <h2><?= $onlyIssues ? '有问题的台' : '开台明细' ?>（<?= num(count($rows)) ?>）</h2>
  <?php
  // 每行要显示的内容只算一次，桌面表格与手机列表共用，避免两处口径跑偏
  $fmt = static function (array $r): array {
      // 底色：套餐缺失最重（红），打多了/没填人数是提醒（黄），
      // 套餐没问题但酒水不足也按提醒标出来
      $cls = ['ok' => '', 'short' => 'row-bad', 'none' => 'row-bad',
              'over' => 'row-warn', 'noguest' => 'row-warn',
              'skip' => 'row-skip'][$r['state']] ?? '';
      if ($cls === '' && $r['bad']) {
          $cls = 'row-warn';
      }
      return [
          'cls'   => $cls,
          'table' => $r['table'] !== '' ? $r['table'] : '#' . $r['id'],
          'dur'   => $r['minutes'] === null ? '—'
                     : intdiv($r['minutes'], 60) . ':' . str_pad((string) ($r['minutes'] % 60), 2, '0', STR_PAD_LEFT),
          // 免核对的台不做人数/套餐比对，差额没有意义，一律显示 —
          'diff'  => ($r['guests'] > 0 && empty($r['skip']))
                     ? (($r['diff'] > 0 ? '+' : '') . qty($r['diff'])) : '—',
          // 酒水还差几份，只在不达标时显示
          'dshort' => $r['drink_short'] > 0.001 ? ('缺 ' . qty($r['drink_short'])) : '',
      ];
  };
  ?>

  <?php
  // 确认按钮：两步走 —— 先点「确认」把该行展开成「确定吗？」，再点「确定」才生效，
  // 避免手机上误触。整个流程走服务端，不依赖 JS。
  $ackBtn = static function (array $r) use ($askId, $selfUrl): string {
      $csrf = '<input type="hidden" name="csrf" value="' . h(Auth::csrfToken()) . '">'
            . '<input type="hidden" name="id" value="' . (int) $r['id'] . '">';

      if ($r['acked']) {
          return '<form method="post" class="ackform">' . $csrf
               . '<input type="hidden" name="act" value="unack">'
               . '<button class="btn-mini" type="submit">撤销</button></form>';
      }
      if (!$r['bad'] || $r['state'] === Report::OPEN_SKIP) {
          return '';                       // 全都合格的、免核对的，没什么可确认的
      }
      if ($askId === $r['id']) {
          return '<form method="post" class="ackform asking">' . $csrf
               . '<input type="hidden" name="act" value="ack">'
               . '<input type="hidden" name="fp" value="' . h($r['fp']) . '">'
               . '<span class="asktip">确定？</span>'
               . '<button class="btn-mini yes" type="submit">确定</button>'
               . '<a class="btn-mini no" href="' . h($selfUrl()) . '">取消</a></form>';
      }
      return '<a class="btn-mini" href="' . h($selfUrl(['ask' => $r['id']])) . '#t' . (int) $r['id'] . '">确认</a>';
  };
  ?>

  <?php // ---------- 手机：一台一行的紧凑列表 ---------- ?>
  <ul class="openlist">
    <?php foreach ($rows as $r): $f = $fmt($r); ?>
      <li id="t<?= (int) $r['id'] ?>" class="<?= $f['cls'] ?><?= $r['acked'] ? ' acked' : '' ?>">
        <div class="l1">
          <b><?= h($f['table']) ?></b>
          <?php if ($r['checks'] > 1): ?><span class="tag">分 <?= (int) $r['checks'] ?> 单</span><?php endif; ?>
          <?php if ($r['eat_type'] === 3): ?><span class="tag">外带</span><?php endif; ?>
          <?php if ($r['acked']): ?>
            <span class="state s-ack">已确认</span>
            <span class="tag"><?= h(Report::openStateLabel($r['state'])) ?></span>
            <?php if ($r['drink_state'] !== Report::DRINK_OK
                   && $r['drink_state'] !== Report::DRINK_NA): ?>
              <span class="tag"><?= h(Report::drinkStateLabel($r['drink_state'])) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="state s-<?= h($r['state']) ?>"><?= h(Report::openStateLabel($r['state'])) ?></span>
            <?php // 酒水达标就不出徽章了，免得每台都挂两个标签 ?>
            <?php if ($r['drink_state'] === Report::DRINK_SHORT
                   || $r['drink_state'] === Report::DRINK_NONE): ?>
              <span class="state s-d<?= h($r['drink_state']) ?>"><?=
                h(Report::drinkStateLabel($r['drink_state'])) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="l2">
          <?php // 没填人数时不显示「— 人」，徽章已经说明了，写出来反而费解 ?>
          <?php if ($r['guests'] > 0): ?>
            <span><b><?= num($r['guests']) ?></b> 人</span>
          <?php endif; ?>
          <?php if (empty($r['skip']) || $r['combo'] > 0): ?>
            <span>套餐 <b><?= qty($r['combo']) ?></b> 份</span>
          <?php endif; ?>
          <?php if ($r['guests'] > 0 && empty($r['skip']) && abs($r['diff']) > 0.001): ?>
            <span class="d <?= $r['state'] === Report::OPEN_OVER ? 'over' : '' ?>"><?= h($f['diff']) ?></span>
          <?php endif; ?>
          <span>酒水 <b><?= qty($r['drink']) ?></b><?php
            if ($f['dshort'] !== ''): ?> <span class="d"><?= h($f['dshort']) ?></span><?php
            endif; ?></span>
          <span class="t <?= $r['stale'] ? 'stale' : '' ?>"><?= h($f['dur']) ?></span>
        </div>
        <div class="l3">
          <span><?= money($r['amount']) ?> · <?= qty($r['dishes']) ?> 菜 / <?= num($r['lines']) ?> 笔
            <?php if ($r['employee'] !== ''): ?> · <?= h($r['employee']) ?><?php endif; ?>
            <?php if (!$onlyOpen): ?> · <?= $r['settled'] ? '已结算' : '未结算' ?><?php endif; ?>
            <?php if ($r['acked']): ?> · 确认于 <?= h(date('H:i', $r['acked_at'])) ?><?php endif; ?>
          </span>
          <?= $ackBtn($r) ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php // ---------- 桌面：完整表格 ---------- ?>
  <div class="tablewrap opentable">
  <table class="grid stick">
    <thead><tr>
      <th>桌号</th><th class="n">人数</th><th class="n">套餐份数</th><th class="n">差额</th>
      <th class="n">酒水</th><th class="n hide-sm">酒水金额</th>
      <th>核对结果</th><th class="n">已点菜品</th><th class="n">当前金额</th>
      <th>开台时间</th><th class="n">已开台</th><th>服务员</th>
      <?php if (!$onlyOpen): ?><th>是否已结算</th><?php endif; ?>
      <th>人工确认</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $f = $fmt($r); ?>
      <tr id="d<?= (int) $r['id'] ?>" class="<?= $f['cls'] ?><?= $r['acked'] ? ' acked' : '' ?>">
        <td><strong><?= h($f['table']) ?></strong>
            <?php if ($r['checks'] > 1): ?><span class="tag">分 <?= (int) $r['checks'] ?> 单</span><?php endif; ?>
            <?php if ($r['eat_type'] === 3): ?><span class="tag">外带</span><?php endif; ?></td>
        <td class="n strong"><?= $r['guests'] > 0 ? num($r['guests']) : '—' ?></td>
        <td class="n strong"><?= (!empty($r['skip']) && $r['combo'] <= 0) ? '—' : qty($r['combo']) ?></td>
        <td class="n"><?= h($f['diff']) ?></td>
        <td class="n strong"><?= qty($r['drink']) ?><?php
            if ($f['dshort'] !== ''): ?> <span class="d"><?= h($f['dshort']) ?></span><?php
            endif; ?></td>
        <td class="n hide-sm dim"><?= $r['drink'] > 0 ? money($r['drink_amount']) : '—' ?></td>
        <td><?php if ($r['acked']): ?>
              <span class="state s-ack">已确认</span>
              <span class="tag"><?= h(Report::openStateLabel($r['state'])) ?></span>
              <?php if ($r['drink_state'] !== Report::DRINK_OK
                     && $r['drink_state'] !== Report::DRINK_NA): ?>
                <span class="tag"><?= h(Report::drinkStateLabel($r['drink_state'])) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="state s-<?= h($r['state']) ?>"><?= h(Report::openStateLabel($r['state'])) ?></span>
              <?php if ($r['drink_state'] === Report::DRINK_SHORT
                     || $r['drink_state'] === Report::DRINK_NONE): ?>
                <span class="state s-d<?= h($r['drink_state']) ?>"><?=
                  h(Report::drinkStateLabel($r['drink_state'])) ?></span>
              <?php endif; ?>
            <?php endif; ?></td>
        <td class="n"><?= qty($r['dishes']) ?> <span class="dim">/ <?= num($r['lines']) ?> 笔</span></td>
        <td class="n"><?= money($r['amount']) ?></td>
        <td class="date"><?= h($r['start'] !== '' ? substr($r['start'], 5, 11) : '—') ?></td>
        <td class="n <?= $r['stale'] ? 'stale' : '' ?>"><?= h($f['dur']) ?></td>
        <td class="dim"><?= h($r['employee']) ?></td>
        <?php if (!$onlyOpen): ?><td class="dim"><?= $r['settled'] ? '已结算' : '未结算' ?></td><?php endif; ?>
        <td class="ackcell"><?= $ackBtn($r) ?>
          <?php if ($r['acked']): ?><span class="dim"><?= h(date('H:i', $r['acked_at'])) ?></span><?php endif; ?>
        </td>
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
    <span class="state s-dnone">未点酒水</span>
    <span class="state s-dshort">酒水不足</span>
    <?php if ($minDrink > 0): ?>
      规则是<strong>每人至少 <?= qty($minDrink) ?> 份，多了不算问题</strong> ——
      2 位客人点 2 份就算达标，点 3 份、5 份都行，只点 1 份才提示。
    <?php else: ?>
      （当前只统计酒水杯数，不做核对；要开启就在 config.php 里设
      <code>drink_min_per_guest</code>）
    <?php endif; ?>
    <span class="state s-skip">免核对</span> 外带（Llevar）这类台没有堂食人数、也不会点按人头的
    自助套餐，不参与核对，也不计入「需要核对的台」。
    <br>
    只点单品不吃自助的客人，本来就不会有套餐 —— 这类会显示成「未打套餐」，属正常，
    请结合金额与菜品数判断。「已开台」按 时:分 显示，超过 <?= (int) $warnHours ?> 小时会标红。
    <br>
    排序：<strong>套餐有问题的最前，其次是只有酒水不足的，然后是已人工确认的、
    全都合格的，免核对的排最后</strong>；
    每一档内部按桌号排（2 排在 10 前面，纯数字桌号排在文字桌号之前）。
  </p>
  <p class="note">
    <strong>酒水口径</strong>：按出品岗位判定，不是一个个列菜品 ——
    <?php if ($drinks['classes']): ?>
      当前命中
      <?php foreach ($drinks['classes'] as $pc => $nm): ?><span class="tag"><?= h($nm) ?></span><?php endforeach; ?>
      共 <strong><?= num(count($drinks['ids'])) ?></strong> 个菜品。
    <?php else: ?>
      <strong class="errtext">当前一个岗位都没命中，酒水会全部显示为 0。</strong>
      本店的岗位名是：<?= h(implode('、', array_slice(array_values($printClasses), 0, 20))) ?>；
      请把对应的岗位名填进 config.php 的 <code>drink_print_classes</code>。
    <?php endif; ?>
    规则 <?= h(implode('、', $drinks['patterns'])) ?: '（空）' ?>
    （大小写不敏感，<code>*</code> 是通配符）。
    <?php if ($drinks['extra']): ?>
      另外单独补入 <?= num(count($drinks['extra'])) ?> 个菜品。
    <?php endif; ?>
    <?php if ($drinks['excluded']): ?>
      剔除 <?= num(count($drinks['excluded'])) ?> 个菜品（免费茶水之类）。
    <?php endif; ?>
    换季加了新饮料不用改清单，只要它挂在这些岗位下就会自动算进来。
  </p>
  <p class="note">
    <span class="state s-skip">免核对</span> 当前的判定规则：
    <?php if ($skipRules['tables']): ?>
      桌号匹配 <?php foreach ($skipRules['tables'] as $i => $p): ?><?= $i ? '、' : '' ?><code><?= h($p) ?></code><?php endforeach; ?>
      （大小写不敏感，<code>*</code> 是通配符，在 <code>no_combo_tables</code> 里改）
    <?php else: ?>
      桌号规则为空（<code>no_combo_tables</code>），所有台都要核对
    <?php endif; ?>
    <?php if (!$skipCustom): ?>
      —— 这是 <code>lib/settings.php</code> 里的<strong>内置默认值</strong>，会跟着程序升级走；
      想改（比如再加个 <code>Barra*</code>）就把 <code>no_combo_tables</code> 写进 config.php
    <?php endif; ?>
    <?php if ($skipRules['eat_types']): ?>
      ；另外 <code>eat_type</code> 为
      <?= h(implode('、', array_map('strval', $skipRules['eat_types']))) ?> 的单也免核对
      （<code>no_combo_eat_types</code>）
    <?php endif; ?>。
    新增外带台、改了桌号命名后记得回来更新，否则会一直报「未打套餐」。
  </p>
  <p class="note">
    <span class="state s-ack">已确认</span>
    并桌之类的情况本来就会人数多、套餐对不上，核对过一次可以点「确认」标记掉，
    之后不再计入「需要核对的台」。点确认后会再问一次「确定？」，防止误触。
    <br>
    确认只记在<strong>当前登录会话</strong>里（不写数据库，本程序始终只读），
    <?= (int) Ack::hours() ?> 小时后自动失效，退出登录即清空，换一台设备也要重新确认。
    <strong>该台的人数、套餐份数、或酒水够不够，任一项变化，确认自动作废</strong>，
    会重新回到待核对状态 —— 所以补打了套餐、加了人，都不会被旧的确认盖住。
    酒水只看「够没够」不看杯数，所以不足时又加一杯（仍然不足）不会让确认作废。
  </p>

  <?php endif; ?>

  <details class="station" style="margin-top:18px">
    <summary><span class="pcname">当前算作「按人头套餐」的菜品</span>
      <span class="pcmeta"><?= num(count($comboIds)) ?> 个 · combo_item_ids<?=
        array_key_exists('combo_item_ids', Db::overrides()) ? '（config.php 里配的）'
                                                           : '（lib/settings.php 默认值）' ?></span></summary>
    <div style="padding:14px 16px">
      <?php if (!$comboIds): ?>
        <p class="empty">清单为空。</p>
      <?php else: ?>
      <div class="tablewrap"><table class="grid small stick">
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
