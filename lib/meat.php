<?php
/**
 * 肉类采购记录 —— 业务逻辑
 *
 * 数据存在自有的 SQLite 里（见 lib/store.php），与 POS 主库完全无关。
 * 要和营业额一起统计时，两边各查各的，在 PHP 内存里按日期合并 —— 不许 JOIN。
 *
 * 录入时机有三种，都要支持：
 *   1. 到货当天就录（那时只有条数，没有公斤和价格）
 *   2. 发票到了再录（公斤、价格齐了）
 *   3. 月底一起补录（日期要能往回填）
 * 所以「重量」和「价格」都是选填，但重量和件数【至少要有一个】。
 * 缺重量或缺总价的记录会被列为「待补发票」，月底补填时不会漏。
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/db.php';

final class Meat
{
    /** 单位：条 / 包 */
    public const UNIT_PIECE = 'piece';
    public const UNIT_PACK  = 'pack';

    /** 单价口径：每公斤 / 每件 */
    public const BASIS_KG   = 'kg';
    public const BASIS_UNIT = 'unit';

    public static function unitLabel(?string $u): string
    {
        return [self::UNIT_PIECE => '条', self::UNIT_PACK => '包'][(string) $u] ?? '';
    }

    public static function basisLabel(?string $b): string
    {
        return [self::BASIS_KG => '每公斤', self::BASIS_UNIT => '每件'][(string) $b] ?? '';
    }

    /** 品类清单：代码 => 显示名。可在 config 里改，换品类不用动代码。 */
    public static function kinds(): array
    {
        $k = (array) (Db::config()['meat_kinds'] ?? []);
        $out = [];
        foreach ($k as $code => $name) {
            $code = trim((string) $code);
            if ($code !== '') {
                $out[$code] = trim((string) $name) !== '' ? (string) $name : $code;
            }
        }
        return $out;
    }

    public static function kindLabel(string $code): string
    {
        return self::kinds()[$code] ?? $code;
    }

    /** 供应商备选（只是下拉提示，可以自己输别的） */
    public static function suppliers(): array
    {
        return array_values(array_filter(array_map(
            static fn($v) => trim((string) $v),
            (array) (Db::config()['meat_suppliers'] ?? [])), static fn($v) => $v !== ''));
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

        // ---- 日期：必填。要允许往回填（月底补录），所以不限制不能早于今天 ----
        $date = trim((string) ($in['purchase_date'] ?? ''));
        $ts   = $date === '' ? false : strtotime($date);
        if ($date === '') {
            $e['purchase_date'] = '请填日期';
        } elseif ($ts === false) {
            $e['purchase_date'] = '日期格式不对';
        } elseif ($ts > strtotime('+1 day')) {
            // 补录是往回填，往后填基本是手滑（比如年份打错）
            $e['purchase_date'] = '日期在未来，请检查是不是填错了';
        } else {
            $c['purchase_date'] = date('Y-m-d', $ts);
        }

        // ---- 品类：必填，且必须在清单里 ----
        $kind = trim((string) ($in['kind'] ?? ''));
        if ($kind === '') {
            $e['kind'] = '请选品类';
        } elseif (!isset(self::kinds()[$kind])) {
            $e['kind'] = '这个品类不在清单里';
        } else {
            $c['kind'] = $kind;
        }

        // ---- 重量、件数：至少填一个 ----
        $w = self::num($in['weight_kg']  ?? null);
        $n = self::num($in['unit_count'] ?? null);
        if ($w !== null && $w <= 0) {
            $e['weight_kg'] = '重量要大于 0';
            $w = null;
        }
        if ($n !== null && $n <= 0) {
            $e['unit_count'] = '件数要大于 0';
            $n = null;
        }
        if ($w === null && $n === null && !isset($e['weight_kg']) && !isset($e['unit_count'])) {
            // 到货时可能只知道条数，发票上才有公斤 —— 所以两者都选填，
            // 但不能都空，否则这条记录等于什么都没记
            $e['weight_kg'] = '重量和件数至少要填一个';
        }
        $c['weight_kg']  = $w;
        $c['unit_count'] = $n;

        // ---- 单位：填了件数就必须说是条还是包 ----
        $unit = trim((string) ($in['unit_type'] ?? ''));
        if ($n !== null) {
            if (!in_array($unit, [self::UNIT_PIECE, self::UNIT_PACK], true)) {
                $e['unit_type'] = '请选「条」还是「包」';
            } else {
                $c['unit_type'] = $unit;
            }
        } else {
            $c['unit_type'] = null;      // 没填件数就没有单位
        }

        // ---- 价格：全部选填 ----
        $up = self::num($in['unit_price']  ?? null);
        $tp = self::num($in['total_price'] ?? null);
        if ($up !== null && $up < 0) { $e['unit_price']  = '单价不能是负数'; $up = null; }
        if ($tp !== null && $tp < 0) { $e['total_price'] = '总价不能是负数'; $tp = null; }
        $c['unit_price']  = $up;
        $c['total_price'] = $tp;

        // ---- 单价口径：填了单价就必须说清是按公斤还是按件 ----
        $basis = trim((string) ($in['price_basis'] ?? ''));
        if ($up !== null) {
            if (!in_array($basis, [self::BASIS_KG, self::BASIS_UNIT], true)) {
                $e['price_basis'] = '请选单价是「每公斤」还是「每件」';
            } elseif ($basis === self::BASIS_KG && $w === null) {
                $e['price_basis'] = '按公斤计价就得填重量，否则算不出总价';
            } elseif ($basis === self::BASIS_UNIT && $n === null) {
                $e['price_basis'] = '按件计价就得填件数，否则算不出总价';
            } else {
                $c['price_basis'] = $basis;
            }
        } else {
            $c['price_basis'] = null;
        }

        $c['supplier'] = self::text($in['supplier'] ?? null, 60);
        $c['note']     = self::text($in['note'] ?? null, 200);

        return [$c, $e];
    }

    /** 空字符串当作「没填」，而不是 0 —— 0 公斤和没填公斤是两回事 */
    private static function num($v): ?float
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = str_replace([' ', ','], ['', '.'], $s);   // 允许写成 1,5（西语习惯）
        return is_numeric($s) ? (float) $s : null;
    }

    private static function text($v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        // 不用 mb_substr —— 本程序不依赖 mbstring
        return preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s);
    }

    /** 单价 × 数量算出来的总价（用于和手填的总价对一对） */
    public static function derivedTotal(array $r): ?float
    {
        $up = $r['unit_price'] ?? null;
        if ($up === null) {
            return null;
        }
        if (($r['price_basis'] ?? null) === self::BASIS_KG && ($r['weight_kg'] ?? null) !== null) {
            return (float) $up * (float) $r['weight_kg'];
        }
        if (($r['price_basis'] ?? null) === self::BASIS_UNIT && ($r['unit_count'] ?? null) !== null) {
            return (float) $up * (float) $r['unit_count'];
        }
        return null;
    }

    /** 这条记录还缺发票信息吗（缺重量或缺总价） */
    public static function needsInvoice(array $r): bool
    {
        return ($r['weight_kg'] ?? null) === null || ($r['total_price'] ?? null) === null;
    }

    // ------------------------------------------------------------------
    // 增删改
    // ------------------------------------------------------------------

    /** 只接受 validate() 出来的干净数据；直接塞原始表单会被挡住 */
    private static function assertClean(array $c): void
    {
        foreach (['purchase_date', 'kind'] as $k) {
            if (($c[$k] ?? null) === null || $c[$k] === '') {
                throw new InvalidArgumentException(
                    "缺少必填字段 {$k} —— 请先过 Meat::validate() 并检查它返回的错误");
            }
        }
        if (($c['weight_kg'] ?? null) === null && ($c['unit_count'] ?? null) === null) {
            throw new InvalidArgumentException('重量和件数至少要有一个');
        }
    }

    public static function create(array $clean): int
    {
        self::assertClean($clean);
        return Store::transaction(static function () use ($clean) {
            $now = date('Y-m-d H:i:s');
            $id = Store::insert(
                'INSERT INTO meat_purchase
                   (purchase_date, kind, weight_kg, unit_count, unit_type,
                    price_basis, unit_price, total_price, supplier, note,
                    created_at, updated_at)
                 VALUES (:d, :k, :w, :n, :u, :b, :up, :tp, :s, :note, :c, :m)',
                [':d' => $clean['purchase_date'], ':k' => $clean['kind'],
                 ':w' => $clean['weight_kg'], ':n' => $clean['unit_count'],
                 ':u' => $clean['unit_type'], ':b' => $clean['price_basis'],
                 ':up' => $clean['unit_price'], ':tp' => $clean['total_price'],
                 ':s' => $clean['supplier'], ':note' => $clean['note'],
                 ':c' => $now, ':m' => $now]
            );
            Store::log($id, 'create', null, $clean);
            return $id;
        });
    }

    public static function update(int $id, array $clean): bool
    {
        self::assertClean($clean);
        return Store::transaction(static function () use ($id, $clean) {
            $before = self::find($id);
            if ($before === null) {
                return false;
            }
            Store::run(
                'UPDATE meat_purchase SET
                    purchase_date = :d, kind = :k, weight_kg = :w, unit_count = :n,
                    unit_type = :u, price_basis = :b, unit_price = :up,
                    total_price = :tp, supplier = :s, note = :note, updated_at = :m
                 WHERE id = :id',
                [':d' => $clean['purchase_date'], ':k' => $clean['kind'],
                 ':w' => $clean['weight_kg'], ':n' => $clean['unit_count'],
                 ':u' => $clean['unit_type'], ':b' => $clean['price_basis'],
                 ':up' => $clean['unit_price'], ':tp' => $clean['total_price'],
                 ':s' => $clean['supplier'], ':note' => $clean['note'],
                 ':m' => date('Y-m-d H:i:s'), ':id' => $id]
            );
            Store::log($id, 'update', $before, $clean);
            return true;
        });
    }

    /** 软删除：标记作废，数据还在，随时能恢复 */
    public static function softDelete(int $id): bool
    {
        return Store::transaction(static function () use ($id) {
            $before = self::find($id);
            if ($before === null || $before['deleted_at'] !== null) {
                return false;
            }
            Store::run('UPDATE meat_purchase SET deleted_at = :t, updated_at = :t WHERE id = :id',
                       [':t' => date('Y-m-d H:i:s'), ':id' => $id]);
            Store::log($id, 'delete', $before, null);
            return true;
        });
    }

    public static function restore(int $id): bool
    {
        return Store::transaction(static function () use ($id) {
            $before = self::find($id);
            if ($before === null || $before['deleted_at'] === null) {
                return false;
            }
            Store::run('UPDATE meat_purchase SET deleted_at = NULL, updated_at = :t WHERE id = :id',
                       [':t' => date('Y-m-d H:i:s'), ':id' => $id]);
            Store::log($id, 'restore', $before, null);
            return true;
        });
    }

    public static function find(int $id): ?array
    {
        return Store::selectOne('SELECT * FROM meat_purchase WHERE id = :id', [':id' => $id]);
    }

    public static function history(int $id): array
    {
        return Store::select(
            'SELECT * FROM meat_purchase_log WHERE row_id = :id ORDER BY id DESC LIMIT 50',
            [':id' => $id]
        );
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /**
     * 列表。
     *
     * @param array $f 筛选：from / to / kind / supplier / only_pending / with_deleted
     */
    public static function listRows(array $f = []): array
    {
        $w = [];
        $p = [];
        if (empty($f['with_deleted'])) {
            $w[] = 'deleted_at IS NULL';
        }
        if (!empty($f['from'])) { $w[] = 'purchase_date >= :from'; $p[':from'] = $f['from']; }
        if (!empty($f['to']))   { $w[] = 'purchase_date <= :to';   $p[':to']   = $f['to']; }
        if (!empty($f['kind'])) { $w[] = 'kind = :kind';           $p[':kind'] = $f['kind']; }
        if (!empty($f['supplier'])) { $w[] = 'supplier = :sup';    $p[':sup']  = $f['supplier']; }
        if (!empty($f['only_pending'])) {
            $w[] = '(weight_kg IS NULL OR total_price IS NULL)';
        }
        $sql = 'SELECT * FROM meat_purchase'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY purchase_date DESC, id DESC LIMIT 500';
        return Store::select($sql, $p);
    }

    /**
     * 各品类的平均条重 —— 只用【重量和件数都填了】的记录算。
     *
     * 用中位数不用平均数：进货偶尔会有特别大或特别小的一批，
     * 平均数会被带跑，中位数不会。
     *
     * 同时给出样本数和范围，让人能判断这个系数可不可信 ——
     * 页面上会把它显示出来，而不是闷头拿去折算。
     *
     * @return array kind => ['unit'=>条/包, 'per'=>中位数, 'n'=>样本数,
     *                        'min'=>最小, 'max'=>最大]
     */
    public static function unitWeights(?string $before = null): array
    {
        $w = ['weight_kg IS NOT NULL', 'unit_count IS NOT NULL', 'unit_count > 0',
              'unit_type IS NOT NULL', 'deleted_at IS NULL'];
        $p = [];
        if ($before !== null) {
            $w[] = 'purchase_date <= :b';
            $p[':b'] = $before;
        }
        $rows = Store::select(
            'SELECT kind, unit_type, weight_kg / unit_count AS per
             FROM meat_purchase WHERE ' . implode(' AND ', $w), $p);

        $by = [];
        foreach ($rows as $r) {
            $by[$r['kind'] . '|' . $r['unit_type']][] = (float) $r['per'];
        }
        $out = [];
        foreach ($by as $key => $vals) {
            sort($vals);
            [$kind, $unit] = explode('|', $key, 2);
            $n = count($vals);
            $out[$kind] = [
                'unit' => $unit,
                'per'  => $n % 2 ? $vals[intdiv($n, 2)]
                                 : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2,
                'n'    => $n,
                'min'  => $vals[0],
                'max'  => $vals[$n - 1],
            ];
        }
        return $out;
    }

    /** 样本少于这个数就不估算 —— 两三条数据算出来的系数没有意义 */
    public const MIN_SAMPLES = 3;

    /**
     * 给一条记录算出「用于统计的重量」。
     *
     * 返回 [重量, 是否估算]。缺重量且样本够时按平均条重折算，
     * 并标成估算；样本不够就返回 null，宁可报「缺重量」也不编数字。
     */
    public static function statWeight(array $r, array $unitWeights): array
    {
        if (($r['weight_kg'] ?? null) !== null) {
            return [(float) $r['weight_kg'], false];
        }
        $k = (string) ($r['kind'] ?? '');
        $u = $unitWeights[$k] ?? null;
        if ($u === null || $u['n'] < self::MIN_SAMPLES
            || ($r['unit_count'] ?? null) === null
            || ($r['unit_type'] ?? null) !== $u['unit']) {
            return [null, false];
        }
        return [(float) $r['unit_count'] * $u['per'], true];
    }
}
