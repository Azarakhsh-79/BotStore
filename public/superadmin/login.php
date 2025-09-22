<?php
require_once __DIR__ . '/../../bootstrap.php';

use Bot\SuperAdminManager;

session_start();
$error = '';
$base_url = '/NewBot/public/superadmin/'; // مسیر صحیح پایه

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manager = new SuperAdminManager();
    if ($manager->login($_POST['username'], $_POST['password'])) {
        header('Location: ' . $base_url); // هدایت به صفحه اصلی پنل
        exit;
    }
    $error = 'نام کاربری یا رمز عبور اشتباه است.';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>ورود به پنل سوپر ادمین</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo $base_url; ?>styles.css">
</head>

<body>
    <div class="login-container">
        <form method="POST" action="<?php echo $base_url; ?>login.php" class="login-form">
            <h1>ورود به پنل مدیریت</h1>
            <?php if ($error): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">ورود</button>
        </form>
    </div>
</body>

</html>