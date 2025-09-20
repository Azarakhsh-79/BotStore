<?php
// public/superadmin/index.php - Login & Session Management
session_start();
require_once __DIR__ . '/../../bootstrap.php';

use Config\AppConfig;
use Bot\SuperAdminManager;

// Define the correct base path for redirection
$basePath = '/superadmin/app.php';

// If user is already logged in, redirect immediately to the app
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true && !(isset($_GET['action']) && $_GET['action'] === 'logout')) {
    header('Location: ' . $basePath);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$masterConfig = AppConfig::getMasterDbConfig();
$adminUser = $masterConfig['admin_user'];
$adminPassHash = $masterConfig['admin_pass'];
$error = null;

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('Invalid CSRF token for logout.');
    }
    $manager = new SuperAdminManager();
    $manager->logAction('Logout');
    unset($_SESSION['super_admin_logged_in'], $_SESSION['super_admin_username']);
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle Login Post Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "خطای امنیتی: درخواست نامعتبر است.";
    } elseif ($_POST['username'] === $adminUser && password_verify($_POST['password'], $adminPassHash)) {
        session_regenerate_id(true);
        $_SESSION['super_admin_logged_in'] = true;
        $_SESSION['super_admin_username'] = $_POST['username'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $manager = new SuperAdminManager();
        $manager->logAction('Login');

        header('Location: ' . $basePath);
        exit;
    } else {
        $error = "نام کاربری یا رمز عبور اشتباه است.";
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پنل مدیریت کل</title>
    <link rel="stylesheet" href="/superadmin/styles.css">
</head>

<body>
    <div class="container login-container">
        <h1>ورود به پنل مدیریت کل</h1>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="username" placeholder="نام کاربری" required>
            <input type="password" name="password" placeholder="رمز عبور" required>
            <button type="submit">ورود</button>
        </form>
    </div>
</body>

</html>

