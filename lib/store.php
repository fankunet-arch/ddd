<?php
/**
 * 自有数据存储 —— SQLite 单文件
 *
 * ============================================================
 *  ⚠️ 这是全程序【唯一】允许写入的地方。改动前请看根目录「注意事项.md」铁律二。
 * ============================================================
 *
 *  和 lib/db.php 的关系：**没有关系，故意的**。
 *
 *    Db     → POS 主库（MySQL），只读，一个字节都不许写
 *    Store  → 我们自己的数据（SQLite 文件），可读可写
 *
 *  两个类各自持有自己的连接，互不引用。不要给 Db 加写能力，
 *  也不要让 Store 去连 MySQL —— 构造时会强制检查 DSN 必须是 sqlite:。
 *
 *  跨两边的统计一律在 PHP 内存里合并（按日期之类的 key 拼），
 *  不许写出把两边放进同一条 SQL 的代码。
 *
 * ============================================================
 *  数据文件放哪
 * ============================================================
 *  默认放在【程序目录的上一级】的 data/ 里，也就是网站根目录之外：
 *
 *      /var/www/ddd/          ← 程序在这里
 *      /var/www/data/app.db   ← 数据文件在这里
 *
 *  绝对不能放进网站目录 —— 那样别人直接输 URL 就能把整个数据库下载走。
 *  路径可在 config.php 的 store_path 里改。
 *
 *  这一项让程序第一次需要【写权限】：那个目录要让 Web 服务器账号可写。
 */

declare(strict_types=1);

final class Store
{
    private static ?PDO $pdo = null;
    private static ?string $failure = null;

    /** 数据文件路径 */
    public static function path(): string
    {
        require_once __DIR__ . '/db.php';
        $p = trim((string) (Db::config()['store_path'] ?? ''));
        if ($p === '') {
            // 默认：程序目录的上一级 / data / app.db —— 在网站根目录之外
            $p = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data'
               . DIRECTORY_SEPARATOR . 'app.db';
        }
        return $p;
    }

    /** 数据文件是否已经能用（不能用时用 lastError() 取原因） */
    public static function isReady(): bool
    {
        try {
            self::pdo();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$failure;
    }

    /**
     * 拿连接。第一次调用时建库建表。
     *
     * 建表用 IF NOT EXISTS，跑多少次都一样，不需要单独的迁移机制 ——
     * 这点数据量不值得为它引入版本管理。
     */
    private static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = self::path();
        $dir  = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            self::$failure = "数据目录建不出来：{$dir}（需要 Web 服务器账号有写权限）";
            throw new RuntimeException(self::$failure);
        }
        if (!is_writable($dir)) {
            self::$failure = "数据目录不可写：{$dir}（本程序第一次需要写权限，"
                           . '请给 Web 服务器账号加上）';
            throw new RuntimeException(self::$failure);
        }

        $dsn = 'sqlite:' . $path;
        // 边界检查：Store 只碰 SQLite。写成常量拼接看着多余，但这是铁律二的
        // 机械保险 —— 万一以后有人把 store_path 改成 mysql:... 这里直接拦住。
        if (strncmp($dsn, 'sqlite:', 7) !== 0) {
            throw new RuntimeException('Store 只允许连接 SQLite');
        }

        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            self::$failure = '打不开数据文件：' . $e->getMessage();
            throw new RuntimeException(self::$failure, 0, $e);
        }

        // WAL：读写可以并发，几个人同时用不会互相锁死
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    /** 建表。CHECK 约束是最后一道防线 —— PHP 校验之外，数据库自己也拦。 */
    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meat_purchase (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_date TEXT    NOT NULL,
                kind          TEXT    NOT NULL,
                weight_kg     REAL,
                unit_count    REAL,
                unit_type     TEXT,
                price_basis   TEXT,
                unit_price    REAL,
                total_price   REAL,
                supplier      TEXT,
                note          TEXT,
                created_at    TEXT    NOT NULL,
                updated_at    TEXT    NOT NULL,
                deleted_at    TEXT,
                -- 重量和件数至少要有一个：这条规则在 PHP 里也校验，
                -- 但数据库层再拦一道，绕过页面直接写也进不来脏数据
                CHECK (weight_kg IS NOT NULL OR unit_count IS NOT NULL),
                CHECK (weight_kg  IS NULL OR weight_kg  > 0),
                CHECK (unit_count IS NULL OR unit_count > 0),
                CHECK (unit_price IS NULL OR unit_price >= 0),
                CHECK (total_price IS NULL OR total_price >= 0)
            )
            SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mp_date ON meat_purchase(purchase_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mp_kind ON meat_purchase(kind, purchase_date)');

        // 留痕：每次改动记一条，存改动前后的快照
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meat_purchase_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                row_id      INTEGER NOT NULL,
                action      TEXT    NOT NULL,
                before_json TEXT,
                after_json  TEXT,
                at          TEXT    NOT NULL
            )
            SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mpl_row ON meat_purchase_log(row_id, id)');
    }

    // ------------------------------------------------------------------
    // 通用读写
    // ------------------------------------------------------------------

    public static function select(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array
    {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }

    /** 执行写操作，返回受影响行数 */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** 记一条改动日志 */
    public static function log(int $rowId, string $action, ?array $before, ?array $after): void
    {
        self::run(
            'INSERT INTO meat_purchase_log (row_id, action, before_json, after_json, at)
             VALUES (:r, :a, :b, :f, :t)',
            [':r' => $rowId, ':a' => $action,
             ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
             ':f' => $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
             ':t' => date('Y-m-d H:i:s')]
        );
    }

    /** 事务：改动 + 留痕要么一起成功，要么一起回滚 */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** 仅供自检脚本：换成内存库，不碰真实文件 */
    public static function useMemoryForTests(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo = $pdo;
        self::migrate($pdo);
    }
}
