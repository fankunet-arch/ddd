<?php
/**
 * 库存 —— 业务逻辑
 *
 * 数据存在自有的 SQLite 里（见 lib/store.php），与 POS 主库完全无关。
 *
 * ============================================================
 *  只记两种动作，不记取出
 * ============================================================
 *
 *   存入   把东西放进库里（原本 4 箱，今天存入 3 箱 → 账面 7 箱）
 *   盘点   实际数一遍，是个【绝对数】（今天数出 5 箱）
 *
 *   取出量 = 上次盘点结存 + 期间存入 − 本次盘点结存
 *          = 4 + 3 − 5 = 2 箱
 *
 *   取出即已使用。少记一种动作，员工少填一半，而且「数出来多少」比
 *   「拿走了多少」可靠得多 —— 后者忙起来一定会漏记，前者是眼见为实。
 *
 * ============================================================
 *  为什么库存品类和采购品类是两份清单
 * ============================================================
 *  采购买的是「一条三文鱼」；进冰箱前要分割处理，出来的是
 *  鱼条、黑皮、鱼沫、中线 —— 盘点数的是后面这些。
 *  数量对不上（一条鱼出多少箱不固定），名字也对不上。
 *  所以两本账各记各的，程序里【不做对应】，也不要去加。
 *
 * ============================================================
 *  为什么精确到分钟
 * ============================================================
 *  一天可能盘好几次：到店（= 今天的开始量，也是昨天的结束量）、
 *  午市后、晚市前、晚市后。只记日期的话同一天的几次排不出先后，
 *  用量就只能摊到一整天，分不到餐期上。
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/db.php';

final class Stock
{
    /** 动作：存入 / 盘点 */
    public const IN    = 'in';
    public const COUNT = 'count';

    public static function moveLabel(?string $k): string
    {
        return [self::IN => '存入', self::COUNT => '盘点'][(string) $k] ?? '';
    }

    /**
     * 库存品类清单：代码 => ['name' => 显示名, 'unit' => 单位]
     *
     * 单位每个品类各自固定，录入时不用选 —— 同一个品类这次填箱、下次填盒的话，
     * 前后两次盘点就减不出来了。单位只用于显示，不做任何换算。
     */
    public static function items(): array
    {
        $out = [];
        foreach ((array) (Db::config()['stock_items'] ?? []) as $code => $v) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            // 允许简写成 '代码' => '名称'（那就没有单位）
            $name = is_array($v) ? trim((string) ($v['name'] ?? '')) : trim((string) $v);
            $unit = is_array($v) ? trim((string) ($v['unit'] ?? '')) : '';
            $out[$code] = ['name' => $name !== '' ? $name : $code, 'unit' => $unit];
        }
        return $out;
    }

    public static function itemLabel(string $code): string
    {
        return self::items()[$code]['name'] ?? $code;
    }

    public static function itemUnit(string $code): string
    {
        return self::items()[$code]['unit'] ?? '';
    }

    /** 盘点时点：代码 => ['name' => 显示名, 'time' => 'HH:MM'] */
    public static function moments(): array
    {
        $out = [];
        foreach ((array) (Db::config()['stock_moments'] ?? []) as $code => $v) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $name = is_array($v) ? trim((string) ($v['name'] ?? '')) : trim((string) $v);
            $time = is_array($v) ? trim((string) ($v['time'] ?? '')) : '';
            $out[$code] = ['name' => $name !== '' ? $name : $code,
                           'time' => preg_match('/^\d{1,2}:\d{2}$/', $time) ? $time : ''];
        }
        return $out;
    }

    public static function momentLabel(?string $code): string
    {
        return self::moments()[(string) $code]['name'] ?? '';
    }

    // ------------------------------------------------------------------
    // 校验
    // ------------------------------------------------------------------

    /**
     * 校验并清洗一条录入。
     *
     * @return array [$clean, $errors]  $errors 为空表示通过
     */
    public static function validate(array $in): array
    {
        $e = [];
        $c = [];

        // ---- 动作：必填。这是全表最要紧的一个字段 ----
        // 存入和盘点的含义完全相反（一个是加、一个是绝对值），选错会把
        // 整段用量算歪，所以宁可不给默认值，逼着每次明确选。
        $kind = trim((string) ($in['move_kind'] ?? ''));
        if ($kind === '') {
            $e['move_kind'] = '请选「存入」还是「盘点」';
        } elseif ($kind !== self::IN && $kind !== self::COUNT) {
            $e['move_kind'] = '动作只能是存入或盘点';
        } else {
            $c['move_kind'] = $kind;
        }

        // ---- 日期：必填，可以往回补，但不能填未来 ----
        $date = trim((string) ($in['happened_date'] ?? ''));
        $ts   = $date === '' ? false : strtotime($date);
        if ($date === '') {
            $e['happened_date'] = '请填日期';
        } elseif ($ts === false) {
            $e['happened_date'] = '日期格式不对';
        } elseif ($ts > strtotime('+1 day')) {
            $e['happened_date'] = '日期在未来，请检查是不是填错了';
        } else {
            $date = date('Y-m-d', $ts);
        }

        // ---- 时间：必填。一天盘好几次，没有时间就排不出先后 ----
        $time = trim((string) ($in['happened_time'] ?? ''));
        if ($time === '') {
            $e['happened_time'] = '请填时间（一天可能盘好几次，靠时间分先后）';
        } elseif (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)
                  || (int) $m[1] > 23 || (int) $m[2] > 59) {
            $e['happened_time'] = '时间格式不对，写成 14:30 这样';
        } else {
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        if (!isset($e['happened_date']) && !isset($e['happened_time'])) {
            $c['happened_at'] = $date . ' ' . $time;
        }

        // ---- 品类：必填，且必须在清单里 ----
        $item = trim((string) ($in['item'] ?? ''));
        if ($item === '') {
            $e['item'] = '请选品类';
        } elseif (!isset(self::items()[$item])) {
            $e['item'] = '这个品类不在清单里';
        } else {
            $c['item'] = $item;
        }

        // ---- 数量：必填 ----
        $qty = self::num($in['qty'] ?? null);
        if ($qty === null) {
            $e['qty'] = '请填数量';
        } elseif ($qty < 0) {
            $e['qty'] = '数量不能是负数';
        } elseif ($qty > 1000000) {
            $e['qty'] = '这个数看着不对，请检查';
        } elseif ($qty == 0.0 && $kind === self::IN) {
            // 盘点 0 是有意义的（数完发现空了），存入 0 等于什么都没做
            $e['qty'] = '存入量要大于 0（如果是数完发现空了，请选「盘点」填 0）';
        } else {
            $c['qty'] = $qty;
        }

        // ---- 时点：选填，但填了就得在清单里 ----
        $moment = trim((string) ($in['moment'] ?? ''));
        if ($moment !== '' && !isset(self::moments()[$moment])) {
            $e['moment'] = '这个时点不在清单里';
        } else {
            $c['moment'] = $moment !== '' ? $moment : null;
        }

        $note = trim((string) ($in['note'] ?? ''));
        $c['note'] = $note !== '' ? mb_substr($note, 0, 200) : null;

        return [$c, $e];
    }

    /** 数字解析：西语键盘习惯写 12,5，这边一并认 */
    private static function num($v): ?float
    {
        if ($v === null) {
            return null;
        }
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /** 写库前的兜底：只有 validate 通过的数据才能进来 */
    private static function assertClean(array $c): void
    {
        foreach (['happened_at', 'item', 'move_kind', 'qty'] as $k) {
            if (!array_key_exists($k, $c)) {
                throw new InvalidArgumentException("未校验的数据（缺 {$k}），不能写库");
            }
        }
    }

    // ------------------------------------------------------------------
    // 增删改（全部留痕）
    // ------------------------------------------------------------------

    public static function create(array $c): int
    {
        self::assertClean($c);
        $now = date('Y-m-d H:i:s');
        return Store::transaction(static function () use ($c, $now) {
            $id = Store::insert(
                'INSERT INTO stock_move
                   (happened_at, item, move_kind, qty, moment, note, created_at, updated_at)
                 VALUES (:at, :it, :mk, :q, :mo, :nt, :c, :u)',
                [':at' => $c['happened_at'], ':it' => $c['item'], ':mk' => $c['move_kind'],
                 ':q' => $c['qty'], ':mo' => $c['moment'] ?? null, ':nt' => $c['note'] ?? null,
                 ':c' => $now, ':u' => $now]
            );
            Store::log($id, 'create', null, $c, 'stock_move_log');
            return $id;
        });
    }

    public static function update(int $id, array $c): bool
    {
        self::assertClean($c);
        $before = self::find($id);
        if ($before === null) {
            return false;
        }
        return (bool) Store::transaction(static function () use ($id, $c, $before) {
            Store::run(
                'UPDATE stock_move
                    SET happened_at = :at, item = :it, move_kind = :mk, qty = :q,
                        moment = :mo, note = :nt, updated_at = :u
                  WHERE id = :id',
                [':at' => $c['happened_at'], ':it' => $c['item'], ':mk' => $c['move_kind'],
                 ':q' => $c['qty'], ':mo' => $c['moment'] ?? null, ':nt' => $c['note'] ?? null,
                 ':u' => date('Y-m-d H:i:s'), ':id' => $id]
            );
            Store::log($id, 'update', $before, $c, 'stock_move_log');
            return true;
        });
    }

    /** 作废是软删除：数据留着，不计入结存，可以恢复 */
    public static function softDelete(int $id): bool
    {
        $before = self::find($id);
        if ($before === null || $before['deleted_at'] !== null) {
            return false;
        }
        return (bool) Store::transaction(static function () use ($id, $before) {
            Store::run('UPDATE stock_move SET deleted_at = :t, updated_at = :t WHERE id = :id',
                       [':t' => date('Y-m-d H:i:s'), ':id' => $id]);
            Store::log($id, 'delete', $before, null, 'stock_move_log');
            return true;
        });
    }

    public static function restore(int $id): bool
    {
        $before = self::find($id);
        if ($before === null || $before['deleted_at'] === null) {
            return false;
        }
        return (bool) Store::transaction(static function () use ($id, $before) {
            Store::run('UPDATE stock_move SET deleted_at = NULL, updated_at = :t WHERE id = :id',
                       [':t' => date('Y-m-d H:i:s'), ':id' => $id]);
            Store::log($id, 'restore', $before, null, 'stock_move_log');
            return true;
        });
    }

    public static function find(int $id): ?array
    {
        return Store::selectOne('SELECT * FROM stock_move WHERE id = :id', [':id' => $id]);
    }

    public static function history(int $id): array
    {
        return Store::select('SELECT * FROM stock_move_log WHERE row_id = :id ORDER BY id',
                             [':id' => $id]);
    }

    /**
     * 明细列表。
     *
     * 排序按 (happened_at, id) 倒序 —— 同一分钟录了好几条时，
     * 后录的排前面，跟结存推算用的顺序是同一套。
     */
    public static function listRows(array $f = []): array
    {
        $w = [];
        $p = [];
        if (empty($f['with_deleted'])) {
            $w[] = 'deleted_at IS NULL';
        }
        if (!empty($f['from'])) { $w[] = 'happened_at >= :from'; $p[':from'] = $f['from'] . ' 00:00'; }
        if (!empty($f['to']))   { $w[] = 'happened_at <= :to';   $p[':to']   = $f['to'] . ' 23:59'; }
        if (!empty($f['item'])) { $w[] = 'item = :item';         $p[':item'] = $f['item']; }
        if (!empty($f['move_kind'])) { $w[] = 'move_kind = :mk'; $p[':mk']   = $f['move_kind']; }
        $sql = 'SELECT * FROM stock_move'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY happened_at DESC, id DESC LIMIT 500';
        return Store::select($sql, $p);
    }

    /** 某个品类的全部有效流水，按时间正序（结存推算就靠这个顺序） */
    public static function seriesFor(string $item): array
    {
        return Store::select(
            'SELECT * FROM stock_move
              WHERE item = :it AND deleted_at IS NULL
              ORDER BY happened_at, id',
            [':it' => $item]
        );
    }

    // ------------------------------------------------------------------
    // 结存与用量
    // ------------------------------------------------------------------

    /**
     * 把一个品类的流水拆成一段一段。
     *
     * 一「段」= 相邻两次盘点之间。段的用量：
     *
     *     取出（已使用） = 上次盘点结存 + 期间存入 − 本次盘点结存
     *
     * 算出负数说明【漏记了存入】—— 盘出来的比账面还多，东西不会凭空长出来。
     * 这种情况要标出来让人回去补，不能悄悄当 0。
     *
     * @param array $rows seriesFor() 的结果（按时间正序）
     * @return array 每段 ['from','to','from_qty','to_qty','in','used','hours','negative']
     */
    public static function periods(array $rows): array
    {
        $out  = [];
        $prev = null;      // 上一次盘点
        $in   = 0.0;       // 上次盘点以来的存入合计

        foreach ($rows as $r) {
            if ((string) $r['move_kind'] === self::IN) {
                $in += (float) $r['qty'];
                continue;
            }
            // 盘点
            if ($prev !== null) {
                $used = (float) $prev['qty'] + $in - (float) $r['qty'];
                $h = (strtotime((string) $r['happened_at'])
                      - strtotime((string) $prev['happened_at'])) / 3600;
                $out[] = [
                    'from'     => (string) $prev['happened_at'],
                    'to'       => (string) $r['happened_at'],
                    'from_moment' => $prev['moment'] ?? null,
                    'to_moment'   => $r['moment'] ?? null,
                    'from_qty' => (float) $prev['qty'],
                    'to_qty'   => (float) $r['qty'],
                    'in'       => $in,
                    'used'     => $used,
                    'hours'    => $h,
                    'negative' => $used < -0.0001,
                ];
            }
            $prev = $r;
            $in   = 0.0;
        }
        return $out;
    }

    /**
     * 每个品类的当前状态。
     *
     * 说明「账面」这个词：最近一次盘点之后又存入了多少是记着的，
     * 但那之后用掉了多少【没人记】—— 要等下次盘点才知道。
     * 所以账面数是个【上限】，不是真实库存，页面上必须这么写，
     * 不然会被当成「现在冰箱里就是这么多」。
     *
     * @return array 品类代码 => [...]，没有任何记录的品类也会出现（值为 null 的那几项）
     */
    public static function current(): array
    {
        $out = [];
        foreach (self::items() as $code => $meta) {
            $rows    = self::seriesFor($code);
            $periods = self::periods($rows);

            $lastCount = null;
            $sinceIn   = 0.0;
            $seenCount = false;
            foreach ($rows as $r) {
                if ((string) $r['move_kind'] === self::COUNT) {
                    $lastCount = $r;
                    $seenCount = true;
                    $sinceIn   = 0.0;
                } elseif ($seenCount) {
                    $sinceIn += (float) $r['qty'];
                }
            }
            // 一次都没盘过时，只能把存入加起来，那更不是真实库存
            $inTotal = 0.0;
            foreach ($rows as $r) {
                if ((string) $r['move_kind'] === self::IN) {
                    $inTotal += (float) $r['qty'];
                }
            }

            $last = $periods ? $periods[count($periods) - 1] : null;
            $out[$code] = [
                'name'       => $meta['name'],
                'unit'       => $meta['unit'],
                'rows'       => count($rows),
                'counted'    => $lastCount !== null,
                'last_at'    => $lastCount !== null ? (string) $lastCount['happened_at'] : null,
                'last_moment'=> $lastCount !== null ? ($lastCount['moment'] ?? null) : null,
                'last_qty'   => $lastCount !== null ? (float) $lastCount['qty'] : null,
                'since_in'   => $sinceIn,
                'in_total'   => $inTotal,
                // 账面上限 = 最近盘点数 + 之后的存入（不含之后已经用掉的）
                'book'       => $lastCount !== null ? (float) $lastCount['qty'] + $sinceIn : null,
                'stale_h'    => $lastCount !== null
                                ? (time() - strtotime((string) $lastCount['happened_at'])) / 3600
                                : null,
                'last_period'=> $last,
                'periods'    => $periods,
            ];
        }
        return $out;
    }

    /**
     * 一次盘点盘了哪几项、还差哪几项。
     *
     * 录入是一条一条来的（一次盘点要录好几条），最容易出的错是漏一项 ——
     * 漏掉的那项下次盘点时会把两段的用量合成一段，看着就是「某段暴增」。
     * 所以录完一条就把同一时刻的进度显示出来。
     *
     * @return array ['at'=>时刻, 'done'=>[代码…], 'missing'=>[代码…]]|null
     */
    public static function countProgress(string $happenedAt): ?array
    {
        $rows = Store::select(
            "SELECT item FROM stock_move
              WHERE happened_at = :at AND move_kind = 'count' AND deleted_at IS NULL",
            [':at' => $happenedAt]
        );
        if (!$rows) {
            return null;
        }
        $done    = array_column($rows, 'item');
        $missing = array_values(array_diff(array_keys(self::items()), $done));
        return ['at' => $happenedAt, 'done' => $done, 'missing' => $missing];
    }
}
