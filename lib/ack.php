<?php
/**
 * 开台核对的「人工确认」
 *
 * 用途：并桌之类的情况会让一张单人数很多却没有套餐，核对结果显示成「未打套餐」，
 * 但其实是正常的。人工确认一次之后就不再提示，省得每次刷新都要重新判断一遍。
 *
 * 存在哪：**PHP session**，不写数据库 —— 本程序对数据库始终只读。
 *   - 随退出登录一起失效（这是可接受的：重新登录后重新核对一遍即可）
 *   - 按浏览器隔离：手机上确认过，收银台电脑上仍需自己确认
 *   - 默认 6 小时后自动过期，可在 config.php 的 ack_hours 调整
 *
 * 什么时候作废：确认时记下当时的「人数 + 套餐份数 + 酒水是否达标」指纹，
 * 只要其中之一发生变化（加人、补打套餐、退套餐、酒水从不足变成够了），
 * 指纹对不上，该台就回到待核对状态。
 * 其他变化（又点了几个菜、金额变了）不会让确认失效，否则一直在重复确认。
 *
 * 酒水这一项只记「够没够」而不记杯数 —— 规则是「每人至少一份，多了没关系」，
 * 所以在不足的状态下又加了一杯（仍然不足），确认不该因此作废。
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class Ack
{
    private const KEY = 'ack_open';

    /** 命令行（自检脚本）下没有 session，退回到进程内数组，便于测试 */
    private static array $memory = [];

    public static function hours(): int
    {
        $h = (int) (Db::config()['ack_hours'] ?? 6);
        return $h > 0 ? $h : 6;
    }

    /**
     * 状态指纹：认「人数」「套餐份数」「酒水是否达标」三项。
     *
     * 份数可能是小数（称重菜之类），乘 100 取整避免浮点比较问题。
     * 酒水只记布尔值：规则是「每人至少一份，多了没关系」，
     * 所以不足时又加一杯（仍然不足）不该让确认作废。
     */
    public static function fingerprint(array $row): string
    {
        return ((int) $row['guests'])
             . ':' . ((int) round(((float) $row['combo']) * 100))
             . ':d' . (empty($row['drink_ok']) ? '0' : '1');
    }

    // ------------------------------------------------------------------

    private static function &store(): array
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
                $_SESSION[self::KEY] = [];
            }
            return $_SESSION[self::KEY];
        }
        return self::$memory;
    }

    /** 清掉过期条目，返回当前有效的全部确认记录 */
    public static function all(): array
    {
        $s      = &self::store();
        $cutoff = time() - self::hours() * 3600;
        foreach ($s as $id => $e) {
            if (!is_array($e) || (int) ($e['at'] ?? 0) < $cutoff) {
                unset($s[$id]);
            }
        }
        return $s;
    }

    /** 记下一次确认。$fp 是确认当时看到的状态指纹。 */
    public static function set(int $orderId, string $fp): void
    {
        if ($orderId <= 0) {
            return;
        }
        $s = &self::store();
        $s[$orderId] = ['fp' => $fp, 'at' => time()];
    }

    /** 撤销某台的确认 */
    public static function clear(int $orderId): void
    {
        $s = &self::store();
        unset($s[$orderId]);
    }

    /** 清空全部确认 */
    public static function clearAll(): void
    {
        $s = &self::store();
        $s = [];
    }

    /** 仅供自检脚本重置进程内存储 */
    public static function resetMemory(): void
    {
        self::$memory = [];
    }
}
