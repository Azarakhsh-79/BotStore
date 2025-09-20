<?php

namespace Config;

use Dotenv\Dotenv;
use Exception;
use InvalidPathException;

class AppConfig
{
    private static array $loadedConfigs = [];
    private static ?string $currentBotId = null;

    public static function init(string $botId): void
    {
        if (empty($botId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $botId)) {
            throw new Exception("Bot ID is invalid.");
        }
        self::$currentBotId = $botId;
        self::load();
    }

    public static function getDbConfig()
    {
        $dbConfig = self::get('database');
        if (empty($dbConfig)) {
            throw new Exception("Database configuration for bot '" . self::$currentBotId . "' is not loaded or is incomplete.");
        }

        return [
            'host'     => $dbConfig['host'] ?? 'localhost',
            'database' => $dbConfig['database'] ?? '',
            'username' => $dbConfig['username'] ?? '',
            'password' => $dbConfig['password'] ?? ''
        ];
    }
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if (self::$currentBotId === null) {
            throw new Exception("AppConfig has not been initialized. Please call AppConfig::init() first.");
        }

        $config = self::$loadedConfigs[self::$currentBotId] ?? null;

        if ($key === null) {
            return $config;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($config) && array_key_exists($segment, $config)) {
                $config = $config[$segment];
            } else {
                return $default;
            }
        }

        return $config;
    }


    private static function load(): void
    {
        if (isset(self::$loadedConfigs[self::$currentBotId])) {
            return;
        }

        $botId = self::$currentBotId;
        $envFilePath = __DIR__ . '/' . $botId . '.env';

        try {
            if (!is_readable($envFilePath)) {
                throw new Exception("Configuration file '{$botId}.env' not found or is not readable.");
            }

            $content = file_get_contents($envFilePath);
            if ($content === false) {
                throw new Exception("Could not read the content of '{$botId}.env'.");
            }

            $env = Dotenv::parse($content);

            // Manual validation
            $required = ['DB_HOST', 'DB_USERNAME', 'DB_DATABASE', 'BOT_TOKEN', 'BOT_LINK'];
            foreach ($required as $key) {
                if (empty($env[$key])) {
                    throw new Exception("Required configuration '{$key}' is missing or empty in '{$botId}.env'.");
                }
            }
            if (!isset($env['DB_PASSWORD'])) {
                throw new Exception("Required configuration 'DB_PASSWORD' is missing in '{$botId}.env'.");
            }

            self::$loadedConfigs[$botId] = [
                'database' => [
                    'host' => $env['DB_HOST'],
                    'username' => $env['DB_USERNAME'],
                    'password' => $env['DB_PASSWORD'],
                    'database' => $env['DB_DATABASE'],
                ],
                'bot' => [
                    'token' => $env['BOT_TOKEN'],
                    'merchant_id' => $env['MERCHANT_ID'] ?? '',
                    'bot_link' => $env['BOT_LINK'],
                    'bot_web' => $env['BOT_WEB'] ?? '',
                ],
            ];
        } catch (Exception $e) {
            throw new Exception("Configuration Error for bot '{$botId}': " . $e->getMessage());
        }
    }

    public static function getMasterDbConfig(): array
    {
        // این بخش را جایگزین کنید
        if (!defined('ROOT_PATH')) {
            throw new Exception("ROOT_PATH constant is not defined. Please check your bootstrap.php file.");
        }

        $masterEnvPath = ROOT_PATH;
        $fullFilePath = $masterEnvPath . DIRECTORY_SEPARATOR . 'master.env'; // استفاده از DIRECTORY_SEPARATOR برای سازگاری بهتر

        if (!file_exists($fullFilePath) || !is_readable($fullFilePath)) {
            // این پیام خطا مسیر دقیق را به شما نشان می‌دهد
            throw new Exception("Master configuration file (master.env) not found or is not readable. Tried to access: " . $fullFilePath);
        }

        $dotenv = Dotenv::createImmutable($masterEnvPath, 'master.env');
        $dotenv->load();
        return [
            'host' => $_ENV['MASTER_DB_HOST'] ?? '',
            'database' => $_ENV['MASTER_DB_DATABASE'] ?? '',
            'username' => $_ENV['MASTER_DB_USERNAME'] ?? '',
            'password' => $_ENV['MASTER_DB_PASSWORD'] ?? '',
            'admin_user' => $_ENV['SUPER_ADMIN_USER'] ?? '',
            'admin_pass' => $_ENV['SUPER_ADMIN_PASS'] ?? ''
        ];
    }
    public static function getCurrentBotId(): ?string
    {
        return self::$currentBotId;
    }
}
