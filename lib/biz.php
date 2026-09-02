<?php
/**
 * 营业统计业务逻辑
 *
 * 设计原则（对应需求中"数据表单独统计，非必要每次只统计一张表"）：
 *   - 营业额/人数：只查 history_order_head（+ 可选 order_head），不做任何 JOIN。
 *   - 菜品点单  ：只查 history_order_detail（+ 可选 order_detail），不做任何 JOIN。
 *   - 岗位、菜名：单独查 menu_item / print_class 两张小字典表（几百行），
 *                 在 PHP 内存里做映射，绝不与大表 JOIN。
 *
 * 关于时间口径（经真实数据验证）：
 *   - 时段按【开台时间 order_start_time】判定。
 *   - 营业日 = DATE(时间 - 2 小时)，因此凌晨 00:00~01:59 的单归入前一天晚市。
 *   - 白天 [08:00, 17:30)，晚上 [18:00, 次日 02:00)。
 *     实测 931 个营业日中落在两段之外的账单仅 13 单，故"白天+晚上≈全天"，
 *     但程序仍单独保留 gap（时段外）一档，保证 全天 = 白天 + 晚上 + 时段外 恒等。
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class Biz
{
    /** 时段常量 */
    public const SEG_DAY   = 'day';
    public const SEG_NIGHT = 'night';
    public const SEG_GAP   = 'gap';

    public static function segLabel(string $seg): string
    {
        return [
            self::SEG_DAY   => '白天',
            self::SEG_NIGHT => '晚上',
            self::SEG_GAP   => '时段外',
        ][$seg] ?? $seg;
    }

    // ------------------------------------------------------------------
    // 日期范围
    // ------------------------------------------------------------------

    /**
     * 把用户选的营业日范围换算成实际的时间戳区间。
     *
     * 返回 [$from, $to]，查询条件恒为  时间 >= $from AND 时间 < $to，
     * 这样不对索引列做任何函数运算，可以吃满 order_start_time / order_time 索引。
     *
     * 例：2026-08-01 ~ 2026-08-03
     *     => from = 2026-08-01 08:00:00
     *        to   = 2026-08-04 02:00:00   （8/3 晚市延续到 8/4 凌晨 2 点）
     */
    public static function range(string $startDate, string $endDate): array
    {
        $c = Db::config();
        $from = $startDate . ' ' . $c['day_start'];
        $to   = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' ' . $c['night_end'];
        return [$from, $to];
    }

    /** 校验日期范围合法性，返回错误信息；合法返回 null */
    public static function validateRange(string $startDate, string $endDate): ?string
    {
        $s = strtotime($startDate);
        $e = strtotime($endDate);
        if ($s === false || $e === false) {
            return '日期格式不正确';
        }
        if ($e < $s) {
            return '结束日期不能早于开始日期';
        }
        $max  = (int) Db::config()['max_range_days'];
        $days = (int) round(($e - $s) / 86400) + 1;
        if ($days > $max) {
            return "日期跨度不能超过 {$max} 天（约 3 个月），当前选择了 {$days} 天";
        }
        return null;
    }

    // ------------------------------------------------------------------
    // SQL 片段
    // ------------------------------------------------------------------

    /** 营业日表达式：凌晨 2 点前归前一天 */
    private static function bizDateExpr(string $col): string
    {
        $h = (int) Db::config()['day_cut_hour'];
        return "DATE({$col} - INTERVAL {$h} HOUR)";
    }

    /** 时段表达式：返回 'day' / 'night' / 'gap' */
    private static function segExpr(string $col): string
    {
        $c  = Db::config();
        $ds = $c['day_start'];
        $de = $c['day_end'];
        $ns = $c['night_start'];
        $ne = $c['night_end'];
        return "CASE
                  WHEN TIME({$col}) >= '{$ds}' AND TIME({$col}) < '{$de}' THEN 'day'
                  WHEN TIME({$col}) >= '{$ns}' OR  TIME({$col}) < '{$ne}' THEN 'night'
                  ELSE 'gap'
                END";
    }

    // ------------------------------------------------------------------
    // 一、营业额 / 人数统计  —— 只碰账单头表
    // ------------------------------------------------------------------

    /**
     * 按营业日 + 时段汇总营业额与人数。
     *
     * 关键点：同一张单被拆成多个 check_id 时，每个 check 都重复写了相同的
     * customer_num（真实数据实测会导致人数虚高 12.1%）。因此内层子查询先
     * 按 order_head_id 归并：人数取 MAX，金额取 SUM，时间取 MIN。
     *
     * @param string $table  history_order_head 或 order_head
     * @param array  $opts   eat_type => null|int, exclude_zero => bool
     * @return array 每行 [biz_date, seg, checks, guests, actual, original, discount,
     *                     service, tax, should, ret]
     */
    public static function salesByDay(string $from, string $to, string $table, array $opts = []): array
    {
        [$sql, $params] = self::buildSalesSql($from, $to, $table, $opts);
        return Db::select($sql, $params);
    }

    /** 构造营业额统计 SQL。独立出来便于单独校验，不访问数据库。 */
    public static function buildSalesSql(string $from, string $to, string $table, array $opts = []): array
    {
        $table = self::safeTable($table, ['history_order_head', 'order_head']);

        $where  = ['order_start_time >= :from', 'order_start_time < :to'];
        $params = [':from' => $from, ':to' => $to];

        if (isset($opts['eat_type']) && $opts['eat_type'] !== '') {
            $where[] = 'eat_type = :eat_type';
            $params[':eat_type'] = (int) $opts['eat_type'];
        }
        $whereSql = implode(' AND ', $where);

        // 内层：按订单归并，消除分单导致的人数重复
        $inner = "SELECT order_head_id,
                         MIN(order_start_time)  AS t0,
                         MAX(customer_num)      AS guests,
                         SUM(actual_amount)     AS actual,
                         SUM(original_amount)   AS original,
                         SUM(discount_amount)   AS discount,
                         SUM(service_amount)    AS service,
                         SUM(tax_amount)        AS tax,
                         SUM(should_amount)     AS should_amt,
                         SUM(return_amount)     AS ret
                  FROM {$table}
                  WHERE {$whereSql}
                  GROUP BY order_head_id";

        if (!empty($opts['exclude_zero'])) {
            $inner = "SELECT * FROM ({$inner}) z WHERE z.actual <> 0";
        }

        $bizDate = self::bizDateExpr('h.t0');
        $seg     = self::segExpr('h.t0');

        $sql = "SELECT {$bizDate} AS biz_date,
                       {$seg}     AS seg,
                       COUNT(*)          AS checks,
                       SUM(h.guests)     AS guests,
                       SUM(h.actual)     AS actual,
                       SUM(h.original)   AS original,
                       SUM(h.discount)   AS discount,
                       SUM(h.service)    AS service,
                       SUM(h.tax)        AS tax,
                       SUM(h.should_amt) AS should_amt,
                       SUM(h.ret)        AS ret
                FROM ({$inner}) h
                GROUP BY biz_date, seg
                ORDER BY biz_date, seg";

        return [$sql, $params];
    }

    // ------------------------------------------------------------------
    // 二、菜品点单统计  —— 只碰明细表
    // ------------------------------------------------------------------

    /**
     * 明细表的公共过滤条件。
     *
     * 真实数据里明细表混着大量非菜品行，必须全部剔除：
     *   menu_item_id = -3  →  "**999 Enviado 19:16**" 送厨房分隔标记
     *   menu_item_id = -4  →  "EFECTIVO" 付款方式行
     *   quantity     = 0   →  上述标记行
     *   is_return_item     →  退菜
     *   condiment_belong_item <> 0 → 套餐子项 / 做法子项（实测为负数，如 -77）
     *
     * 注意：is_send 在本系统里是"已送厨房"而不是"赠送"，84% 的正常菜品
     * 都是 1，绝对不能拿它当赠送过滤，否则会把菜全部滤光。
     */
    private static function detailFilter(array $opts, array &$where): void
    {
        $where[] = 'menu_item_id > 0';
        $where[] = 'quantity > 0';
        $where[] = '(is_return_item IS NULL OR is_return_item = 0)';
        if (empty($opts['include_combo_child'])) {
            $where[] = 'COALESCE(condiment_belong_item, 0) = 0';
        }
    }

    /**
     * 统计范围内每个菜品的点单量（按时段拆分）。
     *
     * 一次查询拿到全部菜品 × 时段的汇总（约 600 菜 × 3 时段 ≈ 1800 行），
     * Top10 / Bottom10 / 各岗位排行全部在 PHP 层从这份结果算出，
     * 不再回数据库，也不与 menu_item 做 JOIN。
     *
     * @param string $table history_order_detail 或 order_detail
     * @return array 每行 [menu_item_id, item_name, seg, qty, times, amount]
     */
    public static function dishTotals(string $from, string $to, string $table, array $opts = []): array
    {
        [$sql, $params] = self::buildDishTotalsSql($from, $to, $table, $opts);
        return Db::select($sql, $params);
    }

    /** 构造菜品汇总 SQL。独立出来便于单独校验，不访问数据库。 */
    public static function buildDishTotalsSql(string $from, string $to, string $table, array $opts = []): array
    {
        $table = self::safeTable($table, ['history_order_detail', 'order_detail']);

        $where  = ['order_time >= :from', 'order_time < :to'];
        $params = [':from' => $from, ':to' => $to];
        self::detailFilter($opts, $where);

        $seg      = self::segExpr('order_time');
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT menu_item_id,
                       MAX(menu_item_name)             AS item_name,
                       {$seg}                          AS seg,
                       SUM(quantity)                   AS qty,
                       COUNT(*)                        AS times,
                       SUM(actual_price * quantity)    AS amount
                FROM {$table}
                WHERE {$whereSql}
                GROUP BY menu_item_id, seg";

        return [$sql, $params];
    }

    /**
     * 单个菜品在范围内按营业日 + 时段的点单明细。
     *
     * @return array 每行 [biz_date, seg, qty, times, amount]
     */
    public static function dishByDay(string $from, string $to, string $table, int $itemId, array $opts = []): array
    {
        [$sql, $params] = self::buildDishByDaySql($from, $to, $table, $itemId, $opts);
        return Db::select($sql, $params);
    }

    /** 构造单菜品统计 SQL。独立出来便于单独校验，不访问数据库。 */
    public static function buildDishByDaySql(string $from, string $to, string $table, int $itemId, array $opts = []): array
    {
        $table = self::safeTable($table, ['history_order_detail', 'order_detail']);

        $where  = ['order_time >= :from', 'order_time < :to', 'menu_item_id = :item'];
        $params = [':from' => $from, ':to' => $to, ':item' => $itemId];
        self::detailFilter($opts, $where);

        $bizDate  = self::bizDateExpr('order_time');
        $seg      = self::segExpr('order_time');
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT {$bizDate} AS biz_date,
                       {$seg}     AS seg,
                       SUM(quantity)                AS qty,
                       COUNT(*)                     AS times,
                       SUM(actual_price * quantity) AS amount
                FROM {$table}
                WHERE {$whereSql}
                GROUP BY biz_date, seg
                ORDER BY biz_date, seg";

        return [$sql, $params];
    }

    // ------------------------------------------------------------------
    // 二之二、岗位（打印机）单量统计  —— 仍然只碰明细表
    // ------------------------------------------------------------------

    /** 岗位分类里表示「菜品在字典里但没配岗位」和「菜品已从字典删除」 */
    public const PC_NONE    = -1;
    public const PC_UNKNOWN = -2;

    /**
     * 按岗位统计单量。
     *
     * 难点：明细表自带的 print_class 字段实测恒为 0/NULL 不可用，岗位只能来自
     * menu_item.print_class。但「单量」是 COUNT(DISTINCT order_head_id)，
     * 按菜品分别统计再相加会重复计（同一张单点了同岗位两个菜就算两次），
     * 必须在数据库端按岗位分组算。
     *
     * 做法：把「菜品ID → 岗位」映射编译成 SQL 里的 CASE ... WHEN IN (...) 表达式。
     * 666 个菜品分成十几个 IN 列表，SQL 文本几 KB，MySQL 处理 IN 列表很快。
     * 这样既只查明细表一张表、不做 JOIN，又只返回十几行汇总结果，
     * 不需要把上万行明细拉回 PHP。
     *
     * 「单量」= COUNT(DISTINCT order_head_id)，即该岗位出品涉及了多少张单（多少桌）。
     * 一张单里同岗位点了几个菜也只算一单。
     *
     * @param array $pcOfItem  item_id => print_class|null（来自 menuItems()）
     * @return array 每行 [pc, seg, orders, items, qty, lines_cnt, amount]
     */
    public static function stationVolume(string $from, string $to, string $table,
                                         array $pcOfItem, array $opts = []): array
    {
        [$sql, $params] = self::buildStationSql($from, $to, $table, $pcOfItem, $opts);
        return Db::select($sql, $params);
    }

    /** 构造岗位单量 SQL。独立出来便于单独校验，不访问数据库。 */
    public static function buildStationSql(string $from, string $to, string $table,
                                           array $pcOfItem, array $opts = []): array
    {
        $table = self::safeTable($table, ['history_order_detail', 'order_detail']);

        // 按岗位把菜品 ID 归堆。所有 ID 强制转成整数后拼进 SQL，不可能带入非数字内容。
        $byPc = [];
        foreach ($pcOfItem as $itemId => $pc) {
            $id = (int) $itemId;
            if ($id <= 0) {
                continue;
            }
            $key = $pc === null ? self::PC_NONE : (int) $pc;
            $byPc[$key][] = $id;
        }

        $cases = '';
        foreach ($byPc as $pc => $ids) {
            $cases .= ' WHEN menu_item_id IN (' . implode(',', $ids) . ') THEN ' . (int) $pc;
        }
        // 字典里查不到的菜品（多半是后来被删掉的）单独归一类，不和「未配岗位」混淆
        $pcExpr = $cases === ''
            ? (string) self::PC_UNKNOWN
            : 'CASE' . $cases . ' ELSE ' . self::PC_UNKNOWN . ' END';

        $where  = ['order_time >= :from', 'order_time < :to'];
        $params = [':from' => $from, ':to' => $to];
        self::detailFilter($opts, $where);
        $whereSql = implode(' AND ', $where);
        $seg      = self::segExpr('order_time');

        $sql = "SELECT {$pcExpr} AS pc,
                       {$seg}    AS seg,
                       COUNT(DISTINCT order_head_id) AS orders,
                       COUNT(DISTINCT menu_item_id)  AS items,
                       SUM(quantity)                 AS qty,
                       COUNT(*)                      AS lines_cnt,
                       SUM(actual_price * quantity)  AS amount
                FROM {$table}
                WHERE {$whereSql}
                GROUP BY pc, seg";

        return [$sql, $params];
    }

    // ------------------------------------------------------------------
    // 二之三、开台核对  —— 两张实时表，各查一次
    // ------------------------------------------------------------------

    /**
     * 当前已开台的订单（实时表 order_head）。
     *
     * 「未结算」判定为 order_end_time IS NULL。同样按 order_head_id 归并，
     * 因为分单时每个 check 都重复写了相同的 customer_num。
     *
     * @param bool $onlyOpen false 时列出实时表里的全部订单（含已结算未日结的），
     *                       用于核实「未结算 = order_end_time IS NULL」这个假设是否成立
     * @return array 每行 [order_head_id, t0, guests, table_name, employee,
     *                     amount, checks, eat_type, status, settled]
     */
    public static function openTables(bool $onlyOpen = true): array
    {
        [$sql, $params] = self::buildOpenTablesSql($onlyOpen);
        return Db::select($sql, $params);
    }

    /** 构造开台列表 SQL。独立出来便于单独校验，不访问数据库。 */
    public static function buildOpenTablesSql(bool $onlyOpen = true): array
    {
        $where = $onlyOpen ? 'WHERE order_end_time IS NULL' : '';

        $sql = "SELECT order_head_id,
                       MIN(order_start_time)              AS t0,
                       MAX(customer_num)                  AS guests,
                       MAX(table_name)                    AS table_name,
                       MAX(open_employee_name)            AS employee,
                       SUM(actual_amount)                 AS amount,
                       COUNT(*)                           AS checks,
                       MAX(eat_type)                      AS eat_type,
                       MAX(status)                        AS status,
                       MAX(order_end_time IS NOT NULL)    AS settled
                FROM order_head
                {$where}
                GROUP BY order_head_id
                ORDER BY t0";

        return [$sql, []];
    }

    /**
     * 这些订单各点了多少份套餐、多少酒水、多少菜（实时表 order_detail）。
     *
     * 一次查询同时算出套餐份数、酒水份数与全部菜品数 —— 都用 SUM(CASE WHEN ...) 区分，
     * 不需要为套餐或酒水各跑一遍。多加一个 SUM 不增加扫描量。
     *
     * 注意 order_detail 只有主键、没有 order_head_id 索引，这条查询会全表扫描。
     * 但只扫一次，且实时表通常只装当前未日结的数据，可以接受。
     *
     * @param int[] $orderIds  要查的订单号
     * @param int[] $comboIds  算作「按人头套餐」的菜品 ID
     * @param int[] $drinkIds  算作「酒水」的菜品 ID
     * @return array 每行 [order_head_id, combo_qty, drink_qty, drink_amount, dish_qty, lines_cnt]
     */
    public static function orderComboCounts(array $orderIds, array $comboIds, array $drinkIds = []): array
    {
        if (!$orderIds) {
            return [];
        }
        [$sql, $params] = self::buildComboCountSql($orderIds, $comboIds, $drinkIds);
        return Db::select($sql, $params);
    }

    /** 构造套餐/酒水份数 SQL。所有 ID 强制转整数后拼接，不可能带入非数字内容。 */
    public static function buildComboCountSql(array $orderIds, array $comboIds, array $drinkIds = []): array
    {
        $ids = [];
        foreach ($orderIds as $v) {
            $n = (int) $v;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        if (!$ids) {
            throw new InvalidArgumentException('订单号清单为空');
        }
        $orderIn = implode(',', array_keys($ids));

        // 菜品 ID 清单编译成 IN 列表；空清单时该项恒为 0，SQL 仍然合法
        $compile = static function (array $ids): string {
            $out = [];
            foreach ($ids as $v) {
                $n = (int) $v;
                if ($n > 0) {
                    $out[$n] = true;
                }
            }
            return implode(',', array_keys($out));
        };

        $comboIn = $compile($comboIds);
        $drinkIn = $compile($drinkIds);

        $comboExpr = $comboIn !== ''
            ? "SUM(CASE WHEN menu_item_id IN ({$comboIn}) THEN quantity ELSE 0 END)" : '0';
        $drinkExpr = $drinkIn !== ''
            ? "SUM(CASE WHEN menu_item_id IN ({$drinkIn}) THEN quantity ELSE 0 END)" : '0';
        $drinkAmt  = $drinkIn !== ''
            ? "SUM(CASE WHEN menu_item_id IN ({$drinkIn}) THEN actual_price * quantity ELSE 0 END)" : '0';

        $sql = "SELECT order_head_id,
                       {$comboExpr}  AS combo_qty,
                       {$drinkExpr}  AS drink_qty,
                       {$drinkAmt}   AS drink_amount,
                       SUM(quantity) AS dish_qty,
                       COUNT(*)      AS lines_cnt
                FROM order_detail
                WHERE order_head_id IN ({$orderIn})
                  AND menu_item_id > 0
                  AND quantity > 0
                  AND (is_return_item IS NULL OR is_return_item = 0)
                  AND COALESCE(condiment_belong_item, 0) = 0
                GROUP BY order_head_id";

        return [$sql, []];
    }

    // ------------------------------------------------------------------
    // 三、字典表  —— 单独查询，内存映射
    // ------------------------------------------------------------------

    /**
     * 菜品字典。item_type = 1 的是做法/口味项（如 "S/Pepino" 不要黄瓜），
     * 不是真正的菜，统计时排除。
     *
     * @return array item_id => ['name'=>..., 'print_class'=>..., 'is_condiment'=>bool, 'price'=>float]
     */
    public static function menuItems(): array
    {
        $rows = Db::select(
            'SELECT item_id, item_name1, item_name2, print_class, item_type, price_1
             FROM menu_item ORDER BY item_id'
        );
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['item_name1']);
            if ($name === '') {
                $name = trim((string) $r['item_name2']);
            }
            $out[(int) $r['item_id']] = [
                'name'         => $name !== '' ? $name : ('#' . $r['item_id']),
                'name2'        => trim((string) $r['item_name2']),
                'print_class'  => $r['print_class'] === null ? null : (int) $r['print_class'],
                'is_condiment' => ((int) $r['item_type']) === 1,
                'price'        => (float) ($r['price_1'] ?? 0),
            ];
        }
        return $out;
    }

    /** 岗位字典：print_class_id => name */
    public static function printClasses(): array
    {
        $rows = Db::select('SELECT print_class_id, print_class_name FROM print_class ORDER BY print_class_id');
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['print_class_id']] = (string) $r['print_class_name'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /** 表名白名单校验，杜绝表名被外部输入影响 */
    private static function safeTable(string $table, array $allowed): string
    {
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException("非法的表名: {$table}");
        }
        return $table;
    }

    /**
     * 是否需要顺带查询未日结的实时表。
     * 实时表存放当天尚未做日结的数据，只有查询范围覆盖到最近才有意义。
     */
    public static function needLiveTables(string $to): bool
    {
        return strtotime($to) > time() - 2 * 86400;
    }
}
