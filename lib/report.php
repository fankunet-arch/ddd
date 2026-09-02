<?php
/**
 * 结果加工层：把数据库返回的扁平结果整理成页面需要的结构。
 * 这一层不访问数据库，纯内存计算。
 */

declare(strict_types=1);

require_once __DIR__ . '/biz.php';
require_once __DIR__ . '/ack.php';

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
    public const OPEN_SKIP    = 'skip';     // 外带之类，本来就没有人头套餐，不用核对

    /** 酒水核对：规则是「每人至少 N 份，多了没关系」 */
    public const DRINK_OK    = 'ok';        // 达标
    public const DRINK_SHORT = 'short';     // 点了，但不够人头
    public const DRINK_NONE  = 'none';      // 一份没点
    public const DRINK_NA    = 'na';        // 不适用：没填人数、免核对台、或没开启这项检查

    public static function drinkStateLabel(string $state): string
    {
        return [
            self::DRINK_OK    => '酒水够',
            self::DRINK_SHORT => '酒水不足',
            self::DRINK_NONE  => '未点酒水',
            self::DRINK_NA    => '不核对酒水',
        ][$state] ?? $state;
    }

    /**
     * 从配置里读出免核对规则，缺项退回 lib/settings.php 的默认值。
     *
     * 正常情况下 Db::config() 已经把默认值合进来了，这里的兜底是为了
     * 直接传进来的裸配置数组（测试、或万一有人绕过 Db 读 config.php）——
     * 缺项时功能应当照常生效，而不是静默失灵。
     *
     * 用 array_key_exists 区分「没写这一项」和「写了但留空」：
     * 没写 → 套默认；明确写成 [] → 表示「所有台都要核对」，必须尊重。
     *
     * @return array ['tables' => 桌号通配符[], 'eat_types' => eat_type 取值[]]
     */
    public static function skipRules(array $cfg): array
    {
        if (!array_key_exists('no_combo_tables', $cfg)) {
            // 默认值只有 settings.php 一个出处，避免两边抄一份抄岔了。
            // 用 require 而不是 require_once —— 后者第二次调用只返回 true。
            $defaults = (array) require __DIR__ . '/settings.php';
            $cfg['no_combo_tables'] = $defaults['no_combo_tables'] ?? [];
        }

        $tables = array_map(static fn($v) => trim((string) $v), (array) $cfg['no_combo_tables']);

        return [
            'tables'    => array_values(array_filter($tables, static fn($v) => $v !== '')),
            'eat_types' => array_map('intval', (array) ($cfg['no_combo_eat_types'] ?? [])),
        ];
    }

    /**
     * 名字是否命中任意一条通配符规则。
     *
     * 大小写不敏感，支持 * 与 ?（'Llevar*' 能匹配 Llevar、LLEVAR 2、Llevar-03）。
     * 两端空格先去掉，避免 POS 里手滑多打一个空格就匹配不上。
     * 整体匹配，不做部分匹配：'Llevar*' 不会命中 'A-Llevar'。
     */
    public static function matchesAny(string $name, array $patterns): bool
    {
        $name = trim($name);
        if ($name === '' || !$patterns) {
            return false;
        }
        foreach ($patterns as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            // preg_quote 会把 * ? 也转义掉，这里再换回通配符语义
            $re = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($p, '/')) . '$/i';
            if (preg_match($re, $name) === 1) {
                return true;
            }
        }
        return false;
    }

    /** 桌号是否属于「不按人头核对」的台 */
    public static function isNoComboTable(string $table, array $patterns): bool
    {
        return self::matchesAny($table, $patterns);
    }

    // ------------------------------------------------------------------
    // 酒水口径
    // ------------------------------------------------------------------

    /**
     * 算出哪些菜品算「酒水」。
     *
     * 按【出品岗位名】匹配而不是列菜品 ID：酒水都从吧台/饮料机出，print_class
     * 就是现成的分类，换季加饮料也不用回来改清单。再用 drink_extra_item_ids /
     * drink_exclude_item_ids 做个别补充和剔除。
     *
     * 做法/口味项（item_type = 1）不是菜，永远排除。
     *
     * @param array $menuItems   Biz::menuItems() 的结果
     * @param array $printClasses Biz::printClasses() 的结果 pc => name
     * @return array [
     *   'ids'      => int[]             算作酒水的菜品 ID
     *   'classes'  => [pc => name]      命中的岗位
     *   'patterns' => string[]          生效的岗位名规则
     *   'extra'    => int[]             额外补进来的菜品 ID（真实存在于菜单的）
     *   'excluded' => int[]             被剔除的菜品 ID
     * ]
     */
    public static function drinkItems(array $menuItems, array $printClasses, array $cfg): array
    {
        $patterns = array_values(array_filter(array_map(
            static fn($v) => trim((string) $v),
            (array) ($cfg['drink_print_classes'] ?? [])), static fn($v) => $v !== ''));

        $classes = [];
        foreach ($printClasses as $pc => $name) {
            if (self::matchesAny((string) $name, $patterns)) {
                $classes[(int) $pc] = (string) $name;
            }
        }

        $exclude = [];
        foreach ((array) ($cfg['drink_exclude_item_ids'] ?? []) as $v) {
            $exclude[(int) $v] = true;
        }

        $ids = [];
        foreach ($menuItems as $id => $m) {
            $id = (int) $id;
            if ($id <= 0 || !empty($m['is_condiment']) || isset($exclude[$id])) {
                continue;
            }
            $pc = $m['print_class'];
            if ($pc !== null && isset($classes[(int) $pc])) {
                $ids[$id] = true;
            }
        }

        $extra = [];
        foreach ((array) ($cfg['drink_extra_item_ids'] ?? []) as $v) {
            $id = (int) $v;
            if ($id > 0 && !isset($exclude[$id])) {
                $ids[$id] = true;
                if (isset($menuItems[$id])) {
                    $extra[] = $id;
                }
            }
        }

        ksort($ids);
        return [
            'ids'      => array_keys($ids),
            'classes'  => $classes,
            'patterns' => $patterns,
            'extra'    => $extra,
            'excluded' => array_keys($exclude),
        ];
    }

    /**
     * 这张台要不要跳过「人数 vs 套餐」核对。
     *
     * @param array $skip ['tables' => 桌号通配符[], 'eat_types' => eat_type 取值[]]
     */
    public static function skipsComboCheck(string $table, int $eatType, array $skip): bool
    {
        if (self::isNoComboTable($table, (array) ($skip['tables'] ?? []))) {
            return true;
        }
        $types = array_map('intval', (array) ($skip['eat_types'] ?? []));
        return $types !== [] && in_array($eatType, $types, true);
    }

    /**
     * 把开台列表和套餐份数合起来，逐桌判断人数与套餐是否对得上。
     *
     * @param array $heads   Biz::openTables() 的结果
     * @param array $counts  Biz::orderComboCounts() 的结果
     * @param int   $warnHours 开台超过几小时算滞留
     * @param array $acks    人工确认记录 [order_head_id => ['fp'=>..,'at'=>..]]，
     *                       指纹对不上（人数或套餐份数变了）就当作没确认过
     * @param array $skip    免核对规则 ['tables'=>[..], 'eat_types'=>[..]]，
     *                       命中的台（外带等）判为 OPEN_SKIP，不算问题台
     * @param array $opts    ['min_drink' => 每位客人至少几份酒水，0 = 只统计不核对]
     */
    public static function buildOpenTables(array $heads, array $counts, int $warnHours = 4,
                                           array $acks = [], array $skip = [],
                                           array $opts = []): array
    {
        // 酒水规则：每人至少 min_drink 份，多了没关系。0 表示只统计、不核对。
        $minDrink = array_key_exists('min_drink', $opts) ? (float) $opts['min_drink'] : 1.0;
        $byId = [];
        foreach ($counts as $c) {
            $byId[(int) $c['order_head_id']] = $c;
        }

        $rows = [];
        $sum  = ['tables' => 0, 'guests' => 0, 'combo' => 0.0, 'amount' => 0.0,
                 'problem' => 0, 'acked' => 0, 'stale' => 0, 'skip' => 0,
                 'drink' => 0.0, 'drink_amount' => 0.0,
                 'combo_problem' => 0, 'drink_problem' => 0];
        $now  = time();

        foreach ($heads as $h) {
            $id     = (int) $h['order_head_id'];
            $table  = (string) ($h['table_name'] ?? '');
            $guests = (int) $h['guests'];
            $c      = $byId[$id] ?? null;
            $combo  = $c ? (float) $c['combo_qty'] : 0.0;
            $dishes = $c ? (float) $c['dish_qty']  : 0.0;
            $lines  = $c ? (int) $c['lines_cnt']   : 0;
            $drink  = $c ? (float) ($c['drink_qty'] ?? 0)    : 0.0;
            $drinkA = $c ? (float) ($c['drink_amount'] ?? 0) : 0.0;
            $diff   = $combo - $guests;

            // 外带（Llevar）之类的台没有堂食人数、也不会点按人头的自助套餐，
            // 拿人数去比对只会一直报「未打套餐」，直接判为免核对。
            $noCheck = self::skipsComboCheck($table, (int) ($h['eat_type'] ?? 0), $skip);

            if ($noCheck) {
                $state = self::OPEN_SKIP;
            } elseif ($guests <= 0) {
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

            // 酒水核对：每人至少 min_drink 份，够了就行，多了不管。
            // 没填人数、免核对的台、或没开启这项检查时不判定。
            $need = ($minDrink > 0 && $guests > 0 && !$noCheck) ? $guests * $minDrink : 0.0;
            if ($need <= 0) {
                $dstate = self::DRINK_NA;
            } elseif ($drink + 0.001 >= $need) {
                $dstate = self::DRINK_OK;
            } elseif ($drink <= 0) {
                $dstate = self::DRINK_NONE;
            } else {
                $dstate = self::DRINK_SHORT;
            }
            $drinkShort = max(0.0, $need - $drink);

            $t0   = $h['t0'] ? strtotime((string) $h['t0']) : null;
            $mins = $t0 ? (int) floor(($now - $t0) / 60) : null;
            $stale = $mins !== null && $mins >= $warnHours * 60;

            // 套餐、酒水任一项不合格，这张台就要人看一眼
            $comboBad = $state !== self::OPEN_OK && $state !== self::OPEN_SKIP;
            $drinkBad = $dstate === self::DRINK_SHORT || $dstate === self::DRINK_NONE;
            $bad      = $comboBad || $drinkBad;

            // 人工确认：只有指纹一致才算数，人数、套餐份数或酒水达标与否一变就自动作废。
            // 酒水只记「够没够」而不记杯数 —— 不足时又加一杯（仍然不足）不该让确认作废。
            // 免核对的台本来就没有待确认的事，不显示确认状态。
            $fp    = Ack::fingerprint([
                'guests'   => $guests,
                'combo'    => $combo,
                'drink_ok' => !$drinkBad,
            ]);
            $a     = $acks[$id] ?? null;
            $acked = !$noCheck && is_array($a) && ($a['fp'] ?? null) === $fp;

            $rows[] = [
                'acked'    => $acked,
                'acked_at' => $acked ? (int) ($a['at'] ?? 0) : null,
                'fp'       => $fp,
                'id'       => $id,
                'table'    => $table,
                'skip'     => $noCheck,
                'guests'   => $guests,
                'combo'    => $combo,
                'diff'     => $diff,
                'state'    => $state,
                'bad'      => $bad,
                'drink'        => $drink,
                'drink_amount' => $drinkA,
                'drink_state'  => $dstate,
                'drink_need'   => $need,
                'drink_short'  => $drinkShort,
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
            $sum['drink']        += $drink;
            $sum['drink_amount'] += $drinkA;
            // 已人工确认的不再算作待处理，但单独计数，方便看确认了多少台
            if ($state === self::OPEN_SKIP) {
                $sum['skip']++;
            } elseif ($bad) {
                if ($acked) {
                    $sum['acked']++;
                } else {
                    $sum['problem']++;
                    // 再按维度拆一下，方便一眼看出是套餐的问题还是酒水的问题
                    if ($comboBad) {
                        $sum['combo_problem']++;
                    }
                    if ($drinkBad) {
                        $sum['drink_problem']++;
                    }
                }
            }
            if ($stale) {
                $sum['stale']++;
            }
        }

        return ['rows' => $rows, 'sum' => $sum];
    }

    /** 问题桌排前面，组内按桌号；方便一眼看到要处理的 */
    public static function sortOpenTables(array $rows, bool $problemFirst = true): array
    {
        $rank = [
            self::OPEN_NONE    => 0,
            self::OPEN_SHORT   => 1,
            self::OPEN_OVER    => 2,
            self::OPEN_NOGUEST => 3,
            self::OPEN_OK      => 4,
        ];
        // 分档：套餐有问题 → 只有酒水不足 → 已人工确认 → 全都合格 → 免核对
        $tier = static function (array $r) use ($rank): float {
            if ($r['state'] === self::OPEN_SKIP) {
                return 9.0;                       // 外带等，压到最后
            }
            if (!empty($r['acked'])) {
                return 5.0;                       // 确认过的降到问题台之后、正常台之前
            }
            if (empty($r['bad'])) {
                return 6.0;                       // 套餐和酒水都合格
            }
            $st = $rank[$r['state']] ?? 4;
            return $st < 4 ? (float) $st : 4.5;   // 4.5 = 套餐没问题，只是酒水不足
        };

        usort($rows, static function ($a, $b) use ($tier, $problemFirst) {
            if ($problemFirst) {
                $ra = $tier($a);
                $rb = $tier($b);
                if ($ra != $rb) {
                    return $ra <=> $rb;
                }
            }
            // 组内按桌号排，方便照着桌号找。用自然排序，这样 2 排在 10 前面，
            // 而不是字符串序把 10 排到 2 前面；纯数字桌号会自动排在文字桌号之前。
            $cmp = strnatcasecmp($a['table'], $b['table']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['start'], $b['start']);   // 桌号相同再按开台时间，保证顺序稳定
        });
        return $rows;
    }

    public static function openStateLabel(string $state): string
    {
        return [
            self::OPEN_OK      => '套餐一致',
            self::OPEN_SHORT   => '套餐打少了',
            self::OPEN_OVER    => '套餐打多了',
            self::OPEN_NONE    => '未打套餐',
            self::OPEN_NOGUEST => '未填人数',
            self::OPEN_SKIP    => '免核对',
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
