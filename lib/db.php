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
 *
 * 底层驱动支持 PDO 与 mysqli 两种，自动选择可用的那个：
 * 很多老服务器只装了 mysqli 而没有 pdo_mysql，或者启用 pdo_mysql 会导致
 * PHP 启动失败。两条路都走得通，就不必为了跑这个程序去动 php.ini。
 */

declare(strict_types=1);

final class Db
{
    private static ?DbDriver $driver = null;
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

    /** 当前实际使用的驱动名：pdo 或 mysqli */
    public static function driverName(): string
    {
        return self::driver()->name();
    }

    /** 本机可用的驱动列表 */
    public static function availableDrivers(): array
    {
        $out = [];
        if (extension_loaded('pdo') && extension_loaded('pdo_mysql')
            && in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $out[] = 'pdo';
        }
        if (extension_loaded('mysqli')) {
            $out[] = 'mysqli';
        }
        return $out;
    }

    private static function driver(): DbDriver
    {
        if (self::$driver === null) {
            $c    = self::config();
            $want = $c['driver'] ?? 'auto';
            $have = self::availableDrivers();

            if (!$have) {
                throw new RuntimeException(
                    'PHP 没有可用的 MySQL 扩展：pdo_mysql 和 mysqli 都未加载。'
                    . '请在 php.ini 中启用其中之一（推荐 extension=mysqli）后重启 Web 服务。'
                    . '详细诊断请运行或访问 tests/env.php。'
                );
            }
            if ($want !== 'auto' && !in_array($want, $have, true)) {
                throw new RuntimeException(
                    "config.php 指定了 driver = {$want}，但该扩展未加载。"
                    . '本机可用: ' . implode(', ', $have)
                );
            }

            // auto：优先 PDO，没有就用 mysqli
            $pick = $want !== 'auto' ? $want : $have[0];
            self::$driver = $pick === 'pdo' ? new PdoDriver($c) : new MysqliDriver($c);
        }
        return self::$driver;
    }

    /**
     * 执行一条 SELECT 查询。
     *
     * @param string $sql    必须以 SELECT 开头
     * @param array  $params 绑定参数，键形如 ':from'
     * @return array         结果集
     * @throws RuntimeException 当 SQL 不是纯 SELECT 时
     */
    public static function select(string $sql, array $params = []): array
    {
        self::assertReadOnly($sql);
        return self::driver()->query($sql, $params);
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
            // \b 词边界，避免误伤 "order_start_time" 之类的列名
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $upper)) {
                throw new RuntimeException("只读模式：SQL 中不允许出现 {$kw}");
            }
        }
    }
}

/** 驱动接口 */
interface DbDriver
{
    public function name(): string;

    /** @param array $params 键形如 ':from' */
    public function query(string $sql, array $params): array;
}

/** PDO 驱动 */
final class PdoDriver implements DbDriver
{
    private PDO $pdo;

    public function __construct(array $c)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['dbname'], $c['charset']
        );

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 关闭模拟预处理，让参数真正走服务端绑定
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // 这个常量只有 pdo_mysql 基于 mysqlnd 编译时才存在，
        // 用 libmysqlclient 编译的环境里没有，必须先判断再用。
        // 真正拦住多语句的是 Db::assertReadOnly() 里的分号检查，此项只是额外加固。
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $opts[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }

        $this->pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    }

    public function name(): string
    {
        return 'pdo';
    }

    public function query(string $sql, array $params): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}

/**
 * mysqli 驱动
 *
 * mysqli 不支持 :name 形式的命名参数，只认 ?，所以要先把 SQL 转换成
 * 位置参数形式。转换时会跳过单引号字符串，避免把 '08:00:00' 这类
 * 时间常量里的冒号误当成参数占位符。
 */
final class MysqliDriver implements DbDriver
{
    private mysqli $conn;

    public function __construct(array $c)
    {
        // 让 mysqli 用异常报错，与 PDO 的行为保持一致
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->conn = new mysqli(
            (string) $c['host'],
            (string) $c['user'],
            (string) $c['pass'],
            (string) $c['dbname'],
            (int) $c['port']
        );
        $this->conn->set_charset((string) $c['charset']);
    }

    public function name(): string
    {
        return 'mysqli';
    }

    public function query(string $sql, array $params): array
    {
        [$sql, $values] = self::toPositional($sql, $params);

        $st = $this->conn->prepare($sql);
        if ($st === false) {
            throw new RuntimeException('SQL 预处理失败: ' . $this->conn->error);
        }

        try {
            if ($values) {
                // bind_param 按引用取参，必须传引用数组
                $refs = [];
                foreach ($values as $k => $_) {
                    $refs[$k] = &$values[$k];
                }
                // 全部按字符串绑定，MySQL 会按列类型自动转换
                $st->bind_param(str_repeat('s', count($values)), ...$refs);
            }
            $st->execute();
            return self::fetchAll($st);
        } finally {
            $st->close();
        }
    }

    /** 把 :name 占位符换成 ?，并按出现顺序整理出值数组 */
    public static function toPositional(string $sql, array $params): array
    {
        $out    = '';
        $values = [];
        $len    = strlen($sql);
        $i      = 0;
        $inStr  = false;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($inStr) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {   // 转义字符整体跳过
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === "'") {
                    $inStr = false;
                }
                $i++;
                continue;
            }

            if ($ch === "'") {
                $inStr = true;
                $out  .= $ch;
                $i++;
                continue;
            }

            // 字符串外面的 :name 才是参数占位符
            if ($ch === ':' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($sql, $i + 1), $m)) {
                $name = ':' . $m[0];
                if (!array_key_exists($name, $params)) {
                    throw new RuntimeException("SQL 缺少绑定参数 {$name}");
                }
                $values[] = $params[$name];
                $out     .= '?';
                $i       += 1 + strlen($m[0]);
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return [$out, $values];
    }

    /**
     * 取回全部结果行。
     * get_result() 需要 mysqlnd，没有的话退回 bind_result() 逐行取，
     * 这样在用 libmysqlclient 编译的老环境上也能工作。
     */
    private static function fetchAll(mysqli_stmt $st): array
    {
        if (method_exists($st, 'get_result')) {
            $res = @$st->get_result();
            if ($res instanceof mysqli_result) {
                $rows = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
                return $rows;
            }
        }

        // 无 mysqlnd 时的回退路径
        $meta = $st->result_metadata();
        if ($meta === false) {
            return [];
        }
        $fields = [];
        $row    = [];
        $binds  = [];
        while ($f = $meta->fetch_field()) {
            $fields[]        = $f->name;
            $row[$f->name]   = null;
            $binds[]         = &$row[$f->name];
        }
        $meta->free();

        $st->store_result();
        $st->bind_result(...$binds);

        $out = [];
        while ($st->fetch()) {
            $copy = [];
            foreach ($fields as $f) {      // 逐字段复制，避免把引用带出去
                $copy[$f] = $row[$f];
            }
            $out[] = $copy;
        }
        $st->free_result();
        return $out;
    }
}
