<?php
/**
 * 结果加工层：把数据库返回的扁平结果整理成页面需要的结构。
 * 这一层不访问数据库，纯内存计算。
 */

declare(strict_types=1);

require_once __DIR__ . '/biz.php';

final class Report
{
    private const SEGS = [Biz::SEG_DAY, Biz::SEG_NIGHT, Biz::SEG_GAP];

    /** 空的金额统计单元 */
    private static function zeroCell(): array
    {
        return [
            'checks' => 0, 'guests' => 0, 'actual' => 0.0, 'original' => 0.0,
            'discount' => 0.0, 'service' => 0.0, 'tax' => 0.0,
            'should_amt' => 0.0, 'ret' => 0.0,
        ];
    }

    private static function addCell(array $a, array $r): array
    {
        $a['checks']     += (int) $r['checks'];
        $a['guests']     += (int) $r['guests'];
        $a['actual']     += (float) $r['actual'];
        $a['original']   += (float) $r['original'];
        $a['discount']   += (float) $r['discount'];
        $a['service']    += (float) $r['service'];
        $a['tax']        += (float) $r['tax'];
        $a['should_amt'] += (float) $r['should_amt'];
        $a['ret']        += (float) $r['ret'];
        return $a;
    }

    /**
     * 把 salesByDay 的结果（可能来自历史表 + 实时表两次查询）整理成：
     *   [
     *     'days'  => [ 'YYYY-MM-DD' => ['day'=>cell,'night'=>cell,'gap'=>cell,'total'=>cell], ... ],
     *     'total' => ['day'=>cell,'night'=>cell,'gap'=>cell,'total'=>cell],
     *   ]
     */
    public static function pivotSales(array ...$resultSets): array
    {
        $days = [];
        foreach ($resultSets as $rows) {
            foreach ($rows as $r) {
                $d   = (string) $r['biz_date'];
                $seg = (string) $r['seg'];
                if (!isset($days[$d])) {
                    $days[$d] = [
                        'day' => self::zeroCell(), 'night' => self::zeroCell(),
                        'gap' => self::zeroCell(), 'total' => self::zeroCell(),
                    ];
                }
                $days[$d][$seg]   = self::addCell($days[$d][$seg], $r);
                $days[$d]['total'] = self::addCell($days[$d]['total'], $r);
            }
        }
        ksort($days);

        $total = [
            'day' => self::zeroCell(), 'night' => self::zeroCell(),
            'gap' => self::zeroCell(), 'total' => self::zeroCell(),
        ];
        foreach ($days as $cells) {
            foreach (['day', 'night', 'gap', 'total'] as $k) {
                foreach ($cells[$k] as $f => $v) {
                    $total[$k][$f] += $v;
                }
            }
        }
        return ['days' => $days, 'total' => $total];
    }

    /** 空的菜品统计单元 */
    private static function zeroDish(): array
    {
        return ['qty' => 0.0, 'times' => 0, 'amount' => 0.0];
    }

    /**
     * 把 dishTotals 的结果整理成按菜品聚合的结构，并附上岗位信息。
     *
     * @param array $menuItems Biz::menuItems() 的结果
     * @return array [
     *    'items' => [ item_id => ['id','name','pc','pc_name','day'=>cell,'night'=>cell,
     *                             'gap'=>cell,'total'=>cell] ],
     *    'grand' => ['day'=>cell,'night'=>cell,'gap'=>cell,'total'=>cell],
     * ]
     */
    public static function buildDishes(array $menuItems, array $printClasses, array ...$resultSets): array
    {
        $items = [];
        $grand = ['day' => self::zeroDish(), 'night' => self::zeroDish(),
                  'gap' => self::zeroDish(), 'total' => self::zeroDish()];

        foreach ($resultSets as $rows) {
            foreach ($rows as $r) {
                $id = (int) $r['menu_item_id'];

                // 做法 / 口味项（item_type = 1）不是菜，排除
                if (!empty($menuItems[$id]['is_condiment'])) {
                    continue;
                }

                $seg = (string) $r['seg'];
                if (!isset($items[$id])) {
                    $pc = $menuItems[$id]['print_class'] ?? null;
                    $items[$id] = [
                        'id'      => $id,
                        // 优先用字典里的菜名，字典查不到才退回明细里记录的历史菜名
                        'name'    => $menuItems[$id]['name'] ?? (string) $r['item_name'],
                        'pc'      => $pc,
                        'pc_name' => ($pc !== null && isset($printClasses[$pc]))
                                        ? $printClasses[$pc] : '未分配岗位',
                        'day'     => self::zeroDish(), 'night' => self::zeroDish(),
                        'gap'     => self::zeroDish(), 'total' => self::zeroDish(),
                    ];
                }
                $cell = ['qty' => (float) $r['qty'], 'times' => (int) $r['times'], 'amount' => (float) $r['amount']];
                foreach ($cell as $f => $v) {
                    $items[$id][$seg][$f]  += $v;
                    $items[$id]['total'][$f] += $v;
                    $grand[$seg][$f]       += $v;
                    $grand['total'][$f]    += $v;
                }
            }
        }
        return ['items' => $items, 'grand' => $grand];
    }

    /**
     * 排行：按指定时段的点单数量排序，取前 N / 后 N。
     *
     * @param string $seg  day / night / gap / total
     * @param string $dir  'desc' 取最多，'asc' 取最少
     */
    public static function rank(array $items, string $seg, string $dir, int $limit = 10): array
    {
        $list = array_values(array_filter($items, static fn($it) => $it[$seg]['qty'] > 0));
        usort($list, static function ($a, $b) use ($seg, $dir) {
            $cmp = $a[$seg]['qty'] <=> $b[$seg]['qty'];
            if ($cmp === 0) {
                // 数量相同时按菜名排，保证结果稳定可复现
                return strcmp($a['name'], $b['name']);
            }
            return $dir === 'desc' ? -$cmp : $cmp;
        });
        return array_slice($list, 0, $limit);
    }

    /**
     * 按岗位分组，每组给出前 N 和后 N。
     *
     * @return array [ ['pc'=>..,'pc_name'=>..,'items'=>N,'qty'=>..,'top'=>[],'bottom'=>[]], ... ]
     *               按该岗位总点单量降序
     */
    public static function byStation(array $items, string $seg, int $limit = 10): array
    {
        $groups = [];
        foreach ($items as $it) {
            if ($it[$seg]['qty'] <= 0) {
                continue;
            }
            $key = $it['pc'] ?? 'none';
            if (!isset($groups[$key])) {
                $groups[$key] = ['pc' => $it['pc'], 'pc_name' => $it['pc_name'], 'qty' => 0.0, 'list' => []];
            }
            $groups[$key]['qty']   += $it[$seg]['qty'];
            $groups[$key]['list'][] = $it;
        }

        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'pc'      => $g['pc'],
                'pc_name' => $g['pc_name'],
                'items'   => count($g['list']),
                'qty'     => $g['qty'],
                'top'     => self::rank($g['list'], $seg, 'desc', $limit),
                'bottom'  => self::rank($g['list'], $seg, 'asc', $limit),
            ];
        }
        usort($out, static fn($a, $b) => $b['qty'] <=> $a['qty']);
        return $out;
    }

    // ------------------------------------------------------------------
    // 开台核对
    // ------------------------------------------------------------------

    public const OPEN_OK      = 'ok';       // 人数与套餐份数一致
    public const OPEN_SHORT   = 'short';    // 套餐打少了
    public const OPEN_OVER    = 'over';     // 套餐打多了
    public const OPEN_NONE    = 'none';     // 一份套餐都没打
    public const OPEN_NOGUEST = 'noguest';  // 没填人数，没法比对

    /**
     * 把开台列表和套餐份数合起来，逐桌判断人数与套餐是否对得上。
     *
     * @param array $heads   Biz::openTables() 的结果
     * @param array $counts  Biz::orderComboCounts() 的结果
     * @param int   $warnHours 开台超过几小时算滞留
     * @param array $acks    人工确认记录 [order_head_id => ['fp'=>..,'at'=>..]]，
     *                       指纹对不上（人数或套餐份数变了）就当作没确认过
     */
    public static function buildOpenTables(array $heads, array $counts, int $warnHours = 4,
                                           array $acks = []): array
    {
        $byId = [];
        foreach ($counts as $c) {
            $byId[(int) $c['order_head_id']] = $c;
        }

        $rows = [];
        $sum  = ['tables' => 0, 'guests' => 0, 'combo' => 0.0, 'amount' => 0.0,
                 'problem' => 0, 'acked' => 0, 'stale' => 0];
        $now  = time();

        foreach ($heads as $h) {
            $id     = (int) $h['order_head_id'];
            $guests = (int) $h['guests'];
            $c      = $byId[$id] ?? null;
            $combo  = $c ? (float) $c['combo_qty'] : 0.0;
            $dishes = $c ? (float) $c['dish_qty']  : 0.0;
            $lines  = $c ? (int) $c['lines_cnt']   : 0;
            $diff   = $combo - $guests;

            if ($guests <= 0) {
                $state = self::OPEN_NOGUEST;
            } elseif (abs($diff) < 0.001) {
                $state = self::OPEN_OK;
            } elseif ($combo <= 0) {
                $state = self::OPEN_NONE;
            } elseif ($diff < 0) {
                $state = self::OPEN_SHORT;
            } else {
                $state = self::OPEN_OVER;
            }

            $t0   = $h['t0'] ? strtotime((string) $h['t0']) : null;
            $mins = $t0 ? (int) floor(($now - $t0) / 60) : null;
            $stale = $mins !== null && $mins >= $warnHours * 60;

            // 人工确认：只有指纹一致才算数，人数或套餐一变就自动作废
            $fp    = ((int) $guests) . ':' . ((int) round($combo * 100));
            $a     = $acks[$id] ?? null;
            $acked = is_array($a) && ($a['fp'] ?? null) === $fp;

            $rows[] = [
                'acked'    => $acked,
                'acked_at' => $acked ? (int) ($a['at'] ?? 0) : null,
                'fp'       => $fp,
                'id'       => $id,
                'table'    => (string) ($h['table_name'] ?? ''),
                'guests'   => $guests,
                'combo'    => $combo,
                'diff'     => $diff,
                'state'    => $state,
                'dishes'   => $dishes,
                'lines'    => $lines,
                'amount'   => (float) $h['amount'],
                'checks'   => (int) $h['checks'],
                'eat_type' => (int) $h['eat_type'],
                'settled'  => !empty($h['settled']),
                'start'    => (string) ($h['t0'] ?? ''),
                'minutes'  => $mins,
                'stale'    => $stale,
                'employee' => (string) ($h['employee'] ?? ''),
            ];

            $sum['tables']++;
            $sum['guests'] += $guests;
            $sum['combo']  += $combo;
            $sum['amount'] += (float) $h['amount'];
            // 已人工确认的不再算作待处理，但单独计数，方便看确认了多少台
            if ($state !== self::OPEN_OK) {
                if ($acked) {
                    $sum['acked']++;
                } else {
                    $sum['problem']++;
                }
            }
            if ($stale) {
                $sum['stale']++;
            }
        }

        return ['rows' => $rows, 'sum' => $sum];
    }

    /** 问题桌排前面，其次按开台时间；方便一眼看到要处理的 */
    public static function sortOpenTables(array $rows, bool $problemFirst = true): array
    {
        $rank = [
            self::OPEN_NONE    => 0,
            self::OPEN_SHORT   => 1,
            self::OPEN_OVER    => 2,
            self::OPEN_NOGUEST => 3,
            self::OPEN_OK      => 4,
        ];
        usort($rows, static function ($a, $b) use ($rank, $problemFirst) {
            if ($problemFirst) {
                // 已确认的降到问题台之后（但仍排在完全正常的台之前）
                $ra = !empty($a['acked']) ? 3.5 : ($rank[$a['state']] ?? 9);
                $rb = !empty($b['acked']) ? 3.5 : ($rank[$b['state']] ?? 9);
                if ($ra != $rb) {
                    return $ra <=> $rb;
                }
            }
            return strcmp($a['start'], $b['start']);
        });
        return $rows;
    }

    public static function openStateLabel(string $state): string
    {
        return [
            self::OPEN_OK      => '一致',
            self::OPEN_SHORT   => '套餐打少了',
            self::OPEN_OVER    => '套餐打多了',
            self::OPEN_NONE    => '未打套餐',
            self::OPEN_NOGUEST => '未填人数',
        ][$state] ?? $state;
    }

    /** 岗位单量统计的空单元 */
    private static function zeroStation(): array
    {
        return ['orders' => 0, 'items' => 0, 'qty' => 0.0, 'lines' => 0, 'amount' => 0.0];
    }

    /**
     * 整理 Biz::stationVolume() 的结果（可能来自历史表 + 实时表两次查询）。
     *
     * 注意 orders（单量）和 items（菜品数）都是数据库端算的 DISTINCT 值，
     * 跨表相加会有重复计的可能 —— 但历史表与实时表装的是不同时期的单，
     * 不会重叠，所以直接相加是安全的。
     *
     * @return array ['stations' => [...], 'grand' => ['day'=>..,'night'=>..,'gap'=>..,'total'=>..]]
     */
    public static function buildStations(array $printClasses, array ...$resultSets): array
    {
        $st    = [];
        $grand = ['day' => self::zeroStation(), 'night' => self::zeroStation(),
                  'gap' => self::zeroStation(), 'total' => self::zeroStation()];

        foreach ($resultSets as $rows) {
            foreach ($rows as $r) {
                $pc  = (int) $r['pc'];
                $seg = (string) $r['seg'];
                if (!isset($st[$pc])) {
                    $st[$pc] = [
                        'pc'      => $pc,
                        'pc_name' => self::stationName($pc, $printClasses),
                        'day'     => self::zeroStation(), 'night' => self::zeroStation(),
                        'gap'     => self::zeroStation(), 'total' => self::zeroStation(),
                    ];
                }
                $cell = [
                    'orders' => (int) $r['orders'],
                    'items'  => (int) $r['items'],
                    'qty'    => (float) $r['qty'],
                    'lines'  => (int) $r['lines_cnt'],
                    'amount' => (float) $r['amount'],
                ];
                foreach ($cell as $f => $v) {
                    $st[$pc][$seg][$f]    += $v;
                    $st[$pc]['total'][$f] += $v;
                    $grand[$seg][$f]      += $v;
                    $grand['total'][$f]   += $v;
                }
            }
        }
        return ['stations' => array_values($st), 'grand' => $grand];
    }

    private static function stationName(int $pc, array $printClasses): string
    {
        if ($pc === Biz::PC_NONE) {
            return '未分配岗位';
        }
        if ($pc === Biz::PC_UNKNOWN) {
            return '菜品已从菜单删除';
        }
        return $printClasses[$pc] ?? ('岗位 #' . $pc);
    }

    /** 按指定指标给岗位排名（降序）；数值相同时按岗位名排，保证结果稳定 */
    public static function sortStations(array $stations, string $by): array
    {
        $key = in_array($by, ['orders', 'qty', 'lines', 'amount'], true) ? $by : 'orders';
        usort($stations, static function ($a, $b) use ($key) {
            $cmp = $b['total'][$key] <=> $a['total'][$key];
            return $cmp !== 0 ? $cmp : strcmp($a['pc_name'], $b['pc_name']);
        });
        return $stations;
    }

    /**
     * 只保留属于指定岗位的菜品。
     *
     * @param string $pc 岗位 ID；'none' 表示未分配岗位的菜品
     */
    public static function filterByStation(array $items, string $pc): array
    {
        return array_filter($items, static function ($it) use ($pc) {
            return $pc === 'none'
                ? $it['pc'] === null
                : (string) $it['pc'] === $pc;
        });
    }

    /**
     * 各岗位汇总概览（不含菜品明细），用于一眼看清岗位分布。
     *
     * @return array [ ['pc','pc_name','items','qty','times','amount'], ... ] 按点单量降序
     */
    public static function stationSummary(array $items, string $seg): array
    {
        $g = [];
        foreach ($items as $it) {
            if ($it[$seg]['qty'] <= 0) {
                continue;
            }
            $k = $it['pc'] ?? 'none';
            if (!isset($g[$k])) {
                $g[$k] = ['pc' => $it['pc'], 'pc_name' => $it['pc_name'],
                          'items' => 0, 'qty' => 0.0, 'times' => 0, 'amount' => 0.0];
            }
            $g[$k]['items']  += 1;
            $g[$k]['qty']    += $it[$seg]['qty'];
            $g[$k]['times']  += $it[$seg]['times'];
            $g[$k]['amount'] += $it[$seg]['amount'];
        }
        $out = array_values($g);
        usort($out, static fn($a, $b) => $b['qty'] <=> $a['qty']);
        return $out;
    }

    /**
     * 范围内一次都没被点过的菜品（做法项除外）。
     * 对"哪些菜该下架"这类问题很有用。
     */
    public static function neverOrdered(array $menuItems, array $items, array $printClasses): array
    {
        $out = [];
        foreach ($menuItems as $id => $m) {
            if ($m['is_condiment'] || isset($items[$id])) {
                continue;
            }
            $pc = $m['print_class'];
            $out[] = [
                'id'      => $id,
                'name'    => $m['name'],
                'pc_name' => ($pc !== null && isset($printClasses[$pc])) ? $printClasses[$pc] : '未分配岗位',
            ];
        }
        usort($out, static fn($a, $b) => [$a['pc_name'], $a['name']] <=> [$b['pc_name'], $b['name']]);
        return $out;
    }
}
