<?php
/**
 * 极小的 .xlsx 读取器 —— 只为了把发票明细读成二维数组。
 *
 * 为什么不用现成的库：这个程序刻意零依赖（无 Composer）。而 .xlsx 本质就是
 * 一个 ZIP 包着几个 XML，读「一张表的单元格」用不到多少代码。
 *
 * 只做读，不做写；只认单元格的值，不管样式、公式、合并。
 * 服务器上没有 ZipArchive 时退回自己解 ZIP（只需要 zlib 的 gzinflate）。
 *
 * ⚠️ 这个类只碰上传上来的文件，跟两个数据库都没有关系。
 */

declare(strict_types=1);

final class Xlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** 上传文件的大小上限：发票明细几千行也就几百 KB，给 8MB 绰绰有余 */
    public const MAX_BYTES = 8388608;

    /** 工作表名字列表 */
    public static function sheetNames(string $file): array
    {
        $wb = self::entry($file, 'xl/workbook.xml');
        if ($wb === null) {
            throw new RuntimeException('这个文件不像 xlsx（缺 workbook.xml）');
        }
        // 两个坑，都踩过：
        //  1. 元素带命名空间（有的文件用默认 xmlns，有的用 x: 前缀），
        //     ->sheets->sheet 直接取是空的，必须 children(命名空间 URI)。
        //  2. children() 之后，属性查找也会默认落到那个命名空间里，
        //     于是 $s['name'] 取到空字符串 —— 属性本身是【不带命名空间】的，
        //     必须走 attributes() 显式取。第一版就栽在这，读出来 0 行。
        $out = [];
        foreach (self::xml($wb)->children(self::NS)->sheets->children(self::NS)->sheet as $s) {
            $out[] = (string) ($s->attributes()['name'] ?? '');
        }
        return $out;
    }

    /**
     * 读第 N 张表（从 0 起），返回 [行号 => [列字母 => 值]]。
     *
     * 空单元格不出现在数组里 —— 调用方用 ?? '' 取值即可。
     */
    public static function rows(string $file, int $index = 0): array
    {
        $shared = self::sharedStrings($file);
        $xmlRaw = self::entry($file, 'xl/worksheets/sheet' . ($index + 1) . '.xml');
        if ($xmlRaw === null) {
            throw new RuntimeException('找不到第 ' . ($index + 1) . ' 张工作表');
        }
        $sheet = self::xml($xmlRaw);

        $out  = [];
        $data = $sheet->children(self::NS)->sheetData;
        foreach ($data->children(self::NS)->row as $row) {
            $rn = (int) ($row->attributes()['r'] ?? 0);
            foreach ($row->children(self::NS)->c as $c) {
                $at   = $c->attributes();
                $ref  = (string) ($at['r'] ?? '');
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $col  = $m[1];
                $type = (string) ($at['t'] ?? '');

                $kids = $c->children(self::NS);
                if ($type === 'inlineStr') {
                    $val = self::textOf($kids->is);
                } elseif ($type === 's') {
                    $val = $shared[(int) $kids->v] ?? '';
                } elseif (isset($kids->v)) {
                    $val = (string) $kids->v;
                } else {
                    continue;                       // 空格子，不占位
                }
                $out[$rn][$col] = $val;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Excel 的日期序号 → Y-m-d。
     *
     * Excel 把日期存成「1899-12-30 起的天数」。1900 年闰年那个著名的错误
     * 正是靠这个起点抵消掉的，所以基准写 12-30 而不是 12-31。
     *
     * 【下限为什么是 61 而不是 1】那个错误只在 1900 年 3 月之前有影响：
     * Excel 认为有 1900-02-29 这一天（其实没有），所以序号 1–60 用这个
     * 基准算出来会【差一天】（序号 1 会变成 1899-12-31，而 Excel 显示的是
     * 1900-01-01）。与其算出一个差一天的日期，不如直接说「这不是日期」——
     * 发票上不会有 1900 年 2 月的单子，但「某一列其实是件数」倒是常有的事，
     * 那种列里的小数字正好落在 1–60。
     */
    public static function toDate($serial): ?string
    {
        if (!is_numeric($serial)) {
            $t = strtotime((string) $serial);
            return $t === false ? null : date('Y-m-d', $t);
        }
        $n = (int) round((float) $serial);
        // 61 = 1900-03-01，60000 ≈ 2064 年，超出这个范围的多半不是日期
        if ($n < 61 || $n > 60000) {
            return null;
        }
        return date('Y-m-d', mktime(12, 0, 0, 12, 30 + $n, 1899));
    }

    // ------------------------------------------------------------------
    // 内部：ZIP 与 XML
    // ------------------------------------------------------------------

    private static function sharedStrings(string $file): array
    {
        $raw = self::entry($file, 'xl/sharedStrings.xml');
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach (self::xml($raw)->children(self::NS)->si as $si) {
            $out[] = self::textOf($si);
        }
        return $out;
    }

    /** <si> / <is> 里可能被拆成好几个 <t>（富文本），要拼起来 */
    private static function textOf($node): string
    {
        if ($node === null) {
            return '';
        }
        $s = '';
        foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $t) {
            $s .= (string) $t;
        }
        return $s;
    }

    private static function xml(string $raw): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        // ⚠️ 上传文件是外来数据：禁掉实体加载，别让人拿 XXE 读服务器上的文件
        $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new RuntimeException('工作表 XML 解析失败');
        }
        $x->registerXPathNamespace('m', self::NS);
        return $x;
    }

    /** 取出 ZIP 里的一个条目，取不到返回 null */
    private static function entry(string $file, string $name): ?string
    {
        if (!is_file($file) || filesize($file) > self::MAX_BYTES) {
            throw new RuntimeException('文件不存在或超过 '
                . (int) (self::MAX_BYTES / 1048576) . 'MB');
        }
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($file) !== true) {
                throw new RuntimeException('打不开这个文件（不是有效的 xlsx）');
            }
            $raw = $z->getFromName($name);
            $z->close();
            return $raw === false ? null : $raw;
        }
        return self::entryNoZipExt($file, $name);
    }

    /**
     * 没有 ZipArchive 时自己解。
     *
     * 只认最常见的两种：存储（0）和 deflate（8）—— Excel 存的就是 deflate。
     * 从「中央目录」找条目，而不是顺着扫本地头：本地头里的长度字段在
     * 流式写出的 zip 里可能是 0，真正的长度只有中央目录里那份是准的。
     */
    private static function entryNoZipExt(string $file, string $name): ?string
    {
        $data = (string) file_get_contents($file);
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('不是有效的 zip/xlsx 文件');
        }
        $cnt = unpack('v', substr($data, $eocd + 10, 2))[1];
        $off = unpack('V', substr($data, $eocd + 16, 4))[1];

        for ($i = 0; $i < $cnt; $i++) {
            if (substr($data, $off, 4) !== "PK\x01\x02") {
                break;
            }
            $h      = unpack('vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen',
                             substr($data, $off + 10, 26));
            $fname  = substr($data, $off + 46, $h['nlen']);
            $lhOff  = unpack('V', substr($data, $off + 42, 4))[1];
            $off   += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if ($fname !== $name) {
                continue;
            }
            // 本地头的「文件名长度 / 扩展长度」才是这一份数据前面的实际偏移
            $lh   = unpack('vnlen/vxlen', substr($data, $lhOff + 26, 4));
            $body = substr($data, $lhOff + 30 + $lh['nlen'] + $lh['xlen'], $h['csize']);
            if ($h['method'] === 0) {
                return $body;
            }
            if ($h['method'] === 8) {
                $out = @gzinflate($body);
                if ($out === false) {
                    throw new RuntimeException("解压失败：{$name}");
                }
                return $out;
            }
            throw new RuntimeException("不支持的压缩方式：{$h['method']}");
        }
        return null;
    }
}
