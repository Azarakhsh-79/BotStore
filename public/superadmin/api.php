
<?php
// ===================================================================
// Enhanced API endpoints for public/superadmin/api.php
?>

<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

use Bot\SuperAdminManager;
use Config\AppConfig;

// Security and Authentication
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        http_response_code(403);
        exit;
    }
}

$action = $_REQUEST['action'] ?? null;
$manager = new SuperAdminManager();
$response = ['ok' => false, 'error' => 'Invalid action.'];

// Rate limiting (simple implementation)
$rateLimitKey = 'api_calls_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$currentCalls = $_SESSION[$rateLimitKey] ?? 0;
$maxCallsPerMinute = 60;

if ($currentCalls > $maxCallsPerMinute) {
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded. Please wait.']);
    http_response_code(429);
    exit;
}

$_SESSION[$rateLimitKey] = $currentCalls + 1;

// Action Routing
try {
    switch ($action) {
        // Dashboard
        case 'get_dashboard_stats':
            $response = ['ok' => true, 'data' => $manager->getDashboardStats()];
            break;

        case 'get_system_health':
            $response = ['ok' => true, 'data' => $manager->getSystemHealth()];
            break;

        // Bot Management
        case 'get_bots':
            $response = ['ok' => true, 'data' => $manager->getAllBots()];
            break;

        case 'get_bot_stats':
            $response = ['ok' => true, 'data' => $manager->getBotStatistics()];
            break;

        case 'get_bot_user_count':
            $botId = trim($_POST['bot_id'] ?? $_GET['bot_id'] ?? '');
            if ($botId) {
                $count = $manager->getBotUserCount($botId);
                $response = ['ok' => true, 'data' => ['count' => $count]];
            } else {
                $response['error'] = "Bot ID is required.";
            }
            break;

        case 'add_bot':
            $botId = trim($_POST['bot_id'] ?? '');
            $botName = trim($_POST['bot_name'] ?? '');
            $botToken = trim($_POST['bot_token'] ?? '');

            if ($botId && $botName && $botToken) {
                if ($manager->createBot($botId, $botName, $botToken)) {
                    $response = ['ok' => true, 'message' => "ربات '{$botId}' با موفقیت ایجاد شد."];
                    $manager->logAction('Bot Created', "Bot '{$botId}' created via API.");
                } else {
                    $response['error'] = $_SESSION['flash_message_error'] ?? "خطا در ایجاد ربات.";
                    unset($_SESSION['flash_message_error']);
                }
            } else {
                $response['error'] = "تمام فیلدها اجباری هستند.";
            }
            break;
        case 'update_all_stats':
            $result = $manager->updateAllBotsStatsCache();
            $response = [
                'ok' => true,
                'message' => "به‌روزرسانی آمار برای " . count($result['success']) . " ربات موفق و برای " . count($result['failed']) . " ربات ناموفق بود.",
                'data' => $result
            ];
            break;
        case 'update_bot':
            $botId = trim($_POST['bot_id'] ?? '');
            $updates = [];

            if (!empty($_POST['bot_name'])) $updates['bot_name'] = trim($_POST['bot_name']);
            if (!empty($_POST['bot_token'])) $updates['bot_token'] = trim($_POST['bot_token']);

            if ($botId && !empty($updates)) {
                if ($manager->updateBot($botId, $updates)) {
                    $response = ['ok' => true, 'message' => "ربات '{$botId}' با موفقیت به‌روزرسانی شد."];
                    $manager->logAction('Bot Updated', "Bot '{$botId}' updated via API.");
                } else {
                    $response['error'] = "خطا در به‌روزرسانی ربات.";
                }
            } else {
                $response['error'] = "اطلاعات کافی ارائه نشده.";
            }
            break;

        case 'delete_bot':
            $botId = trim($_POST['bot_id'] ?? '');
            if ($botId && $manager->deleteBot($botId)) {
                $response = ['ok' => true, 'message' => "ربات '{$botId}' با موفقیت حذف شد."];
                $manager->logAction('Bot Deleted', "Bot '{$botId}' deleted via API.");
            } else {
                $response['error'] = "خطا در حذف ربات.";
            }
            break;

        case 'toggle_status':
            $botId = trim($_POST['bot_id'] ?? '');
            $newStatus = trim($_POST['new_status'] ?? '');
            if ($botId && $newStatus && in_array($newStatus, ['active', 'inactive'])) {
                $manager->updateBotStatus($botId, $newStatus);
                $response = ['ok' => true, 'message' => "وضعیت ربات '{$botId}' به '{$newStatus}' تغییر کرد."];
                $manager->logAction('Status Toggled', "Bot '{$botId}' set to '{$newStatus}' via API.");
            } else {
                $response['error'] = "اطلاعات ناقص یا نامعتبر است.";
            }
            break;

        case 'update_subscription':
            $botId = trim($_POST['bot_id'] ?? '');
            $expiryDate = $_POST['expiry_date'] ?? '';
            if ($botId) {
                $manager->updateSubscription($botId, $expiryDate);
                $response = ['ok' => true, 'message' => "تاریخ انقضای ربات '{$botId}' به‌روز شد."];
                $manager->logAction('Subscription Updated', "Bot '{$botId}' expiry updated via API.");
            } else {
                $response['error'] = "شناسه ربات الزامی است.";
            }
            break;

        // Webhook Management
        case 'manage_webhook':
            $botId = trim($_POST['bot_id'] ?? '');
            $webhookAction = $_POST['webhook_action'] ?? '';
            if ($botId && $webhookAction) {
                $result = $manager->manageWebhook($botId, $webhookAction);
                $response = [
                    'ok' => true,
                    'message' => "نتیجه عملیات '{$webhookAction}' برای '{$botId}':",
                    'data' => $result
                ];
                $manager->logAction('Webhook Action', "Action '{$webhookAction}' on bot '{$botId}' via API.");
            } else {
                $response['error'] = "اطلاعات ناقص است.";
            }
            break;

        // Logs Management
        case 'get_logs':
            $filters = [
                'action' => $_GET['action_filter'] ?? null,
                'date' => $_GET['date_filter'] ?? null,
                'limit' => min((int)($_GET['limit'] ?? 100), 500) // Max 500 records
            ];
            $response = ['ok' => true, 'data' => $manager->getLogs($filters)];
            break;

        case 'clean_old_logs':
            $days = max(1, min((int)($_POST['days'] ?? 30), 365)); // Between 1-365 days
            $deletedRows = $manager->cleanOldLogs($days);
            $response = ['ok' => true, 'message' => "تعداد {$deletedRows} رکورد لاگ حذف شد."];
            $manager->logAction('Logs Cleaned', "Deleted {$deletedRows} log records older than {$days} days.");
            break;

        // Settings Management
        case 'change_admin_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (strlen($newPassword) < 8) {
                $response['error'] = "رمز عبور جدید باید حداقل 8 کاراکتر باشد.";
                break;
            }

            if ($newPassword !== $confirmPassword) {
                $response['error'] = "رمزهای عبور جدید مطابقت ندارند.";
                break;
            }

            if ($manager->changeAdminPassword($currentPassword, $newPassword)) {
                $response = ['ok' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
                $manager->logAction('Password Changed', 'Admin password updated');
            } else {
                $response['error'] = "رمز عبور فعلی اشتباه است.";
            }
            break;

        case 'get_system_settings':
            $response = ['ok' => true, 'data' => $manager->getSystemSettings()];
            break;

        case 'update_system_settings':
            $settings = [];
            $allowedSettings = ['app_url', 'max_bots', 'log_retention_days', 'backup_retention_days'];

            foreach ($allowedSettings as $setting) {
                if (isset($_POST[$setting]) && !empty(trim($_POST[$setting]))) {
                    $settings[$setting] = trim($_POST[$setting]);
                }
            }

            if (!empty($settings)) {
                $manager->updateSystemSettings($settings);
                $response = ['ok' => true, 'message' => 'تنظیمات سیستم با موفقیت به‌روزرسانی شد.'];
                $manager->logAction('Settings Updated', 'System settings updated: ' . implode(', ', array_keys($settings)));
            } else {
                $response['error'] = "هیچ تنظیمات معتبری ارائه نشده.";
            }
            break;

        // Backup Management
        case 'create_backup':
            $backupType = $_POST['backup_type'] ?? 'full';
            if (!in_array($backupType, ['full', 'database', 'configs'])) {
                $response['error'] = "نوع پشتیبان‌گیری نامعتبر است.";
                break;
            }

            $result = $manager->createBackup($backupType);
            if ($result['ok']) {
                $response = $result;
                $manager->logAction('Backup Created', "Created {$backupType} backup: {$result['filename']}");
            } else {
                $response = $result;
            }
            break;

        // Configuration Management
        case 'export_bot_config':
            $botId = trim($_POST['bot_id'] ?? $_GET['bot_id'] ?? '');
            if ($botId) {
                $result = $manager->exportBotConfig($botId);
                $response = $result;
                if ($result['ok']) {
                    $manager->logAction('Config Exported', "Configuration exported for bot '{$botId}'");
                }
            } else {
                $response['error'] = "شناسه ربات الزامی است.";
            }
            break;

        case 'import_bot_config':
            $botId = trim($_POST['bot_id'] ?? '');
            $configContent = $_POST['config_content'] ?? '';

            if ($botId && $configContent) {
                $result = $manager->importBotConfig($botId, $configContent);
                $response = $result;
            } else {
                $response['error'] = "شناسه ربات و محتوای کانفیگ الزامی است.";
            }
            break;

        // Utility endpoints
        case 'ping':
            $response = $manager->ping();
            break;

        case 'get_server_info':
            $response = [
                'ok' => true,
                'data' => [
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'timezone' => date_default_timezone_get(),
                    'current_time' => date('Y-m-d H:i:s'),
                    'disk_free_space' => disk_free_space(__DIR__),
                    'loaded_extensions' => get_loaded_extensions()
                ]
            ];
            break;

        default:
            $response = ['ok' => false, 'error' => 'نوع عملیات پشتیبانی نمی‌شود.'];
            break;
    }
} catch (Exception $e) {
    $response = ['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()];
    http_response_code(500);

    // Log the error
    error_log("SuperAdmin API Error [{$action}]: " . $e->getMessage());
    $manager->logAction('API Error', "Action '{$action}' failed: " . $e->getMessage());
}

// Clean up rate limiting session data older than 1 minute
if (mt_rand(1, 100) <= 5) { // 5% chance to clean up
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'api_calls_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
