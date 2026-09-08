<?php
/**
 * 把发票明细表（.xlsx / .csv）读成采购记录 —— 只做解析和核对，不写库。
 *
 * ============================================================
 *  为什么按【表头文字】认列，而不是写死列号
 * ============================================================
 *  两份真实文件的列顺序就不一样：一份是「类别/日期/发票号…」，
 *  另一份多了送货日期和供货商，重量在第 10 列。写死列号，换一份文件就全错位，
 *  而且错位是【静默】的 —— 数字照样进库，只是全填错了字段。
 *  所以按表头关键词认，并且把认到的对应关系显示出来让人核。
 *
 * ============================================================
 *  含税还是未税：必须统一，否则两份文件加起来没有意义
 * ============================================================
 *  Makro 那份的金额是【未税】，另一份给的是【含税】。混着导进去，
 *  「食材成本占营业额」这类比值就是错的，而且看不出来。
 *
 *  本程序统一存【含税】：营业额那边取的是 POS 的实收（价内含税，见铁律六），
 *  两边同口径才比得了。只有未税金额时按税率折算成含税，并在预览里写明。
 *
 * ============================================================
 *  退货行
 * ============================================================
 *  采购表的数量和金额都不允许为负（数据库 CHECK 也拦着）。
 *  退货行一律【跳过并列出来】，不做任何抵扣 —— 悄悄扣掉会让合计对不上发票，
 *  而对不上的时候没人知道是哪里扣的。
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/meat.php';

final class MeatImport
{
    /** 一次最多处理多少数据行 —— 防止有人传进来一个几十万行的表把内存撑爆 */
    public const MAX_ROWS = 5000;

    /**
     * 认列规则：字段 => [命中词…, '!' => [排除词…]]。
     *
     * 排除词不是装饰 —— 第一版没有它，在真实文件上认错了两列：
     *   「含税单价」被当成「含税金额」→ 合计金额从 2 万变成 781
     *   「发票日期」被当成「发票号」  → 备注里写了个日期序号
     * 两处都不会报错，只是数字悄悄不对。
     *
     * 字段的先后有讲究：排在前面的先挑走列，「送货日期」要压过「发票日期」。
     */
    private const FIELD_HINTS = [
        'date'      => ['送货日期', 'delivery date', '日期', 'date'],
        'kind'      => ['类别', 'category', '品类', '种类'],
        'weight'    => ['净重', '重量', 'weight', '包装数量', 'pack',
                        '!' => ['单价', 'unit price', '金额']],
        // 「金额」而不是「单价」：一行的合计才是要入库的数
        'money_inc' => ['含税金额', 'line amount incl', 'amount incl',
                        '!' => ['单价', 'unit price', '均价', 'average']],
        'money'     => ['行金额', '未税金额', 'line amount', '金额', 'amount',
                        '!' => ['单价', 'unit price', '均价', 'average', '含税']],
        'vat'       => ['税率', 'vat', '!' => ['单价', 'unit price', '金额', 'amount']],
        'supplier'  => ['供货商', '供应商', 'supplier', '!' => ['税号', 'nif']],
        'type'      => ['类型', 'type'],
        'invoice'   => ['发票号', 'invoice', '!' => ['日期', 'date']],
        'desc'      => ['中文名称', 'chinese name', '商品名称', 'description', '原始商品名'],
    ];

    /**
     * 均价的合理区间（€/kg）。超出就警告 —— 这是【认错列】最好用的信号：
     * 把「单价」当成「金额」，或者把「重量」当成「件数」，
     * 算出来的均价会离谱到一眼就能看出来，而单看行数和总额是看不出的。
     */
    private const SANE_PER_KG = [0.5, 200.0];

    /** 给页面用：逐行标出均价离谱的那几条，用的必须是同一个区间 */
    public static function sanePerKg(): array
    {
        return self::SANE_PER_KG;
    }

    /** 类别文字 → 本程序的品类代码。中西文都认，大小写和重音不敏感。 */
    private const KIND_HINTS = [
        'salmon' => ['三文鱼', '鲑', 'salmon', 'salmón', 'salmo'],
        'atun'   => ['金枪鱼', '吞拿', 'atun', 'atún', 'tuna'],
        'lubina' => ['鲈', 'lubina', 'seabass', 'sea bass'],
        'beef'   => ['牛肉', 'beef', 'ternera', 'vacuno'],
    ];

    /**
     * 把整个文件的每一张工作表都解析一遍。
     *
     * 为什么不只解析「自动挑中」的那张：一个工作簿里常常既有汇总页又有明细页，
     * 有时还不止一张明细。只解析猜中的那张，人想换一张就得【重新上传】——
     * 而重新上传之后又要再猜一次，很容易在两张表之间来回打转。
     * 一次全解析出来放着，页面上点一下就能换，也能看到每张表各认出了什么。
     *
     * @return array [
     *   'file'   => 原始文件名,
     *   'sheets' => [工作表名…],
     *   'best'   => 建议用第几张（认出字段最多的），一张都认不出时为 null,
     *   'parsed' => [张号 => 见 sheetResult() 的返回，或 ['error' => 原因]],
     * ]
     */
    public static function parseAll(string $file, string $name): array
    {
        $isCsv = preg_match('/\.csv$/i', $name) === 1;
        if ($isCsv) {
            $sheets = ['(CSV)'];
            $grids  = [0 => self::readCsv($file)];
        } else {
            $sheets = Xlsx::sheetNames($file);
            $grids  = [];
            foreach ($sheets as $i => $_) {
                try {
                    $grids[$i] = Xlsx::rows($file, $i);
                } catch (Throwable $e) {
                    $grids[$i] = null;
                }
            }
        }

        $out    = ['file' => $name, 'sheets' => $sheets, 'best' => null, 'parsed' => []];
        $budget = self::MAX_ROWS;                 // 整个文件合起来的行数上限
        $bestScore = -1;

        foreach ($grids as $i => $grid) {
            if ($grid === null) {
                $out['parsed'][$i] = ['error' => '这张表读不出来'];
                continue;
            }
            [$headerRow, $map] = self::findHeader($grid);
            if ($headerRow === null) {
                $out['parsed'][$i] = ['error' =>
                    '认不出表头（要能找到「日期」「类别」「重量」这几列）'];
                continue;
            }
            if ($budget <= 0) {
                $out['parsed'][$i] = ['error' =>
                    '整个文件的数据行超过 ' . self::MAX_ROWS . ' 行，这张表没有解析'];
                continue;
            }
            $res = self::sheetResult($grid, $headerRow, $map, $i,
                                     (string) ($sheets[$i] ?? ''), $budget);
            $budget -= count($res['rows']);
            $out['parsed'][$i] = $res;

            // 「认出的字段多」优先，一样多时「能导入的行多」优先 ——
            // 汇总页有时也凑得出几个像样的表头，但它导不出几行
            $score = count($map) * 100000 + $res['summary']['ok'];
            if ($score > $bestScore) {
                $bestScore   = $score;
                $out['best'] = $i;
            }
        }
        return $out;
    }

    /**
     * 解析一个文件里的一张表（默认自动挑）。
     *
     * @return array 见 sheetResult()，另带 'sheets'（工作表名）
     */
    public static function parse(string $file, string $name, ?int $sheet = null): array
    {
        $all = self::parseAll($file, $name);
        $i   = $sheet ?? $all['best'];
        $res = $i === null ? null : ($all['parsed'][$i] ?? null);
        if ($res === null || isset($res['error'])) {
            throw new RuntimeException(
                '认不出表头。这张表里要能找到「日期」「类别」「重量」这几列'
                . ($all['sheets']
                    ? '。当前看的是「' . ($all['sheets'][$i] ?? '?') . '」，可以换一张表试试'
                    : '')
            );
        }
        $res['sheets'] = $all['sheets'];
        return $res;
    }

    /**
     * 把一张已经认出表头的表变成待导入记录。
     *
     * @return array [
     *   'sheet' => 张号, 'name' => 表名, 'header' => 表头行号,
     *   'map'   => [字段 => 列字母]，给人核对用,
     *   'basis' => 'incl'（文件直接给含税）|'computed'（按税率折算）|'raw'（没有税率信息）,
     *   'rows'  => [['line'=>行号,'clean'=>校验后的记录,'key'=>去重键,
     *               'raw'=>原始行,'skip'=>跳过原因|null], …],
     *   'summary' => 见 summarize(),
     * ]
     */
    private static function sheetResult(array $grid, int $headerRow, array $map,
                                        int $index, string $name, int $budget): array
    {
        $basis = isset($map['money_inc']) ? 'incl' : (isset($map['vat']) ? 'computed' : 'raw');

        $rows = [];
        $seen = [];
        foreach ($grid as $rn => $cells) {
            if ($rn <= $headerRow) {
                continue;
            }
            $r = self::row($cells, $map, $basis);
            if ($r === null) {
                continue;                         // 整行是空的，不算跳过
            }
            // 同一个文件里内容完全一样的两行，第二行标成重复
            if ($r['key'] !== null && isset($seen[$r['key']])) {
                $r['skip'] = '文件内重复（与第 ' . $seen[$r['key']] . ' 行相同）';
                $r['clean'] = null;
            } elseif ($r['key'] !== null) {
                $seen[$r['key']] = $rn;
            }
            $r['line'] = $rn;
            $rows[] = $r;
            if (count($rows) >= $budget) {
                break;
            }
        }

        $res = ['sheet' => $index, 'name' => $name, 'header' => $headerRow,
                'map' => $map, 'basis' => $basis, 'rows' => $rows];
        $res['summary'] = self::summarize($res);
        return $res;
    }

    /**
     * 核对用的合计。
     *
     * 这一段是【认错列的报警器】，不是装饰。第一版没有它，在真实文件上
     * 把「含税单价」认成了「含税金额」—— 行数照样 69 条、日期照样对，
     * 只有金额从 2 万变成 781，而单看行数根本发现不了。
     * 有了「均价 €/kg」这个数，认错列会立刻变成一个离谱的均价。
     *
     * @return array ['ok','skip','kg','money','no_money','per_kg','from','to',
     *                'kinds'=>[代码=>['rows','kg','money','per_kg']], 'warn'=>[…]]
     */
    public static function summarize(array $res): array
    {
        $s = ['ok' => 0, 'skip' => 0, 'kg' => 0.0, 'money' => 0.0, 'no_money' => 0,
              'per_kg' => null, 'from' => null, 'to' => null, 'kinds' => [], 'warn' => []];

        foreach ($res['rows'] as $r) {
            if (($r['clean'] ?? null) === null) {
                $s['skip']++;
                continue;
            }
            $c = $r['clean'];
            $s['ok']++;
            $w = (float) ($c['weight_kg'] ?? 0);
            $m = $c['total_price'] === null ? null : (float) $c['total_price'];
            $s['kg'] += $w;
            if ($m === null) {
                $s['no_money']++;
            } else {
                $s['money'] += $m;
            }
            $d = (string) $c['purchase_date'];
            if ($s['from'] === null || $d < $s['from']) { $s['from'] = $d; }
            if ($s['to']   === null || $d > $s['to'])   { $s['to']   = $d; }

            $k = (string) $c['kind'];
            $s['kinds'][$k] = $s['kinds'][$k] ?? ['rows' => 0, 'kg' => 0.0, 'money' => 0.0];
            $s['kinds'][$k]['rows']++;
            $s['kinds'][$k]['kg']    += $w;
            $s['kinds'][$k]['money'] += (float) ($m ?? 0);
        }

        $per = static fn(float $money, float $kg) => $kg > 0 ? $money / $kg : null;
        $s['per_kg'] = $per($s['money'], $s['kg']);
        foreach ($s['kinds'] as $k => &$row) {
            $row['per_kg'] = $per($row['money'], $row['kg']);
        }
        unset($row);

        // ---- 报警 ----
        [$lo, $hi] = self::SANE_PER_KG;
        $cols = static fn(array $map, array $fields) => implode('、', array_map(
            static fn($f) => $f . '=' . ($map[$f] ?? '?'),
            array_filter($fields, static fn($f) => isset($map[$f]))));

        if ($s['per_kg'] !== null && ($s['per_kg'] < $lo || $s['per_kg'] > $hi)) {
            $s['warn'][] = '整体均价 ' . number_format($s['per_kg'], 2) . ' €/kg 不像肉价（'
                . '正常应在 ' . $lo . '–' . $hi . ' 之间）。'
                . '多半是【认错了列】—— 比如把「单价」当成了「金额」，或者把「件数」当成了「重量」。'
                . '请对着下面「认出来的列」和原始文件核一遍：'
                . $cols($res['map'], ['weight', 'money_inc', 'money']);
        }
        foreach ($s['kinds'] as $k => $row) {
            if ($row['per_kg'] !== null && ($row['per_kg'] < $lo || $row['per_kg'] > $hi)) {
                $s['warn'][] = '「' . $k . '」的均价 ' . number_format($row['per_kg'], 2)
                    . ' €/kg 不在 ' . $lo . '–' . $hi . ' 之间，这个品类的行值得单独核一下。';
            }
        }
        if ($res['basis'] === 'raw' && $s['money'] > 0) {
            $s['warn'][] = '这张表里没找到「含税金额」，也没找到「税率」，'
                . '所以金额是原样存进去的。如果文件给的是【未税】金额，'
                . '导进来之后和营业额（实收，价内含税）不是一个口径，比出来的成本占比会偏低。';
        }
        if ($s['no_money'] > 0) {
            $s['warn'][] = $s['no_money'] . ' 条读不出金额，会以「待补发票」的形式进库。';
        }
        return $s;
    }

    /**
     * 找表头行：前 30 行里，认出字段最多、且至少认出「日期+类别+重量」的那一行。
     *
     * @return array [行号|null, [字段 => 列字母]]
     */
    private static function findHeader(array $grid): array
    {
        $bestRow = null;
        $bestMap = [];
        $n = 0;
        foreach ($grid as $rn => $cells) {
            if (++$n > 30) {
                break;
            }
            $map  = [];
            $used = [];
            foreach (self::FIELD_HINTS as $field => $spec) {
                $deny  = $spec['!'] ?? [];
                $hints = array_filter($spec, static fn($k) => $k !== '!', ARRAY_FILTER_USE_KEY);
                foreach ($cells as $col => $txt) {
                    if (isset($used[$col])) {
                        continue;                 // 一列只认一个字段，先到先得
                    }
                    $t = mb_strtolower(trim((string) $txt));
                    if ($t === '') {
                        continue;
                    }
                    foreach ($deny as $d) {
                        if (mb_strpos($t, $d) !== false) {
                            continue 2;           // 命中排除词，这一列不是它
                        }
                    }
                    foreach ($hints as $h) {
                        if (mb_strpos($t, $h) !== false) {
                            $map[$field]  = $col;
                            $used[$col]   = true;
                            continue 3;
                        }
                    }
                }
            }
            $need = isset($map['date']) && isset($map['kind']) && isset($map['weight']);
            if ($need && count($map) > count($bestMap)) {
                $bestRow = $rn;
                $bestMap = $map;
            }
        }
        return [$bestRow, $bestMap];
    }

    /** 把一行原始单元格变成一条待导入记录 */
    private static function row(array $cells, array $map, string $basis): ?array
    {
        $get = static fn(string $f) => isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';

        $rawKind = $get('kind');
        $rawW    = $get('weight');
        $rawDate = $get('date');
        if ($rawKind === '' && $rawW === '' && $rawDate === '') {
            return null;                          // 空行
        }

        $out = ['clean' => null, 'key' => null, 'skip' => null, 'raw' => [
            'date' => $rawDate, 'kind' => $rawKind, 'weight' => $rawW,
            'money' => $get('money_inc') !== '' ? $get('money_inc') : $get('money'),
            'supplier' => $get('supplier'), 'invoice' => $get('invoice'),
            'desc' => $get('desc'), 'type' => $get('type'),
        ]];

        // ---- 退货：跳过，不做抵扣 ----
        $type = mb_strtolower($get('type'));
        $w    = self::num($rawW);
        $m    = self::num($out['raw']['money']);
        if (mb_strpos($type, 'return') !== false || mb_strpos($type, '退') !== false
            || ($w !== null && $w < 0) || ($m !== null && $m < 0)) {
            $out['skip'] = '退货／负数行 —— 采购表不收负数，请人工处理';
            return $out;
        }

        $date = Xlsx::toDate($rawDate);
        if ($date === null) {
            $out['skip'] = '日期读不出来：' . ($rawDate === '' ? '（空）' : $rawDate);
            return $out;
        }
        $kind = self::kind($rawKind);
        if ($kind === null) {
            $out['skip'] = '认不出品类：' . ($rawKind === '' ? '（空）' : $rawKind);
            return $out;
        }
        if ($w === null || $w <= 0) {
            $out['skip'] = '重量读不出来或不大于 0：' . ($rawW === '' ? '（空）' : $rawW);
            return $out;
        }

        // ---- 金额统一成含税 ----
        $total = null;
        $vatNote = '';
        if ($m !== null) {
            if ($basis === 'computed' && isset($map['vat'])) {
                $rate = self::num(trim((string) ($cells[$map['vat']] ?? '')));
                if ($rate !== null && $rate > 0) {
                    // 有的表把税率写成 10，有的写成 0.1，两种都认
                    $r = $rate > 1 ? $rate / 100 : $rate;
                    $total   = round($m * (1 + $r), 2);
                    $vatNote = '未税 ' . number_format($m, 2) . ' + 税 '
                             . rtrim(rtrim(number_format($r * 100, 2), '0'), '.') . '%';
                } else {
                    $total = round($m, 2);
                }
            } else {
                $total = round($m, 2);
            }
        }

        $noteBits = array_filter([
            $get('invoice') !== '' ? '发票 ' . $get('invoice') : '',
            $get('desc'),
            $vatNote,
        ], static fn($x) => $x !== '');

        $in = [
            'purchase_date' => $date,
            'kind'          => $kind,
            'weight_kg'     => (string) $w,
            'supplier'      => $get('supplier') !== '' ? $get('supplier') : '',
            'note'          => mb_substr(implode('；', $noteBits), 0, 200),
        ];
        if ($total !== null && $total > 0) {
            $in['total_price'] = (string) $total;
            $in['unit_price']  = (string) round($total / $w, 4);
            $in['price_basis'] = Meat::BASIS_KG;
        }

        [$clean, $errors] = Meat::validate($in);
        if ($errors) {
            $out['skip'] = '校验没过：' . implode('；', $errors);
            return $out;
        }
        $out['clean'] = $clean;
        $out['key']   = self::key($clean, $get('invoice'), $get('desc'));
        return $out;
    }

    /**
     * 去重键：同一张发票的同一行，重复导入时能认出来。
     *
     * 把日期、品类、重量、金额、供货商、发票号、品名一起哈希 ——
     * 只用「日期+品类」会把同一天进的两批不同规格误判成重复。
     */
    public static function key(array $clean, string $invoice, string $desc): string
    {
        return sha1(implode('|', [
            $clean['purchase_date'] ?? '',
            $clean['kind'] ?? '',
            (string) ($clean['weight_kg'] ?? ''),
            (string) ($clean['total_price'] ?? ''),
            $clean['supplier'] ?? '',
            $invoice,
            $desc,
        ]));
    }

    /** 类别文字 → 品类代码；认不出返回 null（宁可跳过也不猜） */
    public static function kind(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        $known = Meat::kinds();
        foreach (self::KIND_HINTS as $code => $hints) {
            if (!isset($known[$code])) {
                continue;                         // 清单里没有的品类不往里塞
            }
            foreach ($hints as $h) {
                if (mb_strpos($t, $h) !== false) {
                    return $code;
                }
            }
        }
        // 直接写代码本身也认（'salmon'、'beef' 这种）
        return isset($known[$t]) ? $t : null;
    }

    /** 数字：认西语逗号小数和千分位 */
    private static function num(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $s = str_replace(["\xc2\xa0", ' ', '€'], '', $s);
        // 「1.234,56」这种欧陆写法：点是千分位、逗号是小数点
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    /** CSV → 和 Xlsx::rows() 一样的 [行号 => [列字母 => 值]] */
    private static function readCsv(string $file): array
    {
        $raw = (string) file_get_contents($file);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        // 分号还是逗号：数哪个多。西语环境导出的 CSV 基本都是分号
        $sep = substr_count($raw, ';') > substr_count($raw, ',') ? ';' : ',';

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $out = [];
        $rn  = 0;
        // 第 4、5 个参数必须写出来：PHP 8.4 起不写 $escape 会报废弃警告，
        // 而且将来默认值会变。转义符给空串（不是反斜杠）—— 发票里出现的
        // 反斜杠就是普通字符，不该让它把后面的引号吃掉
        while (($line = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $rn++;
            foreach ($line as $i => $v) {
                $out[$rn][self::colName($i)] = (string) $v;
            }
        }
        fclose($fh);
        return $out;
    }

    /** 0 => A, 25 => Z, 26 => AA */
    private static function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }
}
