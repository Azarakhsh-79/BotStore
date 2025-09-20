<?php
require_once __DIR__ . '/../bootstrap.php';

use Config\AppConfig;
use Bot\BotHandler;
use Bot\SuperAdminManager;
$botId = $_GET['bot_id'] ?? null;

if (!$botId) {
    http_response_code(400); 
    error_log("⚠️ Bot ID is missing from the request URL.");
    exit('Bot ID is required.');
}

try {
    $adminManager = new SuperAdminManager();
    if (!$adminManager->isBotAllowedToRun($botId)) {
        http_response_code(200);
        error_log("🚫 IGNORED: Request for inactive/expired bot '{$botId}'.");
        exit('Bot is not active.');
    }
} catch (\Exception $e) {
    http_response_code(500);
    error_log("❌ Failed to check bot status for '{$botId}': " . $e->getMessage());
    exit('Could not verify bot status.');
}

try {
    AppConfig::init($botId);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("❌ Failed to initialize AppConfig for bot '{$botId}': " . $e->getMessage());
    exit('Configuration failed.');
}

date_default_timezone_set('Asia/Tehran');
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    exit('No update received');
}
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
        error_log("⚠️ Unknown update type: " . json_encode($update));
        break;
}
