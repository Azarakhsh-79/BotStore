<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Config\AppConfig;
use Bot\Database;
use Bot\jdf;

// --- STEP 1: Initialize Config based on bot_id from URL ---
$botId = $_GET['bot_id'] ?? null;
if (!$botId) {
    http_response_code(400);
    die('Bot ID is required.');
}

try {
    AppConfig::init($botId);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Failed to initialize AppConfig for admin panel '{$botId}': " . $e->getMessage());
    die('Configuration failed for this bot.');
}

session_start();

// --- STEP 2: Validate User Token ---
$db = new Database();
$token = $_GET['token'] ?? null;
$user = null;

if (isset($_SESSION['admin_user_id']) && $_SESSION['admin_bot_id'] === $botId) {
    $user = $db->getUserInfo($_SESSION['admin_user_id']);
} elseif ($token) {
    $user = $db->validateAdminToken($token);
    if ($user) {
        $_SESSION['admin_user_id'] = $user['chat_id'];
        $_SESSION['admin_bot_id'] = $botId;
    }
}

if (!$user || !$user['is_admin']) {
    http_response_code(403);
    die('Access Denied. You must access this page through the Telegram bot.');
}

// --- STEP 3: Handle Form Submissions ---
$page = $_GET['page'] ?? 'dashboard';
$flash_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $settingsToUpdate = $_POST['settings'] ?? [];
    foreach ($settingsToUpdate as $key => $value) {
        $db->updateSetting($key, trim($value));
    }
    $flash_message = "تنظیمات با موفقیت ذخیره شد.";
    // Reload settings after update
    $settings = $db->getAllSettings();
} else {
    $settings = $db->getAllSettings();
}

// --- STEP 4: Display Page ---
$jdate = jdf::jdate('l، j F Y');
$storeName = $settings['store_name'] ?? 'پنل مدیریت';

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($storeName) ?></title>
    <link rel="stylesheet" href="/superadmin/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background-color: var(--card-bg-color);
            padding: 10px;
            border-radius: 8px;
        }

        nav a {
            color: var(--text-secondary-color);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 6px;
            transition: background-color 0.2s;
        }

        nav a.active,
        nav a:hover {
            color: #fff;
            background-color: var(--accent-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            color: var(--text-secondary-color);
            font-size: 0.9em;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            background-color: #0d1117;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-color);
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-group textarea {
            min-height: 120px;
        }

        .flash {
            padding: 15px;
            background-color: var(--green-color);
            color: #fff;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }

        button[type="submit"] {
            background-color: var(--accent-color);
            color: #fff;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1em;
            width: 100%;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1><?= htmlspecialchars($storeName) ?></h1>
            <p><?= $jdate ?></p>
        </header>

        <nav>
            <a href="?bot_id=<?= htmlspecialchars($botId) ?>&token=<?= htmlspecialchars($token) ?>&page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">📊 داشبورد</a>
            <a href="?bot_id=<?= htmlspecialchars($botId) ?>&token=<?= htmlspecialchars($token) ?>&page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">⚙️ تنظیمات</a>
        </nav>

        <?php if ($flash_message): ?>
            <div class="flash"><?= $flash_message ?></div>
        <?php endif; ?>

        <?php if ($page === 'settings'): ?>
            <section class="settings-section">
                <h2>تنظیمات ربات</h2>
                <form method="POST" action="?bot_id=<?= htmlspecialchars($botId) ?>&token=<?= htmlspecialchars($token) ?>&page=settings">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="store_name">نام فروشگاه</label>
                            <input type="text" id="store_name" name="settings[store_name]" value="<?= htmlspecialchars($settings['store_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="delivery_price">هزینه ارسال (تومان)</label>
                            <input type="number" id="delivery_price" name="settings[delivery_price]" value="<?= htmlspecialchars($settings['delivery_price'] ?? '0') ?>">
                        </div>
                        <div class="form-group">
                            <label for="tax_percent">درصد مالیات</label>
                            <input type="number" id="tax_percent" name="settings[tax_percent]" value="<?= htmlspecialchars($settings['tax_percent'] ?? '0') ?>">
                        </div>
                        <div class="form-group">
                            <label for="support_id">آیدی پشتیبانی (با @)</label>
                            <input type="text" id="support_id" name="settings[support_id]" value="<?= htmlspecialchars($settings['support_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="card_number">شماره کارت</label>
                            <input type="text" id="card_number" name="settings[card_number]" value="<?= htmlspecialchars($settings['card_number'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="card_holder_name">نام صاحب حساب</label>
                            <input type="text" id="card_holder_name" name="settings[card_holder_name]" value="<?= htmlspecialchars($settings['card_holder_name'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="main_menu_text">متن منوی اصلی</label>
                            <textarea id="main_menu_text" name="settings[main_menu_text]"><?= htmlspecialchars($settings['main_menu_text'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="store_rules">قوانین فروشگاه</label>
                            <textarea id="store_rules" name="settings[store_rules]"><?= htmlspecialchars($settings['store_rules'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <button type="submit">ذخیره تغییرات</button>
                </form>
            </section>
        <?php else: // Dashboard Page 
        ?>
            <?php $stats = $db->getStatsSummary(); ?>
            <section class="stats-section">
                <h2>👥 کاربران</h2>
                <div class="stats-grid user-stats">
                    <div class="stat-card">
                        <h3>کاربران کل</h3>
                        <p class="stat-number"><?= number_format($stats['total_users']) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>کاربران جدید امروز</h3>
                        <p class="stat-number"><?= number_format($stats['new_users_today']) ?></p>
                        <span class="stat-subtext <?= $stats['new_users_change_percent'] >= 0 ? '' : 'negative' ?>">
                            <?= $stats['new_users_change_percent'] >= 0 ? '▲' : '▼' ?>
                            <?= abs($stats['new_users_change_percent']) ?>% نسبت به دیروز
                        </span>
                    </div>
                    <div class="stat-card">
                        <h3>کاربران فعال امروز</h3>
                        <p class="stat-number"><?= number_format($stats['active_users_today']) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>فعال در ۷ روز گذشته</h3>
                        <p class="stat-number"><?= number_format($stats['active_users_last_7_days']) ?></p>
                    </div>
                </div>
            </section>
            <section class="stats-section">
                <h2>📊 فعالیت و فروش</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>درآمد امروز</h3>
                        <p class="stat-number"><?= number_format($stats['todays_revenue']) ?> <small>تومان</small></p>
                        <span class="stat-subtext">سفارشات تایید شده</span>
                    </div>
                    <div class="stat-card">
                        <h3>سفارشات در انتظار</h3>
                        <p class="stat-number"><?= number_format($stats['pending_invoices']) ?></p>
                        <span class="stat-subtext">نیازمند بررسی</span>
                    </div>
                    <div class="stat-card">
                        <h3>محصولات رو به اتمام</h3>
                        <p class="stat-number danger"><?= number_format($stats['low_stock_products']) ?></p>
                        <span class="stat-subtext">موجودی کمتر از ۵ عدد</span>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <script>
        if (window.Telegram && window.Telegram.WebApp) {
            Telegram.WebApp.ready();
            Telegram.WebApp.expand();
            Telegram.WebApp.setHeaderColor('#0d1117');
            Telegram.WebApp.setBackgroundColor('#0d1117');
        }
    </script>
</body>

</html>