<?php
/**
 * 登录页
 *
 * 密码在 config.php 的 'password' 里设置。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/view.php';

Auth::boot();

// 已经登录了就直接进去
if (Auth::isLoggedIn() && ($_GET['action'] ?? '') !== 'logout') {
    header('Location: index.php');
    exit;
}

if (($_GET['action'] ?? '') === 'logout') {
    Auth::logout();
    header('Location: login.php?bye=1');
    exit;
}

$error = null;
$back  = $_GET['back'] ?? ($_POST['back'] ?? '');
// 只接受本站相对路径，避免被构造成跳转到外部网站
if (!is_string($back) || $back === '' || $back[0] !== '/' && !preg_match('#^[\w.-]+\.php#', $back)) {
    $back = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isConfigured()) {
        $error = '尚未设置密码：请先在 config.php 里填写 password';
    } elseif (!Auth::csrfValid($_POST['csrf'] ?? null)) {
        $error = '表单已过期，请重新提交';
    } elseif (Auth::verify((string) ($_POST['password'] ?? ''))) {
        Auth::login();
        header('Location: ' . $back);
        exit;
    } else {
        // 失败时统一延迟 1 秒，拖慢暴力破解
        sleep(1);
        $error = '密码不正确';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1e2836">
<title>登录 · 营业数据查询</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="loginpage">
<form class="loginbox" method="post" action="login.php">
  <div class="logo" aria-hidden="true">📊</div>
  <h1>营业数据查询</h1>
  <p class="sub">请输入密码</p>

  <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
  <input type="hidden" name="back" value="<?= h($back) ?>">
  <input type="password" name="password" placeholder="密码" autofocus required
         autocomplete="current-password">
  <button type="submit">进入</button>

  <?php if ($error): ?>
    <p class="err"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if (isset($_GET['bye'])): ?>
    <p class="ok">已退出登录</p>
  <?php endif; ?>
  <?php if (!Auth::isConfigured()): ?>
    <p class="err">config.php 里还没设置 password，任何密码都无法登录。</p>
  <?php endif; ?>

  <p class="foot">本程序仅执行 SELECT 查询，不会对数据库做任何写入、删除或结构变更。</p>
</form>
</body>
</html>
