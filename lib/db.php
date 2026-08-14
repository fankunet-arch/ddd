<?php
/**
 * 只读数据库访问层
 *
 * 本文件是全程序唯一与数据库通信的入口。它在代码层面强制保证：
 *   1. 只允许执行 SELECT 语句；
 *   2. 任何写操作关键字（INSERT/UPDATE/DELETE/DDL 等）一律拒绝执行；
 *   3. 不提供 exec()、不开启事务、不做任何多语句执行。
 *
 * 配合 config.php 中建议的只读数据库账号，形成双重保险。
 */

declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    /** 禁止出现在 SQL 中的写操作关键字 */
    private const FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'DROP',
        'CREATE', 'ALTER', 'RENAME', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK',
        'CALL', 'LOAD', 'HANDLER', 'START', 'COMMIT', 'ROLLBACK', 'SET',
        'INTO OUTFILE', 'INTO DUMPFILE',
    ];

    public static function config(): array
    {
        if (!self::$cfg) {
            self::$cfg = require __DIR__ . '/../config.php';
        }
        return self::$cfg;
    }

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::config();
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'], $c['port'], $c['dbname'], $c['charset']
            );
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // 关闭模拟预处理，让参数真正走服务端绑定
                PDO::ATTR_EMULATE_PREPARES   => false,
                // 关键：禁止一次执行多条语句，杜绝语句拼接注入
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * 执行一条 SELECT 查询。
     *
     * @param string $sql    必须以 SELECT 开头
     * @param array  $params 绑定参数
     * @return array         结果集
     * @throws RuntimeException 当 SQL 不是纯 SELECT 时
     */
    public static function select(string $sql, array $params = []): array
    {
        self::assertReadOnly($sql);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 执行 SELECT 并只返回第一行；无结果返回 null */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }

    /**
     * 校验 SQL 为只读语句。任何可疑内容直接抛异常终止。
     * 公开为 public 以便自检脚本在不连数据库的情况下验证这道防线。
     */
    public static function assertReadOnly(string $sql): void
    {
        // 去掉注释，避免关键字藏在注释里绕过检查
        $clean = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $clean = preg_replace('#--[^\n]*#', ' ', (string) $clean);
        $clean = preg_replace('#\#[^\n]*#', ' ', (string) $clean);
        $upper = strtoupper(trim((string) $clean));

        if (strncmp($upper, 'SELECT', 6) !== 0) {
            throw new RuntimeException('只读模式：仅允许 SELECT 语句');
        }
        if (strpos($clean, ';') !== false) {
            throw new RuntimeException('只读模式：SQL 中不允许出现分号');
        }
        foreach (self::FORBIDDEN as $kw) {
            // \b 词边界，避免误伤 "created_at" 之类的列名
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $upper)) {
                throw new RuntimeException("只读模式：SQL 中不允许出现 {$kw}");
            }
        }
    }
}
