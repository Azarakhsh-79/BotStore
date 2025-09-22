<?php
require_once __DIR__ . '/../../bootstrap.php';

use Bot\SuperAdminManager;
use Bot\jdf;

session_start();
$manager = new SuperAdminManager();

// مسیر پایه برای لینک‌ها
$base_url = '/NewBot/public/superadmin/';

if (!$manager->checkAuth()) {
    header('Location: ' . $base_url . 'login.php');
    exit;
}

$action = $_REQUEST['action'] ?? 'list';
$message = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'save') {
            $manager->saveBot($_POST);
            $message = ['type' => 'success', 'text' => 'ربات با موفقیت ذخیره شد.'];
            $action = 'list';
        } elseif ($action === 'delete') {
            $manager->deleteBot((int)$_POST['id']);
            $message = ['type' => 'success', 'text' => 'ربات با موفقیت حذف شد.'];
            $action = 'list';
        }
    }

    if ($action === 'set_webhook') {
        $result = $manager->setWebhook((int)$_GET['id']);
        $message = $result['ok']
            ? ['type' => 'success', 'text' => 'وبهوک با موفقیت تنظیم شد.']
            : ['type' => 'danger', 'text' => 'خطا در تنظیم وبهوک: ' . htmlspecialchars($result['description'])];
        $action = 'list';
    } elseif ($action === 'delete_webhook') {
        $result = $manager->deleteWebhook((int)$_GET['id']);
        $message = $result['ok']
            ? ['type' => 'success', 'text' => 'وبهوک با موفقیت حذف شد.']
            : ['type' => 'danger', 'text' => 'خطا در حذف وبهوک: ' . htmlspecialchars($result['description'])];
        $action = 'list';
    } elseif ($action === 'get_webhook_info') {
        $result = $manager->getWebhookInfo((int)$_GET['id']);
        if ($result['ok']) {
            $info = json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $message = ['type' => 'success', 'text' => "<strong>اطلاعات وبهوک:</strong><pre>" . htmlspecialchars($info) . "</pre>"];
        } else {
            $message = ['type' => 'danger', 'text' => 'خطا در دریافت اطلاعات وبهوک: ' . htmlspecialchars($result['description'])];
        }
        $action = 'list';
    } elseif ($action === 'logout') {
        $manager->logout();
        header('Location: ' . $base_url . 'login.php');
        exit;
    }
} catch (Exception $e) {
    $message = ['type' => 'danger', 'text' => 'یک خطای سیستمی رخ داد: ' . $e->getMessage()];
}

$bots = ($action === 'list') ? $manager->getBots() : [];
$bot_to_edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $bot_to_edit = $manager->getBot((int)$_GET['id']);
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>پنل سوپر ادمین</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo $base_url; ?>styles.css">
    <link rel="stylesheet" href="<?php echo $base_url; ?>datepicker/wheel-datepicker.css">
</head>

<body>
    <div class="container">
        <header>
            <h1>🤖 مدیریت ربات‌ها</h1>
            <a href="<?php echo $base_url; ?>?action=logout" class="logout">خروج</a>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message['type']; ?>"><?php echo $message['text']; ?></div>
        <?php endif; ?>

        <?php if ($action === 'new' || $action === 'edit'): ?>
            <h2><?php echo $action === 'new' ? 'افزودن ربات جدید' : 'ویرایش ربات'; ?></h2>
            <div class="form-container">
                <form action="<?php echo $base_url; ?>" method="POST">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo $bot_to_edit['id'] ?? ''; ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="bot_name">نام فروشگاه</label>
                            <input type="text" id="bot_name" name="bot_name" value="<?php echo htmlspecialchars($bot_to_edit['bot_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="bot_id_string">شناسه ربات (برای URL)</label>
                            <input type="text" id="bot_id_string" name="bot_id_string" value="<?php echo htmlspecialchars($bot_to_edit['bot_id_string'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="bot_token">توکن ربات</label>
                            <input type="text" id="bot_token" name="bot_token" value="<?php echo htmlspecialchars($bot_to_edit['bot_token'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="status">وضعیت</label>
                            <select id="status" name="status">
                                <option value="active" <?php echo ($bot_to_edit['status'] ?? '') === 'active' ? 'selected' : ''; ?>>فعال</option>
                                <option value="inactive" <?php echo ($bot_to_edit['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>غیرفعال</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="subscription_expires_at">تاریخ انقضای اشتراک (اختیاری)</label>
                            <input type="text" id="subscription_expires_at" name="subscription_expires_at"
                                value="<?php echo !empty($bot_to_edit['subscription_expires_at']) ? date('Y-m-d H:i', strtotime($bot_to_edit['subscription_expires_at'])) : ''; ?>"
                                autocomplete="off" placeholder="برای تاریخ نامحدود، خالی بگذارید">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">ذخیره</button>
                        <a href="<?php echo $base_url; ?>" class="btn btn-secondary">انصراف</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <div style="margin-bottom: 20px;">
                <a href="<?php echo $base_url; ?>?action=new" class="btn btn-primary">➕ افزودن ربات جدید</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام فروشگاه</th>
                        <th>وضعیت</th>
                        <th>تاریخ انقضا</th>
                        <th style="width: 480px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bots as $bot): ?>
                        <tr>
                            <td><?php echo $bot['id']; ?></td>
                            <td><?php echo htmlspecialchars($bot['bot_name']); ?></td>
                            <td>
                                <?php
                                $is_expired = $bot['subscription_expires_at'] !== null && strtotime($bot['subscription_expires_at']) < time();
                                $status_class = $is_expired ? 'status-expired' : 'status-' . $bot['status'];
                                $status_text = $is_expired ? 'منقضی شده' : ($bot['status'] === 'active' ? 'فعال' : 'غیرفعال');
                                ?>
                                <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td>
                                <?php echo $bot['subscription_expires_at'] ? jdf::jdate('Y/m/d H:i', strtotime($bot['subscription_expires_at'])) : 'نامحدود'; ?>
                            </td>
                            <td class="actions">
                                <div class="action-buttons">
                                    <a href="<?php echo $base_url; ?>?action=set_webhook&id=<?php echo $bot['id']; ?>" class="btn btn-success">تنظیم وبهوک</a>
                                    <a href="<?php echo $base_url; ?>?action=get_webhook_info&id=<?php echo $bot['id']; ?>" class="btn btn-secondary">اطلاعات</a>
                                    <a href="<?php echo $base_url; ?>?action=delete_webhook&id=<?php echo $bot['id']; ?>" class="btn btn-danger">حذف وبهوک</a>
                                    <a href="<?php echo $base_url; ?>?action=edit&id=<?php echo $bot['id']; ?>" class="btn btn-secondary">ویرایش</a>
                                    <form action="<?php echo $base_url; ?>" method="POST" onsubmit="return confirm('آیا از حذف کامل این ربات مطمئن هستید؟');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $bot['id']; ?>">
                                        <button type="submit" class="btn btn-danger">حذف ربات</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script src="<?php echo $base_url; ?>datepicker/wheel-datepicker.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const datePickerInput = document.getElementById('subscription_expires_at');
            if (datePickerInput) {
                new WheelDatePicker({
                    el: datePickerInput,
                    format: 'YYYY-MM-DD HH:mm', // فرمت ذخیره سازی در دیتابیس
                    sync: true,
                    jalali: true,
                    responsive: true
                });
            }
        });
    </script>

</body>

</html>