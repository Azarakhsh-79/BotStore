<?php
require_once __DIR__ . '/../bootstrap.php';

use Config\AppConfig;
use Bot\BotHandler;
use Bot\SuperAdminManager;
use Bot\Logger;

// دریافت bot_id از URL
$botId = $_GET['bot_id'] ?? null;

if (!$botId) {
    http_response_code(400);
    exit('Bot ID is required.');
}

// بررسی سریع وضعیت ربات در جدول managed_bots قبل از init
try {
    $adminManager = new SuperAdminManager();
    $check = $adminManager->isBotAllowedToRun($botId);
    if (!$check['allowed']) {
        http_response_code(200);
        Logger::log('warning', 'Bot Inactive', "Request blocked: " . $check['reason']);
        exit('Bot is not active.');
    }
} catch (\Exception $e) {
    http_response_code(200);
    error_log("Error checking bot status for '{$botId}': " . $e->getMessage());
    exit('Could not verify bot status.');
}

// مقداردهی AppConfig
try {
    AppConfig::init($botId);
} catch (\Exception $e) {
    http_response_code(500);

    $errorMessage = "FATAL: AppConfig Initialization Failed for bot '{$botId}'. ";
    $errorMessage .= "Error: " . $e->getMessage() . ". ";
    $errorMessage .= "Possible causes: ";
    $errorMessage .= "1. 'master.env' file is missing, unreadable, or has incorrect permissions. ";
    $errorMessage .= "2. Master database connection failed (check MASTER_DB_* credentials in master.env). ";
    $errorMessage .= "3. Bot with id_string '{$botId}' does not exist in the 'managed_bots' table or its status is not 'active'.";

    error_log($errorMessage);
    exit('Bot configuration failed.');
}

// حالا که AppConfig مقداردهی شد، می‌توانیم از Logger استفاده کنیم
Logger::log('info', 'Bot Init', "AppConfig initialized successfully for bot '{$botId}'.");

// ست کردن timezone
date_default_timezone_set('Asia/Tehran');

// دریافت آپدیت از تلگرام
$update = json_decode(file_get_contents('php://input'), true);

// بررسی دریافت آپدیت
if (!$update) {
    Logger::log('warning', 'No Update', 'No update received from Telegram.');
    exit('No update received');
}

// لاگ کردن آپدیت دریافتی
Logger::log('info', 'Update Received', 'یک به‌روزرسانی از تلگرام دریافت شد.', ['update' => $update]);

// پردازش آپدیت
switch (true) {
    case isset($update['message']):
        $message   = $update['message'];
        $chatId    = $message['chat']['id'] ?? null;
        $text      = $message['text'] ?? '';
        $messageId = $message['message_id'] ?? null;

        $bot = new BotHandler($chatId, $text, $messageId, $message);

        if (isset($message['successful_payment'])) {
            $bot->handleSuccessfulPayment($update);
        } else {
            $bot->handleRequest();
        }
        break;

    case isset($update['callback_query']):
        $callbackQuery = $update['callback_query'];
        $chatId    = $callbackQuery['message']['chat']['id'] ?? null;
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $data      = $callbackQuery['data'] ?? '';

        $bot = new BotHandler($chatId, $data, $messageId, $callbackQuery['message']);
        $bot->handleCallbackQuery($callbackQuery);
        break;

    case isset($update['inline_query']):
        // منطق مربوط به جستجوی inline در اینجا اضافه خواهد شد
        break;

    case isset($update['pre_checkout_query']):
        $preCheckoutQuery = $update['pre_checkout_query'];
        $chatId = $preCheckoutQuery['from']['id'] ?? null;

        $bot = new BotHandler($chatId, '', null, null);
        $bot->handlePreCheckoutQuery($update);
        break;

    default:
        Logger::log('warning', 'Unknown Update Type', 'Unknown update type received', ['update' => $update]);
        break;
}
