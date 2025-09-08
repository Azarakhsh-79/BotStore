<?php
require_once __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('Asia/Tehran');

use Bot\BotHandler;

$update = json_decode(file_get_contents('php://input'), true);
// error_log("Update: " . print_r($update ,true));
if (!$update) {
    exit('No update received');
}

switch (true) {
    case isset($update['inline_query']):
    
        $inlineQueryHandler->handleInlineQuery($update['inline_query']);
        break;

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

        $bot = new BotHandler($chatId, '', $messageId, $callbackQuery['message']);
        $bot->handleCallbackQuery($callbackQuery);
        break;

    case isset($update['pre_checkout_query']):
       
        $bot->handlePreCheckoutQuery($update);
        break;

    default:
        error_log("⚠️ Unknown update type: " . json_encode($update));
        break;
}
