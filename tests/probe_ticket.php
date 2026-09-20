<?php
/**
 * 诊断：数据库里到底有没有「出了多少张单」这个数？
 *
 *     php tests/probe_ticket.php          # 默认看最近 1 天
 *     php tests/probe_ticket.php 7        # 看最近 7 天
 *
 * 也可以放到浏览器里访问（需要先登录）：
 *
 *     .../tests/probe_ticket.php          # 最近 1 天
 *     .../tests/probe_ticket.php?days=7   # 最近 7 天
 *
 * 全程只执行 SELECT，不会改动任何数据（见「注意事项.md」铁律一）。
 *
 * ============================================================
 *  要回答的问题
 * ============================================================
 *  岗位页现在给的是「多少桌点了这个岗位的东西」（COUNT DISTINCT order_head_id）
 *  和「多少份菜」。缺的是【这个岗位的打印机出了多少张单】——
 *  一桌可能分好几次下单（先点一轮，过会儿加菜），每次下单该岗位就出一张新单。
 *
 *  数据库里【没有】一张记录「打印过哪些单」的表：
 *    - print_task 是打印队列（一个 data 大文本 + 时间戳，不带订单号／岗位），
 *      打完就清，不是历史。本脚本第 1 节会实测它到底留不留东西。
 *    - history_order_detail 里有 print_class 字段，但实测恒为 0/NULL（README 三之岗位）。
 *
 *  但「下单动作」本身是有痕迹的，而且有【两条互不相干】的线索：
 *    甲、menu_item_id = -3 的标记行，名字形如 `**999 Enviado 19:16**`
 *        （Enviado = 西语「已送出」）—— 每送一次厨房就写一行。
 *    乙、菜品行自己的 order_time。order_detail 上有触发器
 *        `BEFORE INSERT ... set NEW.order_time=now()`，也就是【写库那一刻】的时间；
 *        同一次下单一起写进去的几行，order_time 应当相同（精确到秒）。
 *
 *  本脚本就是去实测这两条线索对不对得上。对得上，「张数」就能算出来；
 *  对不上，就得老老实实说这个数出不来，而不是编一个看着像模像样的数字。
 *
 * ============================================================
 *  为什么要先测，不能直接写进页面
 * ============================================================
 *  如果 POS 是一行一行分别写库的，一次下单就可能跨秒，
 *  于是一张单被拆成两批 —— 算出来的「张数」会偏多，
 *  而这种偏差【在页面上完全看不出来】：数字照样是个合理的整数。
 *  所以先用真实数据验一遍，再决定要不要把这一列加到岗位页。
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/biz.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && !Auth::isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font:13px/1.6 monospace;padding:16px">';
}

// 默认只看 1 天。这是在【生产库】上跑的诊断，第 3、5 节要按
// (单, 下单时刻) 分组，范围拉大会真的压到 MySQL 5.5 ——
// 先用一天看结论，需要更大样本再手动加大。
$days = 1;
$arg  = $cli ? ($argv[1] ?? null) : ($_GET['days'] ?? null);
if (is_string($arg) && ctype_digit($arg)) {
    $days = max(1, min(92, (int) $arg));
}

$cfg  = Db::config();
$cut  = (int) $cfg['day_cut_hour'];
$to   = date('Y-m-d H:i:s', time() - $cut * 3600);
$from = date('Y-m-d H:i:s', strtotime("-{$days} day", strtotime($to)));

/** 明细表的公共过滤：和岗位统计用的是同一套条件，否则两个数没法比 */
const DISH_ONLY = 'menu_item_id > 0 AND quantity > 0
                   AND (is_return_item IS NULL OR is_return_item = 0)
                   AND COALESCE(condiment_belong_item, 0) = 0';

$verdict = [];
function say(string $s = ''): void { echo $s . "\n"; }
function kv(string $k, $v): void { printf("    %-28s %s\n", $k, (string) $v); }

say();
say("范围：{$from}  ~  {$to}   （最近 {$days} 天，营业日切分点 {$cut} 点）");

// =====================================================================
say("\n===== 1. print_task：是打印历史，还是打完就清的队列？ =====");
// =====================================================================
// 如果它留着历史，那才是最直接的答案 —— 直接数它就行，用不着推。
try {
    $r = Db::select('SELECT COUNT(*) AS n, MIN(`time`) AS oldest, MAX(`time`) AS newest
                     FROM print_task')[0] ?? null;
    $n = (int) ($r['n'] ?? 0);
    kv('行数', $n);
    kv('最早 / 最新', ($r['oldest'] ?? '—') . '  /  ' . ($r['newest'] ?? '—'));
    if ($n === 0) {
        say('    → 空表。打完就清，指望不上。');
        $verdict['print_task'] = false;
    } elseif ($r['oldest'] !== null && strtotime((string) $r['oldest']) < strtotime($from)) {
        say('    → 竟然留着历史！那就不用推算了，直接数这张表最准。');
        say('      不过它只有一个 data 大文本，得先看看里面认不认得出岗位和订单号。');
        $verdict['print_task'] = true;
    } else {
        say('    → 只有最近的几行，是队列不是历史。指望不上。');
        $verdict['print_task'] = false;
    }
} catch (Throwable $e) {
    say('    读不到 print_task：' . $e->getMessage());
    $verdict['print_task'] = false;
}

// =====================================================================
say("\n===== 2. 线索甲：`Enviado` 送厨房标记行长什么样 =====");
// =====================================================================
$marks = Db::select(
    'SELECT order_head_id, menu_item_name, order_time, quantity
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND menu_item_id = -3
     ORDER BY order_detail_id DESC LIMIT 8',
    [':from' => $from, ':to' => $to]);
if (!$marks) {
    say('    这段时间一行都没有 —— 线索甲不成立（也可能是这几天没营业，换个范围再试）。');
    $verdict['marks'] = false;
} else {
    foreach ($marks as $m) {
        printf("    单 %-8s %-30s %s\n",
               (string) $m['order_head_id'],
               (string) $m['menu_item_name'],
               (string) $m['order_time']);
    }
    say('    → 每送一次厨房写一行。注意名字里带的是【桌号和时分】，不带岗位 ——');
    say('      也就是说它只告诉你「送了一次」，不告诉你「送给哪几个岗位」。');
    $verdict['marks'] = true;
}

// =====================================================================
say("\n===== 3. 线索乙：同一次下单的菜，order_time 是不是同一秒？ =====");
// =====================================================================
// 这是【决定性】的一节。如果绝大多数「批次」只有 1 行菜，
// 说明 order_time 是逐行各写各的，根本不是批次标识，后面就都别算了。
$dist = Db::select(
    'SELECT lines_in_batch, COUNT(*) AS batches FROM (
        SELECT order_head_id, order_time, COUNT(*) AS lines_in_batch
        FROM history_order_detail
        WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
        GROUP BY order_head_id, order_time
     ) b
     GROUP BY lines_in_batch ORDER BY lines_in_batch LIMIT 20',
    [':from' => $from, ':to' => $to]);

$totalBatches = 0;
$totalLines   = 0;
$oneLine      = 0;
foreach ($dist as $d) {
    $b = (int) $d['batches'];
    $l = (int) $d['lines_in_batch'];
    $totalBatches += $b;
    $totalLines   += $b * $l;
    if ($l === 1) { $oneLine = $b; }
}
if ($totalBatches === 0) {
    say('    这段时间没有菜品行。');
    $verdict['batch'] = false;
} else {
    say('    每批多少行菜 → 有多少批：');
    foreach ($dist as $d) {
        $b = (int) $d['batches'];
        printf("      %2d 行  %6d 批  %5.1f%%  %s\n", (int) $d['lines_in_batch'], $b,
               $b / $totalBatches * 100, str_repeat('#', (int) round($b / $totalBatches * 40)));
    }
    $pctOne = $oneLine / $totalBatches * 100;
    kv('批次总数', $totalBatches);
    kv('菜品行总数', $totalLines);
    kv('平均每批', sprintf('%.2f 行', $totalLines / $totalBatches));
    kv('只有 1 行的批次占比', sprintf('%.1f%%', $pctOne));
    if ($pctOne > 85) {
        say('    → ⚠️ 几乎每行菜自成一批，说明 order_time 是【逐行写入时间】，');
        say('      不是「一次下单」的标识。用它数出来的「张数」≈ 菜品行数，没有意义。');
        $verdict['batch'] = false;
    } else {
        say('    → 一批里通常有好几行菜，order_time 确实是【一次下单】的标识。');
        $verdict['batch'] = true;
    }
}

// =====================================================================
say("\n===== 4. 两条线索对得上吗？（这是交叉验证，不是重复）=====");
// =====================================================================
// 甲（Enviado 标记数）和乙（不同 order_time 数）来路完全不同。
// 两个数接近 = 它们指的是同一件事；差很多 = 至少有一个理解错了。
$a = Db::select(
    'SELECT COUNT(*) AS marks, COUNT(DISTINCT order_head_id) AS orders
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND menu_item_id = -3',
    [':from' => $from, ':to' => $to])[0] ?? ['marks' => 0, 'orders' => 0];
$b = Db::select(
    'SELECT COUNT(DISTINCT order_head_id, order_time) AS batches,
            COUNT(DISTINCT order_head_id)             AS orders
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY,
    [':from' => $from, ':to' => $to])[0] ?? ['batches' => 0, 'orders' => 0];

kv('甲：Enviado 标记行数', (int) $a['marks']);
kv('乙：不同的(单, 下单时刻)数', (int) $b['batches']);
kv('涉及订单数（甲 / 乙）', (int) $a['orders'] . ' / ' . (int) $b['orders']);
$mk = (int) $a['marks'];
$bt = (int) $b['batches'];
if ($mk > 0 && $bt > 0) {
    $diff = abs($mk - $bt) / max($mk, $bt) * 100;
    kv('相差', sprintf('%.1f%%', $diff));
    if ($diff <= 15) {
        say('    → 两条毫不相干的线索指向同一个数，可信。');
        $verdict['agree'] = true;
    } else {
        say('    → 差得不少。可能的原因：下单后又加菜但没重新送厨房、');
        say('      整桌取消、或者一次下单跨了秒。这个数要打折扣看。');
        $verdict['agree'] = false;
    }
} else {
    $verdict['agree'] = false;
}

// =====================================================================
say("\n===== 5. 一次下单，要出几张单？（每批涉及几个岗位）=====");
// =====================================================================
// 这一节回答的是：为什么「张数」不等于「下单次数」。
// 一轮点了 2 个寿司 + 1 个热菜，寿司台出一张、热菜出一张 —— 一次下单两张单。
$items    = Biz::menuItems();
$pcOfItem = [];
foreach ($items as $id => $it) {
    if (!empty($it['is_condiment'])) {
        continue;                       // 做法项不是菜，不占打印分类
    }
    $pcOfItem[$id] = $it['print_class'];
}
$pcExpr = Biz::pcCaseExpr($pcOfItem);
$pcName = Biz::printClasses();

$perBatch = Db::select(
    'SELECT pcs, COUNT(*) AS batches FROM (
        SELECT order_head_id, order_time, COUNT(DISTINCT ' . $pcExpr . ') AS pcs
        FROM history_order_detail
        WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
        GROUP BY order_head_id, order_time
     ) b GROUP BY pcs ORDER BY pcs LIMIT 15',
    [':from' => $from, ':to' => $to]);

$tickets = 0;
$bsum    = 0;
foreach ($perBatch as $r) {
    $tickets += (int) $r['pcs'] * (int) $r['batches'];
    $bsum    += (int) $r['batches'];
    printf("      涉及 %d 个岗位：%6d 批\n", (int) $r['pcs'], (int) $r['batches']);
}
if ($bsum > 0) {
    kv('下单次数', $bsum);
    kv('推算出的总单数', $tickets);
    kv('平均一次下单出几张', sprintf('%.2f 张', $tickets / $bsum));
}

// =====================================================================
say("\n===== 6. 各岗位：现在页面上的「桌数」 vs 推算的「单数」 =====");
// =====================================================================
// 把两个数并排摆出来，差别一眼可见。
// 「桌数」一桌只算一次；「单数」一桌分三次下单就算三次。
$rows = Db::select(
    'SELECT ' . $pcExpr . ' AS pc,
            COUNT(DISTINCT order_head_id)             AS tables_cnt,
            COUNT(DISTINCT order_head_id, order_time) AS tickets,
            SUM(quantity)                             AS qty
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY pc ORDER BY tickets DESC',
    [':from' => $from, ':to' => $to]);

printf("    %-16s %8s %8s %8s %8s\n", '岗位', '桌数', '单数', '每桌单数', '份数');
foreach ($rows as $r) {
    $pc = (int) $r['pc'];
    $nm = $pc === Biz::PC_NONE ? '（未配岗位）'
        : ($pc === Biz::PC_UNKNOWN ? '（已删除的菜）' : ($pcName[$pc] ?? "#{$pc}"));
    $t  = (int) $r['tables_cnt'];
    printf("    %-16s %8d %8d %8s %8s\n", $nm, $t, (int) $r['tickets'],
           $t > 0 ? sprintf('%.2f', (int) $r['tickets'] / $t) : '—',
           rtrim(rtrim(number_format((float) $r['qty'], 1), '0'), '.'));
}

// =====================================================================
say("\n===== 7. 几个会让「单数」算不准的字段 =====");
// =====================================================================
// not_print：配置成不打印的行，不该算进单数。
$np = Db::select(
    'SELECT COALESCE(not_print, -99) AS v, COUNT(*) AS n
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY v ORDER BY n DESC LIMIT 6',
    [':from' => $from, ':to' => $to]);
say('    not_print（1 = 这行不打印）：');
foreach ($np as $r) {
    kv($r['v'] == -99 ? 'NULL' : (string) $r['v'], (int) $r['n'] . ' 行');
}
// rush：催菜会重新打一张，但数据库里看不出「重打过几次」
$rush = Db::select(
    'SELECT COALESCE(rush, -99) AS v, COUNT(*) AS n
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY v ORDER BY n DESC LIMIT 6',
    [':from' => $from, ':to' => $to]);
say('    rush（催菜）：');
foreach ($rush as $r) {
    kv($r['v'] == -99 ? 'NULL' : (string) $r['v'], (int) $r['n'] . ' 行');
}
// 明细表自带的 print_class 到底是不是真的废的（README 说实测恒为 0/NULL）
$pcRaw = Db::select(
    'SELECT COALESCE(print_class, -99) AS v, COUNT(*) AS n
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY v ORDER BY n DESC LIMIT 6',
    [':from' => $from, ':to' => $to]);
say('    明细表自带的 print_class（README 说实测恒为 0/NULL）：');
foreach ($pcRaw as $r) {
    kv($r['v'] == -99 ? 'NULL' : (string) $r['v'], (int) $r['n'] . ' 行');
}

// =====================================================================
say("\n" . str_repeat('=', 60));
// =====================================================================
$okBatch = !empty($verdict['batch']);
$okAgree = !empty($verdict['agree']);
if (!empty($verdict['print_task'])) {
    say('结论：print_task 里居然有历史 —— 先去看它，那比推算准。');
} elseif ($okBatch && $okAgree) {
    say('结论：可以算。');
    say('  「单数」= COUNT(DISTINCT order_head_id, order_time)，按岗位分组。');
    say('  两条独立线索（Enviado 标记 / 下单时刻）对得上，第 6 节那一列可以用。');
    say('  仍要记住：这是【推算】，不是数据库记下来的事实 ——');
    say('  重打的单、手工补打的单，数据库里根本没有痕迹，永远数不到。');
} elseif ($okBatch) {
    say('结论：能算，但两条线索对不上（见第 4 节），数字要打折扣看。');
    say('  建议先弄清差异来自哪里，再决定要不要把这一列放上页面。');
} else {
    say('结论：算不出来。order_time 不是「一次下单」的标识（见第 3 节），');
    say('  数据库里也没有别的地方记着出了几张单。');
    say('  硬算的话得到的是「菜品行数」，不是「单数」—— 那是个看着合理的错数字。');
}
say();

if (!$cli) {
    echo '</pre>';
}
