<?php
/**
 * 登录保护
 *
 * 密码写在 config.php 的 'password' 里，支持两种写法：
 *   1. 明文，例如  'password' => 'abc123'
 *   2. bcrypt 哈希，例如  'password' => '$2y$10$....'
 *      （用 php -r "echo password_hash('你的密码', PASSWORD_DEFAULT);" 生成）
 *   两种都能用，哈希更安全一些 —— 即使 config.php 被人看到也读不出原文。
 *
 * 说明：登录会用到 PHP 的 session，PHP 会把会话文件写到服务器的 session 目录。
 * 这属于 PHP 自身的机制，与数据库无关 —— 本程序对数据库仍然只读。
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class Auth
{
    private const SESSION_NAME = 'SALESRPT';
    private const KEY_OK       = 'auth_ok';
    private const KEY_TIME     = 'auth_time';
    private const KEY_CSRF     = 'csrf';

    /** 会话有效期（秒），超时需要重新登录 */
    private const LIFETIME = 8 * 3600;

    public static function boot(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,          // 关闭浏览器即失效
            'path'     => '/',
            'httponly' => true,       // 禁止 JS 读取，降低 XSS 风险
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
        session_start();
    }

    /** config.php 里有没有配置密码 */
    public static function isConfigured(): bool
    {
        $p = (string) (Db::config()['password'] ?? '');
        return $p !== '' && $p !== '在这里设置登录密码';
    }

    public static function isLoggedIn(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;                  // 命令行脚本不走登录
        }
        self::boot();
        if (empty($_SESSION[self::KEY_OK])) {
            return false;
        }
        // 超时自动登出
        if (time() - (int) ($_SESSION[self::KEY_TIME] ?? 0) > self::LIFETIME) {
            self::logout();
            return false;
        }
        $_SESSION[self::KEY_TIME] = time();
        return true;
    }

    /**
     * 校验密码。明文与哈希两种配置都支持。
     * 用 hash_equals 做定长比较，避免通过响应时间猜密码。
     */
    public static function verify(string $input): bool
    {
        $stored = (string) (Db::config()['password'] ?? '');
        if ($stored === '') {
            return false;
        }
        if (preg_match('/^\$(2[aby]|argon2)/', $stored)) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }

    /** 登录成功后调用 */
    public static function login(): void
    {
        self::boot();
        session_regenerate_id(true);      // 防会话固定攻击
        $_SESSION[self::KEY_OK]   = true;
        $_SESSION[self::KEY_TIME] = time();
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** 页面顶部调用：未登录就跳到登录页 */
    public static function requireLogin(): void
    {
        if (self::isLoggedIn()) {
            return;
        }
        $back = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: login.php' . ($back !== '' ? '?back=' . urlencode($back) : ''));
        exit;
    }

    /** 表单防跨站请求令牌 */
    public static function csrfToken(): string
    {
        self::boot();
        if (empty($_SESSION[self::KEY_CSRF])) {
            $_SESSION[self::KEY_CSRF] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION[self::KEY_CSRF];
    }

    public static function csrfValid(?string $token): bool
    {
        self::boot();
        $want = (string) ($_SESSION[self::KEY_CSRF] ?? '');
        return $want !== '' && is_string($token) && hash_equals($want, $token);
    }
}
