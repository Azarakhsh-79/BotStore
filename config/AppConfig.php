<?php

namespace Config;

use Dotenv\Dotenv;
use Exception;
use PDO;

class AppConfig
{
    private static array $loadedConfigs = [];
    private static ?string $currentBotIdString = null;
    private static ?PDO $masterPdo = null;

    /**
     * مقداردهی اولیه تنظیمات برای یک bot_id خاص
     */
    public static function init(string $botId): void
    {
        if (empty($botId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $botId)) {
            throw new Exception("Bot ID is invalid.");
        }
        self::$currentBotIdString = $botId;
        self::load();
    }

    /**
     * بارگذاری تنظیمات ربات از دیتابیس مرکزی
     */
    private static function load(): void
    {
        $botIdString = self::$currentBotIdString;
        if (isset(self::$loadedConfigs[$botIdString])) {
            return;
        }

        try {
            $masterDbConfig = self::getMasterDbConfig();
            self::connectMasterDb($masterDbConfig);

            $stmt = self::$masterPdo->prepare("SELECT id, bot_token, bot_name FROM managed_bots WHERE bot_id_string = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$botIdString]);
            $botData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$botData) {
                throw new Exception("Bot '{$botIdString}' not found or is not active.");
            }

            self::$loadedConfigs[$botIdString] = [
                'bot' => [
                    'id' => (int)$botData['id'], // آیدی عددی ربات
                    'token' => $botData['bot_token'],
                    'name' => $botData['bot_name'],
                ],
                // تمام ربات‌ها از این پس از یک دیتابیس مشترک استفاده می‌کنند
                'database' => [
                    'host' => $masterDbConfig['host'],
                    'username' => $masterDbConfig['username'],
                    'password' => $masterDbConfig['password'],
                    'database' => $masterDbConfig['database'],
                ]
            ];
        } catch (Exception $e) {
            // لاگ کردن خطا برای دیباگ بهتر
            error_log("Configuration Error for bot '{$botIdString}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * دریافت تنظیمات بر اساس کلید
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if (self::$currentBotIdString === null) {
            throw new Exception("AppConfig has not been initialized. Please call AppConfig::init() first.");
        }

        $config = self::$loadedConfigs[self::$currentBotIdString] ?? null;

        if ($key === null) {
            return $config;
        }

        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * دریافت آیدی عددی ربات فعلی
     */
    public static function getCurrentBotId(): ?int
    {
        return self::get('bot.id');
    }

    /**
     * اتصال به دیتابیس اصلی
     */
    private static function connectMasterDb(array $dbConfig): void
    {
        if (self::$masterPdo === null) {
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            self::$masterPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        }
    }

    /**
     * خواندن تنظیمات دیتابیس اصلی از فایل master.env
     */
    // ... کدهای دیگر فایل AppConfig.php ...

    /**
     * خواندن تنظیمات دیتابیس اصلی و ادمین از فایل master.env
     */
    public static function getMasterDbConfig(): array
    {
        // مسیر فایل .env را به ریشه پروژه (یک پوشه بالاتر از config) تنظیم می‌کنیم
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..', 'master.env');
        $dotenv->load();

        // <<-- اصلاحیه اصلی اینجاست
        // کلیدهای admin_user و admin_pass را به آرایه اضافه می‌کنیم
        return [
            'host'       => $_ENV['MASTER_DB_HOST'] ?? 'localhost',
            'database'   => $_ENV['MASTER_DB_DATABASE'] ?? '',
            'username'   => $_ENV['MASTER_DB_USERNAME'] ?? 'root',
            'password'   => $_ENV['MASTER_DB_PASSWORD'] ?? '',
            'admin_user' => $_ENV['SUPER_ADMIN_USER'] ?? '', // این خط اضافه شد
            'admin_pass' => $_ENV['SUPER_ADMIN_PASS'] ?? ''  // این خط اضافه شد
        ];
    }
    public static function getCurrentBotIdString(): ?string
    {
        return self::$currentBotIdString;
    }

}
