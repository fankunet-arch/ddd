<?php
/**
 * 检查渲染出来的 HTML：
 *   1. 每张表的表头列数与数据行列数是否一致（结构错位）
 *   2. 数值单元格 <td class="n"> 对应的表头是否也是右对齐（视觉错位）
 */
$fails = 0;
$files = array_slice($argv, 1);
// 不给文件就直接失败 —— 否则会静默"通过"，等于没测
if (!$files) {
    fwrite(STDERR, "  ✗ 没有传入任何 HTML 文件（用法: php tablecheck.php out_*.html）\n");
    exit(1);
}
$checked = 0;
foreach ($files as $file) {
    $html = file_get_contents($file);
    $name = basename($file);
    if (!preg_match_all('#<table[^>]*>(.*?)</table>#s', $html, $tables)) {
        continue;
    }
    foreach ($tables[1] as $ti => $tbl) {
        // ---- 列数一致性（考虑 colspan / rowspan）----
        preg_match('#<thead>(.*?)</thead>#s', $tbl, $th);
        preg_match('#<tbody>(.*?)</tbody>#s', $tbl, $tb);
        preg_match('#<tfoot>(.*?)</tfoot>#s', $tbl, $tf);
        if (!$th || !$tb) {
            continue;
        }
        $checked++;

        $width = static function (string $rowHtml): int {
            $n = 0;
            preg_match_all('#<t[hd]([^>]*)>#', $rowHtml, $cells);
            foreach ($cells[1] as $attr) {
                $n += preg_match('/colspan\s*=\s*"?(\d+)/', $attr, $m) ? (int) $m[1] : 1;
            }
            return $n;
        };

        // 表头总宽 = 第一行（含 colspan）
        preg_match_all('#<tr>(.*?)</tr>#s', $th[1], $hrows);
        $headWidth = $hrows[1] ? $width($hrows[1][0]) : 0;

        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $tb[1], $brows);
        foreach ($brows[1] as $ri => $row) {
            $w = $width($row);
            if ($w !== $headWidth) {
                printf("  ✗ %s 表#%d 数据行#%d 列数 %d ≠ 表头 %d\n", $name, $ti + 1, $ri + 1, $w, $headWidth);
                $fails++;
                break;
            }
        }
        if ($tf) {
            preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $tf[1], $frows);
            foreach ($frows[1] as $row) {
                $w = $width($row);
                if ($w !== $headWidth) {
                    printf("  ✗ %s 表#%d 合计行列数 %d ≠ 表头 %d\n", $name, $ti + 1, $w, $headWidth);
                    $fails++;
                }
            }
        }

        // ---- 对齐一致性：取表头最后一行（真正对应数据列的那行）----
        $lastHeadRow = end($hrows[1]);
        preg_match_all('#<th([^>]*)>#', (string) $lastHeadRow, $hcells);
        // 只在表头最后一行与数据列一一对应时才检查
        if (count($hcells[1]) !== $headWidth) {
            // 有 rowspan 的复杂表头，取第一条数据行做对照
            preg_match_all('#<t[hd]([^>]*)>#', $brows[1][0] ?? '', $bcells);
            $offset = $headWidth - count($hcells[1]);
            foreach ($hcells[1] as $i => $hattr) {
                $bi = $i + $offset;
                $battr = $bcells[1][$bi] ?? '';
                $bIsNum = strpos($battr, 'class="n') !== false;
                $hIsNum = strpos($hattr, 'class="n') !== false;
                if ($bIsNum && !$hIsNum) {
                    printf("  ✗ %s 表#%d 第 %d 列：数据右对齐但表头左对齐\n", $name, $ti + 1, $bi + 1);
                    $fails++;
                }
            }
        } else {
            preg_match_all('#<t[hd]([^>]*)>#', $brows[1][0] ?? '', $bcells);
            foreach ($hcells[1] as $i => $hattr) {
                $battr = $bcells[1][$i] ?? '';
                $bIsNum = strpos($battr, 'class="n') !== false;
                $hIsNum = strpos($hattr, 'class="n') !== false;
                if ($bIsNum && !$hIsNum) {
                    printf("  ✗ %s 表#%d 第 %d 列：数据右对齐但表头左对齐\n", $name, $ti + 1, $i + 1);
                    $fails++;
                }
            }
        }
    }
}
if ($checked === 0) {
    fwrite(STDERR, "  ✗ 传入的文件里没有找到任何带表头和数据的表格\n");
    exit(1);
}
printf("  （共检查 %d 张表）\n", $checked);
echo $fails === 0 ? "  ✓ 所有表格列数与对齐一致\n" : "  共 {$fails} 处问题\n";
exit($fails === 0 ? 0 : 1);
