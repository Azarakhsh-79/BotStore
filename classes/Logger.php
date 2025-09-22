<?php

namespace Bot;

use Config\AppConfig;
use Throwable;

class Logger
{
    private string $logFilePath;
    private string $botToken;
    private string $chatId;

    // نگهداری instance برای متدهای استاتیک
    private static ?self $instance = null;

    // سازنده خصوصی برای Singleton
    private function __construct()
    {
        $botId = AppConfig::getCurrentBotId();
        if (!$botId) {
            throw new \Exception("Bot ID not initialized for Logger.");
        }

        $dataDir = __DIR__ . '/../logs';
        if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

        $this->logFilePath = "{$dataDir}/{$botId}.log";

        $config = AppConfig::get();
        $this->botToken = $config['bot']['token'] ?? '';
        $this->chatId   = $config['bot']['log_chat'] ?? '@mybugsram';
    }

    // دسترسی به instance
    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * ثبت لاگ به صورت استاتیک
     *
     * @param string $level info|success|warning|error
     * @param string $title عنوان
     * @param string $message پیام
     * @param array $context داده‌های اضافی
     * @param bool $sendToTelegram ارسال به تلگرام یا خیر
     */
    public static function log(string $level, string $title, string $message, array $context = [], bool $sendToTelegram = false): void
    {
        $self = self::getInstance();

        $emojis = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];
        $emoji  = $emojis[strtolower($level)] ?? '📝';
        $timestamp = date('[Y-m-d H:i:s]');

        // مخفی کردن توکن و پسورد
        foreach ($context as $key => $value) {
            if (stripos($key, 'token') !== false || stripos($key, 'password') !== false) {
                $context[$key] = '[HIDDEN]';
            }
        }

        $logText = "$timestamp [$level] $title - $message";
        if (!empty($context)) {
            $logText .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($self->logFilePath, $logText . PHP_EOL . str_repeat('-', 80) . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($sendToTelegram && $self->botToken) {
            $contextLines = '';
            foreach ($context as $key => $value) {
                $prettyValue = is_array($value) || is_object($value)
                    ? "<pre>" . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>"
                    : "<code>" . htmlspecialchars((string)$value) . "</code>";
                $contextLines .= "🔹 <b>" . htmlspecialchars($key) . ":</b> {$prettyValue}\n";
            }

            $telegramMessage = "$emoji <b>" . htmlspecialchars($title) . "</b>\n\n" .
                htmlspecialchars($message) . "\n\n" .
                $contextLines .
                "🕒 <i>" . date('Y-m-d H:i:s') . "</i>";

            if (mb_strlen($telegramMessage) > 4000) {
                $telegramMessage = mb_substr($telegramMessage, 0, 3990) . "\n...\n📌 پیام طولانی‌تر بود!";
            }

            try {
                $ch = curl_init("https://api.telegram.org/bot{$self->botToken}/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'chat_id' => $self->chatId,
                        'text' => $telegramMessage,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true
                    ])
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (Throwable $e) {
                file_put_contents($self->logFilePath, "$timestamp [error] Telegram Error - " . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
    }

    // متدهای کمکی استاتیک
    public static function getLogs(): string
    {
        return self::getInstance()->_getLogs();
    }

    public static function clearLogs(): void
    {
        self::getInstance()->_clearLogs();
    }

    // متدهای داخلی غیر استاتیک
    private function _getLogs(): string
    {
        return file_exists($this->logFilePath) ? file_get_contents($this->logFilePath) : '';
    }

    private function _clearLogs(): void
    {
        if (file_exists($this->logFilePath)) {
            file_put_contents($this->logFilePath, '', LOCK_EX);
        }
    }
}
