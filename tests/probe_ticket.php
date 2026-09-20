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
 *  本脚本就是去实测这两条线索对不对得上。
 *
 *  实测下来两者差了 4 倍（甲 622 / 乙 2561），所以【对不上本身不是结论】，
 *  得先分清是哪一边不对。两种可能，4b 和 4c 分别去证伪：
 *    ① 乙虚高 —— 一次下单被拆成好几秒写库，一张票被数成两三张。
 *       测法（4b）：同一张单里相邻两批隔多久。隔几秒是被拆开，隔几分钟是真的又点了一轮。
 *    ② 甲残缺 —— 标记只在按某个特定键时才写，本来就不是每次下单都有。
 *       测法（4c）：每个标记能不能在同一张单里找到时间贴得很近的一批菜。
 *       能对上就说明标记是批次的子集，那是甲不全，不是乙虚高。
 *
 *  两边都测过再下结论，而不是编一个看着像模像样的数字。
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
say("\n===== 1b. 打印机是怎么配的？（决定「一张票」到底是什么）=====");
// =====================================================================
// 这一节是【用户纠正出来的】：一次 Enviado 里有 2*101 和 3*95，
// 到打印机那儿出的是【两张票】—— 一张 101（2 份）、一张 95（3 份）。
// 也就是说这台打印机是「一道菜一张单」，不是「一次下单一张单」。
// 而且份数不拆票（2 份还在同一张上），所以粒度是【明细行】，不是【份】。
//
// 这件事不用猜：print_devices 上就有开关。三张字典表各查一次、
// 在 PHP 里拼（都是几行到几十行的小表，不和大表 JOIN —— 铁律五）。
$pcDict = Db::select('SELECT print_class_id, print_class_name FROM print_class');
$rel    = Db::select('SELECT print_class_id, print_device_id FROM print_class_relation');
// bit(1) 取回来是二进制串（"\0" / "\1"），+0 强制成数字才好判断
$dev    = Db::select('SELECT print_device_id, print_device_name,
                             split_print + 0 AS split_print,
                             print_label + 0 AS print_label
                      FROM print_devices');
$devById = [];
foreach ($dev as $d) {
    $devById[(int) $d['print_device_id']] = $d;
}
$devOfPc = [];
foreach ($rel as $r) {
    $devOfPc[(int) $r['print_class_id']][] = (int) $r['print_device_id'];
}

$splitYes = 0;
$splitNo  = 0;
$labelYes = 0;
printf("    %-14s %-18s %-10s %-10s %s\n", '岗位', '打印机', 'split_print', 'print_label', '一张票是什么');
foreach ($pcDict as $pc) {
    $id  = (int) $pc['print_class_id'];
    $ids = $devOfPc[$id] ?? [];
    if (!$ids) {
        printf("    %-14s %-18s %s\n", (string) $pc['print_class_name'], '(没配打印机)', '—');
        continue;
    }
    foreach ($ids as $did) {
        $d  = $devById[$did] ?? null;
        $sp = $d === null ? null : (int) $d['split_print'];
        $lb = $d === null ? null : (int) $d['print_label'];
        if ($sp === 1) { $splitYes++; } elseif ($sp === 0) { $splitNo++; }
        if ($lb === 1) { $labelYes++; }
        printf("    %-14s %-18s %-11s %-11s %s\n",
               (string) $pc['print_class_name'],
               $d === null ? "#{$did}（查不到）" : (string) $d['print_device_name'],
               $sp === null ? '?' : (string) $sp,
               $lb === null ? '?' : (string) $lb,
               $sp === 1 ? '一道菜一张（= 明细行数）'
                         : ($sp === 0 ? '一次下单一张（= 下单次数）' : '?'));
    }
}
kv('split_print = 1 的（一菜一单）', $splitYes . ' 个');
kv('split_print = 0 的（一单一票）', $splitNo . ' 个');
if ($labelYes > 0) {
    kv('print_label = 1 的', $labelYes . ' 个  ← 标签模式，粒度可能还不一样，要单独看');
}
if ($splitYes > 0 && $splitNo === 0) {
    say('    → 全部是【一道菜一张】。那么「票数」应当等于【明细行数】，');
    say('      而不是「下单次数」。岗位页现在那一列算小了。');
    $verdict['split_print'] = 'line';
} elseif ($splitNo > 0 && $splitYes === 0) {
    say('    → 全部是【一次下单一张】。「票数」= 该岗位参与的下单次数，');
    say('      也就是岗位页现在那一列。');
    $verdict['split_print'] = 'batch';
} elseif ($splitYes > 0) {
    say('    → ⚠️ 两种配置【混着】。那就不能一个公式套到底 ——');
    say('      得按岗位分别算：split_print=1 的用行数，=0 的用下单次数。');
    $verdict['split_print'] = 'mixed';
} else {
    say('    → 读不出配置（表是空的或者字段含义对不上），');
    say('      那就只能拿实际出纸情况人工核一核。');
    $verdict['split_print'] = null;
}
say('    参考：第 6 节会把「下单次数」和「明细行数」并排列出来，');
say('    照着上面的配置挑哪一列才是真正的票数。');

// =====================================================================
// 先把两份原始清单一次取回来，后面几节全在 PHP 里算。
//
// 为什么不在 SQL 里分别聚合：上一版就是那么做的，结果第 3 节的
// 「批次总数」是从一个 LIMIT 20 的分布里累加出来的 —— 21 行以上的批次
// 被悄悄截掉，总数少算了一截（实测 2545 vs 真实 2561）。
// 截断型的错误最难发现：数字还是个合理的整数，只是偏小。
// 一次取回全量、在 PHP 里算，就没有这个缝。
// =====================================================================
$batches = Db::select(
    'SELECT order_head_id, order_time, COUNT(*) AS lines_cnt
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY order_head_id, order_time
     ORDER BY order_head_id, order_time',
    [':from' => $from, ':to' => $to]);

$marks = Db::select(
    "SELECT order_head_id, menu_item_name, order_time,
            COALESCE(pos_name, '(空)') AS pos_name
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND menu_item_id = -3
     ORDER BY order_head_id, order_time",
    [':from' => $from, ':to' => $to]);

// =====================================================================
say("\n===== 2. 线索甲：`Enviado` 送厨房标记行长什么样 =====");
// =====================================================================
if (!$marks) {
    say('    这段时间一行都没有 —— 线索甲不成立（也可能是这几天没营业，换个范围再试）。');
    $verdict['marks'] = false;
} else {
    foreach (array_slice($marks, -8) as $m) {
        printf("    单 %-8s %-30s %-12s %s\n",
               (string) $m['order_head_id'], (string) $m['menu_item_name'],
               (string) $m['pos_name'], (string) $m['order_time']);
    }
    say('    → 每按一次那个键写一行。名字里那个数字【不是桌号】——');
    say('      同一张单里既出现过 999 又出现过 555，桌号不会中途变。');
    say('      那是 POS 上的按键／宏编号，第 4c 节数一下各是多少。');
    $verdict['marks'] = true;
}

// =====================================================================
say("\n===== 3. 线索乙：同一次下单的菜，order_time 是不是同一秒？ =====");
// =====================================================================
// 如果绝大多数「批次」只有 1 行菜，说明 order_time 是逐行各写各的，
// 根本不是批次标识，后面就都别算了。
$dist = [];
$totalLines = 0;
foreach ($batches as $b) {
    $l = (int) $b['lines_cnt'];
    $dist[$l >= 21 ? 21 : $l] = ($dist[$l >= 21 ? 21 : $l] ?? 0) + 1;
    $totalLines += $l;
}
ksort($dist);
$totalBatches = count($batches);
if ($totalBatches === 0) {
    say('    这段时间没有菜品行。');
    $verdict['batch'] = false;
} else {
    say('    每批多少行菜 → 有多少批：');
    foreach ($dist as $l => $b) {
        printf("      %s  %6d 批  %5.1f%%  %s\n",
               $l >= 21 ? '21+ 行' : sprintf('%2d 行 ', $l), $b,
               $b / $totalBatches * 100, str_repeat('#', (int) round($b / $totalBatches * 40)));
    }
    $pctOne = ($dist[1] ?? 0) / $totalBatches * 100;
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
say("\n===== 4. 两条线索对得上吗？ =====");
// =====================================================================
$orderOfMark  = count(array_unique(array_column($marks, 'order_head_id')));
$orderOfBatch = count(array_unique(array_column($batches, 'order_head_id')));
kv('甲：Enviado 标记行数', count($marks));
kv('乙：不同的(单, 下单时刻)数', $totalBatches);
kv('涉及订单数（甲 / 乙）', $orderOfMark . ' / ' . $orderOfBatch);
if ($orderOfBatch > 0) {
    kv('每张单平均下单几次（乙）', sprintf('%.2f 次', $totalBatches / $orderOfBatch));
}
if ($orderOfMark > 0) {
    kv('每张单平均几个标记（甲）', sprintf('%.2f 个', count($marks) / $orderOfMark));
}
kv('有菜、却一个标记都没有的单', max(0, $orderOfBatch - $orderOfMark) . ' 张');
$mk = count($marks);
$diff = ($mk > 0 && $totalBatches > 0)
      ? abs($mk - $totalBatches) / max($mk, $totalBatches) * 100 : 100.0;
kv('相差', sprintf('%.1f%%', $diff));
$verdict['agree'] = $diff <= 15;
if ($verdict['agree']) {
    say('    → 两条毫不相干的线索指向同一个数，可信。');
} else {
    say('    → 差得不少。两种可能，下面 4b / 4c 分别去证伪：');
    say('      ① 乙【虚高】：一次下单被拆成好几秒写库，一张票被数成两三张 → 看 4b');
    say('      ② 甲【残缺】：标记只在按某个特定键时才写，本来就不是每次下单都有 → 看 4c');
}

// =====================================================================
say("\n===== 4b. 同一张单里，相邻两批隔多久？（决定乙是不是虚高）=====");
// =====================================================================
// 这是分辨上面①②的关键。
//   隔几秒  → 同一次下单被拆开了，乙虚高，得把它们合回去
//   隔几分钟 → 就是真的又点了一轮，乙没问题
$buckets = [5 => '≤5 秒', 15 => '6–15 秒', 60 => '16–60 秒',
            300 => '1–5 分钟', 900 => '5–15 分钟', PHP_INT_MAX => '>15 分钟'];
$gapHist = array_fill_keys(array_keys($buckets), 0);
$gaps    = [];
$prevOid = null;
$prevTs  = null;
foreach ($batches as $b) {
    $oid = (int) $b['order_head_id'];
    $ts  = strtotime((string) $b['order_time']);
    if ($oid === $prevOid && $ts !== false && $prevTs !== null) {
        $g = $ts - $prevTs;
        $gaps[] = $g;
        foreach ($buckets as $hi => $_) {
            if ($g <= $hi) { $gapHist[$hi]++; break; }
        }
    }
    $prevOid = $oid;
    $prevTs  = $ts;
}
$nGap = count($gaps);
if ($nGap === 0) {
    say('    没有「同一张单里有两批以上」的情况，判断不了。');
    $verdict['split'] = null;
} else {
    foreach ($buckets as $hi => $label) {
        printf("      %-10s %6d 次  %5.1f%%  %s\n", $label, $gapHist[$hi],
               $gapHist[$hi] / $nGap * 100,
               str_repeat('#', (int) round($gapHist[$hi] / $nGap * 40)));
    }
    sort($gaps);
    kv('间隔样本数', $nGap);
    kv('中位间隔', gmdate('i:s', (int) $gaps[intdiv($nGap, 2)]) . ' （分:秒）');
    $short = ($gapHist[5] + $gapHist[15]) / $nGap * 100;
    kv('15 秒以内的占比', sprintf('%.1f%%', $short));

    // 把间隔在 N 秒以内的相邻批次合成一批，看数字掉多少 —— 掉得多说明确实在拆
    say('    如果把间隔 N 秒以内的相邻批次合并（当成同一次下单）：');
    foreach ([5, 15, 30, 60, 120] as $tol) {
        $merged = 0;
        $pOid = null;
        $pTs  = null;
        foreach ($batches as $b) {
            $oid = (int) $b['order_head_id'];
            $ts  = strtotime((string) $b['order_time']);
            if (!($oid === $pOid && $pTs !== null && $ts - $pTs <= $tol)) {
                $merged++;
            }
            $pOid = $oid;
            $pTs  = $ts;
        }
        printf("      %3d 秒内合并 → %6d 批（比原来少 %.1f%%）\n",
               $tol, $merged, ($totalBatches - $merged) / $totalBatches * 100);
    }
    if ($short > 20) {
        say('    → ⚠️ 相当一部分相邻批次只差十几秒，像是【同一次下单被拆开】。');
        say('      直接用乙会偏多，应当按上面某个阈值合并之后再算。');
        $verdict['split'] = true;
    } else {
        say('    → 相邻两批基本隔着几分钟，是真的又点了一轮，不是被拆开的。');
        say('      乙没有虚高，可以直接用。');
        $verdict['split'] = false;
    }
}

// =====================================================================
say("\n===== 4c. Enviado 标记到底从哪来？（决定甲是不是残缺）=====");
// =====================================================================
if (!$marks) {
    say('    没有标记行，跳过。');
    $verdict['subset'] = null;
} else {
    // ① 按键编号：名字形如 **555 Enviado 19:16**，那个数字是 POS 的按键／宏编号
    $byKey = [];
    foreach ($marks as $m) {
        $key = preg_match('/^\*\*\s*(\d+)\s/u', (string) $m['menu_item_name'], $mm)
             ? $mm[1] : '(认不出)';
        $byKey[$key] = ($byKey[$key] ?? 0) + 1;
    }
    arsort($byKey);
    say('    按名字里的编号分：');
    foreach (array_slice($byKey, 0, 8, true) as $k => $n) {
        kv((string) $k, $n . ' 行');
    }

    // ② 哪台机器写的：如果标记只来自某一台，那它天生就只覆盖一部分下单
    $byPos = [];
    foreach ($marks as $m) {
        $byPos[(string) $m['pos_name']] = ($byPos[(string) $m['pos_name']] ?? 0) + 1;
    }
    arsort($byPos);
    say('    标记行来自哪台 POS：');
    foreach (array_slice($byPos, 0, 8, true) as $k => $n) {
        kv((string) $k, $n . ' 行');
    }
    $dishPos = Db::select(
        "SELECT COALESCE(pos_name, '(空)') AS pos_name, COUNT(*) AS n
         FROM history_order_detail
         WHERE order_time >= :from AND order_time < :to AND " . DISH_ONLY . "
         GROUP BY pos_name ORDER BY n DESC LIMIT 8",
        [':from' => $from, ':to' => $to]);
    say('    作为对照，菜品行来自哪台 POS：');
    foreach ($dishPos as $r) {
        kv((string) $r['pos_name'], (int) $r['n'] . ' 行');
    }

    // ③ 每个标记，能不能在同一张单里找到时间贴得很近的一批菜？
    //    能对上 = 标记是批次的【子集】，那就是甲残缺而不是乙虚高。
    $byOrder = [];
    foreach ($batches as $b) {
        $byOrder[(int) $b['order_head_id']][] = strtotime((string) $b['order_time']);
    }
    $near = ['0' => 0, '5' => 0, '60' => 0, '300' => 0, 'far' => 0, 'none' => 0];
    foreach ($marks as $m) {
        $oid = (int) $m['order_head_id'];
        $ts  = strtotime((string) $m['order_time']);
        if (!isset($byOrder[$oid]) || $ts === false) {
            $near['none']++;
            continue;
        }
        $best = null;
        foreach ($byOrder[$oid] as $bt) {
            $d = abs($bt - $ts);
            if ($best === null || $d < $best) { $best = $d; }
        }
        if ($best === null)     { $near['none']++; }
        elseif ($best === 0)    { $near['0']++; }
        elseif ($best <= 5)     { $near['5']++; }
        elseif ($best <= 60)    { $near['60']++; }
        elseif ($best <= 300)   { $near['300']++; }
        else                    { $near['far']++; }
    }
    say('    每个标记离同一张单里最近的一批菜有多远：');
    $labels = ['0' => '同一秒', '5' => '5 秒内', '60' => '1 分钟内',
               '300' => '5 分钟内', 'far' => '更远', 'none' => '这张单压根没有菜'];
    foreach ($labels as $k => $lb) {
        kv($lb, $near[$k] . ' 个  ' . sprintf('%.1f%%', $near[$k] / count($marks) * 100));
    }
    $hit = ($near['0'] + $near['5'] + $near['60']) / count($marks) * 100;
    kv('1 分钟内能对上的比例', sprintf('%.1f%%', $hit));
    if ($hit >= 90) {
        say('    → 标记几乎都落在某一批菜上，说明它是批次的【子集】：');
        say('      不是每次下单都写标记，只有按那个键时才写。');
        say('      所以是【甲残缺】，不是乙虚高 —— 该用乙。');
        $verdict['subset'] = true;
    } else {
        say('    → 有不少标记对不上任何一批菜，两者说的可能不是一回事，别急着用。');
        $verdict['subset'] = false;
    }
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
            COUNT(DISTINCT order_head_id, order_time) AS batches,
            COUNT(*)                                  AS lines_cnt,
            SUM(quantity)                             AS qty
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY . '
     GROUP BY pc ORDER BY lines_cnt DESC',
    [':from' => $from, ':to' => $to]);

say('    「下单次数」= 该岗位参与了几次下单；「明细行数」= 几行菜。');
say('    哪一列才是票数，看 1b 节的 split_print：=1 用行数，=0 用下单次数。');
printf("    %-16s %8s %10s %10s %8s\n", '岗位', '桌数', '下单次数', '明细行数', '份数');
foreach ($rows as $r) {
    $pc = (int) $r['pc'];
    $nm = $pc === Biz::PC_NONE ? '（未配岗位）'
        : ($pc === Biz::PC_UNKNOWN ? '（已删除的菜）' : ($pcName[$pc] ?? "#{$pc}"));
    printf("    %-16s %8d %10d %10d %8s\n", $nm, (int) $r['tables_cnt'],
           (int) $r['batches'], (int) $r['lines_cnt'],
           rtrim(rtrim(number_format((float) $r['qty'], 1), '0'), '.'));
}

// =====================================================================
say("\n===== 6b. 金额对得上吗？（明细那边 vs 账单头那边）=====");
// =====================================================================
// 岗位页的「金额」= SUM(actual_price * quantity)。这里有个不会报错的陷阱：
// 如果 actual_price 存的其实是【行金额】而不是【单价】，这一乘就乘重了。
// 放大的倍数正好是「平均每行几份」—— 一两倍而已，算出来的人均照样像真的。
//
// 账单头表的 actual_amount 是完全独立的来源（营业额统计页用的就是它）。
// 两边一对，哪个口径对一目了然。
//
// ⚠️ 两张表的时间字段不同：账单头按【开台时间】，明细按【下单时间】。
// 跨夜的单会分到不同区间里，所以允许几个百分点的出入，不必强求分毫不差。
$head = Db::select(
    'SELECT SUM(actual_amount) AS actual, COUNT(*) AS rows_cnt,
            COUNT(DISTINCT order_head_id) AS orders
     FROM history_order_head
     WHERE order_start_time >= :from AND order_start_time < :to',
    [':from' => $from, ':to' => $to])[0] ?? null;

$det = Db::select(
    'SELECT SUM(actual_price * quantity) AS by_unit,
            SUM(actual_price)            AS by_line,
            SUM(quantity)                AS qty,
            COUNT(*)                     AS lines_cnt
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to AND ' . DISH_ONLY,
    [':from' => $from, ':to' => $to])[0] ?? null;

// 不加任何过滤的那一份：付款方式行（-4）之类也算进来，看看是不是它们补上了差额
$detAll = Db::select(
    'SELECT SUM(actual_price * quantity) AS by_unit, SUM(actual_price) AS by_line
     FROM history_order_detail
     WHERE order_time >= :from AND order_time < :to',
    [':from' => $from, ':to' => $to])[0] ?? null;

$hv = (float) ($head['actual'] ?? 0);
kv('账单头 实收合计', number_format($hv, 2) . '  （' . (int) ($head['orders'] ?? 0) . ' 张单）');
say('    明细表这边，按三种口径各算一遍：');
$cands = [
    'actual_price × quantity（页面现在用的）' => (float) ($det['by_unit'] ?? 0),
    'actual_price 直接相加（当成行金额）'     => (float) ($det['by_line'] ?? 0),
    'actual_price × quantity（不过滤任何行）' => (float) ($detAll['by_unit'] ?? 0),
];
// 先算出每个口径离账单头差多少
$diffs = [];
foreach ($cands as $label => $v) {
    $d = $hv > 0 ? ($v - $hv) / $hv * 100 : 0.0;
    $diffs[$label] = $d;
    printf("      %-42s %14s  差 %+7.1f%%\n", $label, number_format($v, 2), $d);
}
if (($det['lines_cnt'] ?? 0) > 0) {
    kv('平均每行几份', sprintf('%.2f', (float) $det['qty'] / (int) $det['lines_cnt'])
        . '  ← 认错口径的话，金额差不多就放大这么多倍');
}

// 判定顺序很讲究：【先看页面现在用的那个口径合不合格】，
// 而不是挑「最接近的」。两个口径都在容差内时挑最接近的，会因为
// 零点几个百分点的差就判页面错 —— 那是没事找事，改完还可能更糟。
// 只有现用口径真的超出容差，才去找哪个对得上。
$TOL   = 5.0;                     // 两张表时间字段不同，跨夜单会分岔，留 5% 容差
$cur   = 'actual_price × quantity（页面现在用的）';
$curD  = $diffs[$cur] ?? null;
if ($hv <= 0) {
    say('    → 账单头这边没金额，对不了。');
    $verdict['money'] = null;
} elseif ($curD !== null && abs($curD) <= $TOL) {
    kv('页面现用口径', sprintf('差 %+.1f%%，在 ±%.0f%% 容差内', $curD, $TOL));
    say('    → 页面现在的算法对得上账单头，金额可信。');
    say('      也就是说：这一天的钱确实几乎全记在某一个岗位上，不是算错 ——');
    say('      套餐挂在哪个岗位，钱就全算给哪个岗位。');
    $verdict['money'] = true;
} else {
    asort($diffs);
    $best = null;
    foreach ($diffs as $label => $d) {
        if ($best === null || abs($d) < abs($diffs[$best])) { $best = $label; }
    }
    kv('页面现用口径', sprintf('差 %+.1f%% —— 超出 ±%.0f%% 容差', (float) $curD, $TOL));
    if (abs($diffs[$best]) <= $TOL) {
        kv('对得上的是', $best . sprintf('（差 %+.1f%%）', $diffs[$best]));
        say('    → ⚠️ 对得上的【不是】页面现在用的那个口径。');
        say('      岗位页和菜品页的「金额」都要改成上面这一个。');
    } else {
        say('    → 三种口径都对不上账单头，差得还不小。');
        say('      可能是服务费／税／退单只记在账单头那边，也可能是别的原因。');
        say('      在弄清楚之前，岗位页和菜品页的「金额」列都不要当准数用。');
    }
    $verdict['money'] = false;
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
$split   = $verdict['split']  ?? null;   // 乙是不是被拆开了（虚高）
$subset  = $verdict['subset'] ?? null;   // 甲是不是只覆盖了一部分（残缺）

if (!empty($verdict['print_task'])) {
    say('结论：print_task 里居然有历史 —— 先去看它，那比推算准。');
} elseif (!$okBatch) {
    say('结论：算不出来。order_time 不是「一次下单」的标识（见第 3 节），');
    say('  数据库里也没有别的地方记着出了几张单。');
    say('  硬算的话得到的是「菜品行数」，不是「单数」—— 那是个看着合理的错数字。');
} elseif ($okAgree) {
    say('结论：可以算。');
    say('  「单数」= COUNT(DISTINCT order_head_id, order_time)，按岗位分组。');
    say('  两条独立线索（Enviado 标记 / 下单时刻）对得上，第 6 节那一列可以用。');
} elseif ($split === false && $subset === true) {
    // 两条线索数目差很多，但差异【已经解释清楚了】：
    // 4b 说批次不是被拆出来的，4c 说标记只是批次的一个子集。
    say('结论：可以算，用乙（下单时刻）。');
    say('  「单数」= COUNT(DISTINCT order_head_id, order_time)，按岗位分组。');
    say('  两条线索数目差很多，但 4b + 4c 已经把差异解释清楚了：');
    say('    · 4b：相邻两批隔着几分钟，不是同一次下单被拆开 → 乙没虚高');
    say('    · 4c：标记几乎都落在某一批菜上 → 标记是批次的子集，甲本来就不全');
} elseif ($split === true) {
    say('结论：先别直接用。4b 显示一次下单会被拆成好几秒写库，');
    say('  直接数 (单, 下单时刻) 会偏多。要用的话得先按一个秒数阈值合并 ——');
    say('  4b 那张表列了几个阈值各自的结果，挑一个和 4c 对得上的。');
} else {
    say('结论：能算，但两条线索的差异还没解释清楚（见 4b / 4c）。');
    say('  弄清楚之前，别把这一列放上页面 —— ');
    say('  一个没验过的「张数」和一个合理的整数长得一模一样。');
}
say();
if (($verdict['money'] ?? null) === false) {
    say('⚠️ 另外：第 6b 节显示【金额】那一列的口径对不上账单头，');
    say('   岗位页和菜品页的金额都要先查清楚再用。票数和份数不受影响。');
    say();
}
say('无论哪种结论，有两件事永远数不到：重打的单、手工补打的单 ——');
say('数据库里根本没有痕迹。所以这个数是「下单产生的票数」，不是「打印机吐了几张纸」。');
say();

if (!$cli) {
    echo '</pre>';
}
