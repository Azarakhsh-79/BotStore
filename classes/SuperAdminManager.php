<?php
// classes/Bot/SuperAdminManager.php - Enhanced Version

namespace Bot;

use Config\AppConfig;
use PDO;
use Exception;
use ZipArchive;

class SuperAdminManager
{
    private ?PDO $pdo;
    private string $configPath;
    private string $backupPath;

    public function __construct()
    {
        $dbConfig = AppConfig::getMasterDbConfig();
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->configPath = ROOT_PATH . '/config/';
        $this->backupPath = ROOT_PATH . '/backups/';

        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }

        $this->initializeTables();
    }

    /**
     * Initialize required database tables
     */
    private function initializeTables(): void
    {
        // Create super_admin_logs table if not exists
        $sql = "CREATE TABLE IF NOT EXISTS super_admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_username VARCHAR(100) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_username (admin_username),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->pdo->exec($sql);

        // Create managed_bots table if not exists
        $sql = "CREATE TABLE IF NOT EXISTS managed_bots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id VARCHAR(50) UNIQUE NOT NULL,
            bot_name VARCHAR(255) NOT NULL,
            bot_token VARCHAR(500) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'inactive',
            subscription_expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bot_id (bot_id),
            INDEX idx_status (status),
            INDEX idx_expires_at (subscription_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->pdo->exec($sql);

        // Create system_settings table if not exists
        $sql = "CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->pdo->exec($sql);
    }

    /**
     * Log admin actions
     */
    public function logAction(string $action, ?string $details = null): void
    {
        $sql = "INSERT INTO super_admin_logs (admin_username, action, details, ip_address) VALUES (?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $_SESSION['super_admin_username'] ?? 'system',
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'CLI'
        ]);
    }

    /**
     * Get logs with filtering
     */
    public function getLogs(array $filters = []): array
    {
        $sql = "SELECT * FROM super_admin_logs WHERE 1=1";
        $params = [];

        if (!empty($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['date'])) {
            $sql .= " AND DATE(created_at) = ?";
            $params[] = $filters['date'];
        }

        $sql .= " ORDER BY created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        } else {
            $sql .= " LIMIT 100";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Create a new bot
     */
    public function createBot(string $botId, string $botName, string $botToken): bool
    {
        // Validate inputs
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $botId)) {
            $_SESSION['flash_message_error'] = "شناسه ربات باید فقط شامل حروف انگلیسی، اعداد، خط تیره و زیرخط باشد.";
            return false;
        }

        if (strlen($botId) < 3 || strlen($botId) > 50) {
            $_SESSION['flash_message_error'] = "شناسه ربات باید بین 3 تا 50 کاراکتر باشد.";
            return false;
        }

        if ($this->getBotById($botId)) {
            $_SESSION['flash_message_error'] = "خطا: ربات '{$botId}' از قبل وجود دارد.";
            return false;
        }

        $envFilePath = $this->configPath . $botId . '.env';
        if (file_exists($envFilePath)) {
            $_SESSION['flash_message_error'] = "خطا: فایل کانفیگ برای '{$botId}' از قبل وجود دارد.";
            return false;
        }

        // Validate bot token with Telegram API
        if (!$this->validateBotToken($botToken)) {
            $_SESSION['flash_message_error'] = "توکن ربات نامعتبر است یا ربات در دسترس نیست.";
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            // Insert into database
            $sql = "INSERT INTO managed_bots (bot_id, bot_name, bot_token, status) VALUES (?, ?, ?, 'inactive')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$botId, $botName, $botToken]);

            // Create config file
            $this->createBotConfigFile($botId, $botName, $botToken);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $_SESSION['flash_message_error'] = "خطا در ایجاد ربات: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Validate bot token with Telegram API
     */
    private function validateBotToken(string $token): bool
    {
        $url = "https://api.telegram.org/bot{$token}/getMe";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['ok']) && $data['ok'] === true;
    }

    /**
     * Create bot configuration file
     */
    private function createBotConfigFile(string $botId, string $botName, string $botToken): bool
    {
        $masterDbConfig = AppConfig::getMasterDbConfig();
        $botWeb = $_ENV['APP_URL'] ?? 'https://YOUR_DOMAIN.com';

        $envContent = <<<EOT
# Webhook commands for bot: {$botId}
# Set webhook: curl -X POST "https://api.telegram.org/bot{$botToken}/setWebhook" -d "url={$botWeb}/bot.php?bot_id={$botId}"
# Delete webhook: curl -X POST "https://api.telegram.org/bot{$botToken}/deleteWebhook"
# Get webhook info: curl -X GET "https://api.telegram.org/bot{$botToken}/getWebhookInfo"

# --------------------------------
# DATABASE CONFIGURATION
# --------------------------------
DB_HOST={$masterDbConfig['host']}
DB_DATABASE={$botId}_bot_db
DB_USERNAME={$masterDbConfig['username']}
DB_PASSWORD={$masterDbConfig['password']}

# --------------------------------
# TELEGRAM BOT CONFIGURATION
# --------------------------------
BOT_TOKEN={$botToken}
BOT_LINK=https://t.me/YOUR_BOT_USERNAME
BOT_WEB={$botWeb}
BOT_LOGO=./uploads/bot/default.png
STORE_NAME="{$botName}"

# --------------------------------
# OTHER SERVICES (Optional)
# --------------------------------
MERCHANT_ID=
PAYMENT_GATEWAY_URL=
API_RATE_LIMIT=60
MAX_USERS=10000
DEBUG_MODE=false

# --------------------------------
# SECURITY SETTINGS
# --------------------------------
ADMIN_CHAT_ID=
WEBHOOK_SECRET=
ALLOWED_IPS=

# --------------------------------
# FEATURE TOGGLES
# --------------------------------
ENABLE_PAYMENTS=true
ENABLE_FILE_UPLOAD=true
ENABLE_ANALYTICS=true
EOT;

        $envFilePath = $this->configPath . $botId . '.env';
        return file_put_contents($envFilePath, $envContent) !== false;
    }

    /**
     * Update bot information
     */
    public function updateBot(string $botId, array $updates): bool
    {
        $allowedFields = ['bot_name', 'bot_token', 'status'];
        $setParts = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields) && !empty($value)) {
                $setParts[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($setParts)) {
            return false;
        }

        $params[] = $botId;
        $sql = "UPDATE managed_bots SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE bot_id = ?";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a bot
     */
    public function deleteBot(string $botId): bool
    {
        try {
            $this->pdo->beginTransaction();

            // Delete from database
            $stmt = $this->pdo->prepare("DELETE FROM managed_bots WHERE bot_id = ?");
            $deleted = $stmt->execute([$botId]);

            // Remove config file
            $envFilePath = $this->configPath . $botId . '.env';
            if (file_exists($envFilePath)) {
                unlink($envFilePath);
            }

            // Remove bot-specific database if exists
            $this->dropBotDatabase($botId);

            $this->pdo->commit();
            return $deleted;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error deleting bot {$botId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop bot-specific database
     */
    private function dropBotDatabase(string $botId): void
    {
        try {
            $dbName = $botId . '_bot_db';
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        } catch (Exception $e) {
            error_log("Warning: Could not drop database for bot {$botId}: " . $e->getMessage());
        }
    }

    /**
     * Get all bots
     */
    public function getAllBots(): array
    {
        // Now this query also fetches the cached stats directly
        $stmt = $this->pdo->query("
            SELECT *,
                   DATEDIFF(subscription_expires_at, NOW()) as days_until_expiry
            FROM managed_bots
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }


    /**
     * Get bot by ID
     */
    public function getBotById(string $botId): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM managed_bots WHERE bot_id = ?");
        $stmt->execute([$botId]);
        return $stmt->fetch();
    }

    /**
     * Update bot status
     */
    public function updateBotStatus(string $botId, string $status): bool
    {
        if (!in_array($status, ['active', 'inactive'])) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE managed_bots SET status = ?, updated_at = NOW() WHERE bot_id = ?");
        return $stmt->execute([$status, $botId]);
    }

    /**
     * Update subscription expiry
     */
    public function updateSubscription(string $botId, ?string $expiryDate): bool
    {
        $dateValue = empty($expiryDate) ? null : $expiryDate . ' 23:59:59';
        $stmt = $this->pdo->prepare("UPDATE managed_bots SET subscription_expires_at = ?, updated_at = NOW() WHERE bot_id = ?");
        return $stmt->execute([$dateValue, $botId]);
    }

    /**
     * Check if bot is allowed to run
     */
    public function isBotAllowedToRun(string $botId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, subscription_expires_at FROM managed_bots WHERE bot_id = ? LIMIT 1"
        );
        $stmt->execute([$botId]);
        $bot = $stmt->fetch();

        if (!$bot || $bot['status'] !== 'active') {
            return false;
        }

        if ($bot['subscription_expires_at'] !== null && strtotime($bot['subscription_expires_at']) < time()) {
            $this->updateBotStatus($botId, 'inactive');
            $this->logAction('Bot Auto-Deactivated', "Bot '{$botId}' deactivated due to expired subscription.");
            return false;
        }

        return true;
    }

    /**
     * Manage webhook operations
     */
    public function manageWebhook(string $botId, string $action): array
    {
        $bot = $this->getBotById($botId);
        if (!$bot || !$bot['bot_token']) {
            return ['ok' => false, 'description' => 'Bot token not found.'];
        }

        try {
            AppConfig::init($botId);
        } catch (Exception $e) {
            return ['ok' => false, 'description' => 'Failed to load bot configuration: ' . $e->getMessage()];
        }

        $token = $bot['bot_token'];
        $baseUrl = "https://api.telegram.org/bot{$token}/";

        switch ($action) {
            case 'set':
                $webhookUrl = AppConfig::get('bot.bot_web') . "/bot.php?bot_id={$botId}";
                $url = $baseUrl . "setWebhook";
                $postData = ['url' => $webhookUrl];
                break;

            case 'delete':
                $url = $baseUrl . "deleteWebhook";
                $postData = [];
                break;

            case 'getInfo':
            default:
                $url = $baseUrl . "getWebhookInfo";
                $postData = null;
                break;
        }

        return $this->makeTelegramRequest($url, $postData);
    }

    /**
     * Make request to Telegram API
     */
    private function makeTelegramRequest(string $url, ?array $postData = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $postData ? 'POST' : 'GET',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postData ? http_build_query($postData) : '',
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['ok' => false, 'description' => 'Failed to connect to Telegram API'];
        }

        $result = json_decode($response, true);
        return $result ?: ['ok' => false, 'description' => 'Invalid response from Telegram API'];
    }
    public function updateBotStatsCache(string $botId): array
    {
        $stats = [
            'user_count' => 0,
            'total_revenue' => 0.00,
            'pending_invoices' => 0,
        ];

        try {
            AppConfig::init($botId);
            $dbConfig = AppConfig::getDbConfig();
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
            $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

            // Get user count
            $stmt = $botPdo->query("SELECT COUNT(*) FROM users");
            $stats['user_count'] = (int)$stmt->fetchColumn();

            // Get financial stats
            $stmt = $botPdo->query("
                SELECT
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_invoices
                FROM invoices
            ");
            $financials = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_revenue'] = (float)$financials['total_revenue'];
            $stats['pending_invoices'] = (int)$financials['pending_invoices'];

            // Update the main managed_bots table with the fresh stats
            $updateSql = "
                UPDATE managed_bots SET
                    user_count = ?,
                    total_revenue = ?,
                    pending_invoices = ?,
                    stats_last_updated_at = NOW()
                WHERE bot_id = ?
            ";
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([$stats['user_count'], $stats['total_revenue'], $stats['pending_invoices'], $botId]);

            return ['ok' => true, 'bot_id' => $botId, 'stats' => $stats];
        } catch (Exception $e) {
            error_log("Failed to update stats cache for bot {$botId}: " . $e->getMessage());
            return ['ok' => false, 'bot_id' => $botId, 'error' => $e->getMessage()];
        }
    }


    public function updateAllBotsStatsCache(): array
    {
        $bots = $this->getAllBots();
        $results = ['success' => [], 'failed' => []];
        foreach ($bots as $bot) {
            $result = $this->updateBotStatsCache($bot['bot_id']);
            if ($result['ok']) {
                $results['success'][] = $bot['bot_id'];
            } else {
                $results['failed'][] = $bot['bot_id'];
            }
        }
        $this->logAction('Stats Cache Updated', 'Updated stats for ' . count($results['success']) . ' bots.');
        return $results;
    }
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(): array
    {
        $stats = [
            'total_bots' => 0,
            'active_bots' => 0,
            'total_users' => 0,
            'total_revenue' => 0,
            'pending_invoices' => 0,
            'expiring_soon' => 0,
            'errors' => []
        ];

        // Get all stats from the main table in one go
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_bots,
                SUM(IF(status = 'active', 1, 0)) as active_bots,
                SUM(user_count) as total_users,
                SUM(total_revenue) as total_revenue,
                SUM(pending_invoices) as pending_invoices,
                SUM(IF(subscription_expires_at IS NOT NULL AND subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY), 1, 0)) as expiring_soon
            FROM managed_bots
        ");

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $stats['total_bots'] = (int)$result['total_bots'];
            $stats['active_bots'] = (int)$result['active_bots'];
            $stats['total_users'] = (int)$result['total_users'];
            $stats['total_revenue'] = (float)$result['total_revenue'];
            $stats['pending_invoices'] = (int)$result['pending_invoices'];
            $stats['expiring_soon'] = (int)$result['expiring_soon'];
        }

        return $stats;
    }

    /**
     * Get user count for a specific bot
     */
    public function getBotUserCount(string $botId): int
    {
        try {
            AppConfig::init($botId);
            $dbConfig = AppConfig::getDbConfig();

            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
            $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

            $stmt = $botPdo->query("SELECT COUNT(*) FROM users");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Error getting user count for bot {$botId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Change admin password
     */
    public function changeAdminPassword(string $currentPassword, string $newPassword): bool
    {
        $masterConfig = AppConfig::getMasterDbConfig();
        $adminPassHash = $masterConfig['admin_pass'];

        if (!password_verify($currentPassword, $adminPassHash)) {
            return false;
        }

        // Update password in master.env file
        $masterEnvPath = __DIR__ . '/../../master.env';
        if (!file_exists($masterEnvPath)) {
            throw new Exception('Master configuration file not found');
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $content = file_get_contents($masterEnvPath);

        $updatedContent = preg_replace(
            '/^ADMIN_PASS=.*$/m',
            'ADMIN_PASS=' . $newPasswordHash,
            $content
        );

        return file_put_contents($masterEnvPath, $updatedContent) !== false;
    }

    /**
     * Create system backup
     */
    public function createBackup(string $type = 'full'): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_{$type}_{$timestamp}";
        $backupDir = $this->backupPath . $backupName . '/';

        if (!mkdir($backupDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create backup directory'];
        }

        try {
            switch ($type) {
                case 'database':
                    $this->backupDatabase($backupDir);
                    break;

                case 'configs':
                    $this->backupConfigs($backupDir);
                    break;

                case 'full':
                default:
                    $this->backupDatabase($backupDir);
                    $this->backupConfigs($backupDir);
                    break;
            }

            // Create ZIP file
            $zipFile = $this->backupPath . $backupName . '.zip';
            if ($this->createZipFromDirectory($backupDir, $zipFile)) {
                // Clean up temporary directory
                $this->removeDirectory($backupDir);

                return [
                    'ok' => true,
                    'filename' => $backupName . '.zip',
                    'download_url' => '/superadmin/download_backup.php?file=' . urlencode($backupName . '.zip'),
                    'size' => filesize($zipFile)
                ];
            } else {
                throw new Exception('Failed to create ZIP file');
            }
        } catch (Exception $e) {
            // Clean up on error
            $this->removeDirectory($backupDir);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Backup database
     */
    private function backupDatabase(string $backupDir): void
    {
        $dbConfig = AppConfig::getMasterDbConfig();

        // Backup master database
        $masterDumpFile = $backupDir . 'master_database.sql';
        $this->createDatabaseDump($dbConfig, $masterDumpFile);

        // Backup all bot databases
        $bots = $this->getAllBots();
        foreach ($bots as $bot) {
            try {
                AppConfig::init($bot['bot_id']);
                $botDbConfig = AppConfig::getDbConfig();
                $botDumpFile = $backupDir . "bot_{$bot['bot_id']}_database.sql";
                $this->createDatabaseDump($botDbConfig, $botDumpFile);
            } catch (Exception $e) {
                // Log error but continue with other bots
                error_log("Failed to backup database for bot {$bot['bot_id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Create database dump
     */
    private function createDatabaseDump(array $dbConfig, string $outputFile): void
    {
        $command = sprintf(
            'mysqldump -h%s -u%s -p%s %s > %s',
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            // Fallback to PHP-based dump if mysqldump fails
            $this->createPhpDatabaseDump($dbConfig, $outputFile);
        }
    }

    /**
     * PHP-based database dump (fallback)
     */
    private function createPhpDatabaseDump(array $dbConfig, string $outputFile): void
    {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

        $dump = "-- Database dump created by SuperAdmin Panel\n";
        $dump .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

        // Get all tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Get table structure
            $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $dump .= $createTable['Create Table'] . ";\n\n";

            // Get table data
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $dump .= "INSERT INTO `{$table}` VALUES\n";
                $valueStrings = [];

                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    $valueStrings[] = '(' . implode(',', $values) . ')';
                }

                $dump .= implode(",\n", $valueStrings) . ";\n\n";
            }
        }

        file_put_contents($outputFile, $dump);
    }

    /**
     * Backup configuration files
     */
    private function backupConfigs(string $backupDir): void
    {
        $configBackupDir = $backupDir . 'configs/';
        mkdir($configBackupDir, 0755, true);

        // Copy all .env files
        $configFiles = glob($this->configPath . '*.env');
        foreach ($configFiles as $file) {
            copy($file, $configBackupDir . basename($file));
        }

        // Copy master.env
        $masterEnv = __DIR__ . '/../../master.env';
        if (file_exists($masterEnv)) {
            copy($masterEnv, $configBackupDir . 'master.env');
        }
    }

    /**
     * Create ZIP from directory
     */
    private function createZipFromDirectory(string $sourceDir, string $zipFile): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive extension not available');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir));
                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Get system settings
     */
    public function getSystemSettings(): array
    {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = [];

        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    /**
     * Update system setting
     */
    public function updateSystemSetting(string $key, string $value): bool
    {
        $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([$key, $value]);
    }

    /**
     * Update multiple system settings
     */
    public function updateSystemSettings(array $settings): bool
    {
        try {
            $this->pdo->beginTransaction();

            foreach ($settings as $key => $value) {
                $this->updateSystemSetting($key, $value);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $daysToKeep = 30): int
    {
        $sql = "DELETE FROM super_admin_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$daysToKeep]);

        return $stmt->rowCount();
    }

    /**
     * Get system health status
     */
    public function getSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'checks' => []
        ];

        // Check database connection
        try {
            $this->pdo->query("SELECT 1");
            $health['checks']['database'] = ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (Exception $e) {
            $health['checks']['database'] = ['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()];
            $health['status'] = 'unhealthy';
        }

        // Check config directory
        if (is_writable($this->configPath)) {
            $health['checks']['config_dir'] = ['status' => 'ok', 'message' => 'Config directory is writable'];
        } else {
            $health['checks']['config_dir'] = ['status' => 'warning', 'message' => 'Config directory is not writable'];
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }

        // Check backup directory
        if (is_writable($this->backupPath)) {
            $health['checks']['backup_dir'] = ['status' => 'ok', 'message' => 'Backup directory is writable'];
        } else {
            $health['checks']['backup_dir'] = ['status' => 'warning', 'message' => 'Backup directory is not writable'];
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }

        // Check PHP extensions
        $requiredExtensions = ['pdo_mysql', 'curl', 'json', 'zip'];
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $health['checks']["ext_{$ext}"] = ['status' => 'ok', 'message' => "{$ext} extension loaded"];
            } else {
                $health['checks']["ext_{$ext}"] = ['status' => 'error', 'message' => "{$ext} extension not loaded"];
                $health['status'] = 'unhealthy';
            }
        }

        // Check bot configurations
        $invalidBots = 0;
        $bots = $this->getAllBots();
        foreach ($bots as $bot) {
            $configFile = $this->configPath . $bot['bot_id'] . '.env';
            if (!file_exists($configFile)) {
                $invalidBots++;
            }
        }

        if ($invalidBots === 0) {
            $health['checks']['bot_configs'] = ['status' => 'ok', 'message' => 'All bot configurations found'];
        } else {
            $health['checks']['bot_configs'] = ['status' => 'warning', 'message' => "{$invalidBots} bot(s) missing configuration files"];
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }

        return $health;
    }

    /**
     * Get bot statistics
     */
    public function getBotStatistics(): array
    {
        $stats = [];
        $bots = $this->getAllBots();

        foreach ($bots as $bot) {
            try {
                AppConfig::init($bot['bot_id']);
                $dbConfig = AppConfig::getDbConfig();

                $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
                $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

                // Get user count
                $stmt = $botPdo->query("SELECT COUNT(*) FROM users");
                $userCount = (int)$stmt->fetchColumn();

                // Get recent activity (if logs table exists)
                $recentActivity = 0;
                try {
                    $stmt = $botPdo->query("SELECT COUNT(*) FROM user_actions WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                    $recentActivity = (int)$stmt->fetchColumn();
                } catch (Exception $e) {
                    // Table might not exist, ignore error
                }

                $stats[$bot['bot_id']] = [
                    'name' => $bot['bot_name'],
                    'status' => $bot['status'],
                    'users' => $userCount,
                    'recent_activity' => $recentActivity,
                    'expires_at' => $bot['subscription_expires_at'],
                    'days_until_expiry' => $bot['days_until_expiry']
                ];
            } catch (Exception $e) {
                $stats[$bot['bot_id']] = [
                    'name' => $bot['bot_name'],
                    'status' => $bot['status'],
                    'users' => 0,
                    'recent_activity' => 0,
                    'expires_at' => $bot['subscription_expires_at'],
                    'days_until_expiry' => $bot['days_until_expiry'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return $stats;
    }

    /**
     * Ping method for connection checking
     */
    public function ping(): array
    {
        try {
            $this->pdo->query("SELECT 1");
            return ['ok' => true, 'timestamp' => time()];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Database connection failed'];
        }
    }

    /**
     * Export bot configuration
     */
    public function exportBotConfig(string $botId): array
    {
        $bot = $this->getBotById($botId);
        if (!$bot) {
            return ['ok' => false, 'error' => 'Bot not found'];
        }

        $configFile = $this->configPath . $botId . '.env';
        if (!file_exists($configFile)) {
            return ['ok' => false, 'error' => 'Configuration file not found'];
        }

        $config = file_get_contents($configFile);

        // Remove sensitive information
        $config = preg_replace('/^(BOT_TOKEN|DB_PASSWORD|MERCHANT_ID)=.*$/m', '$1=***HIDDEN***', $config);

        return [
            'ok' => true,
            'config' => $config,
            'filename' => $botId . '_config.env'
        ];
    }

    /**
     * Import bot configuration
     */
    public function importBotConfig(string $botId, string $configContent): array
    {
        if (!$this->getBotById($botId)) {
            return ['ok' => false, 'error' => 'Bot not found'];
        }

        try {
            // Validate configuration content
            $lines = explode("\n", $configContent);
            $hasRequiredFields = false;

            foreach ($lines as $line) {
                if (strpos($line, 'BOT_TOKEN=') === 0 && strpos($line, '***HIDDEN***') === false) {
                    $hasRequiredFields = true;
                    break;
                }
            }

            if (!$hasRequiredFields) {
                return ['ok' => false, 'error' => 'Configuration must contain valid BOT_TOKEN'];
            }

            $configFile = $this->configPath . $botId . '.env';
            if (file_put_contents($configFile, $configContent) !== false) {
                $this->logAction('Config Imported', "Configuration imported for bot '{$botId}'");
                return ['ok' => true, 'message' => 'Configuration imported successfully'];
            } else {
                return ['ok' => false, 'error' => 'Failed to write configuration file'];
            }
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()];
        }
    }

    /**
     * Create bot database
     */
    public function createBotDatabase(string $botId): bool
    {
        try {
            $dbName = $botId . '_bot_db';

            // Create database
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Connect to the new database
            $dbConfig = AppConfig::getMasterDbConfig();
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbName};charset=utf8mb4";
            $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $botPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create basic bot tables
            $this->createBotTables($botPdo);

            return true;
        } catch (Exception $e) {
            error_log("Error creating bot database for {$botId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create basic tables for bot
     */
    private function createBotTables(PDO $pdo): void
    {
        $tables = [
            // Users table
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNIQUE NOT NULL,
                username VARCHAR(255),
                first_name VARCHAR(255),
                last_name VARCHAR(255),
                phone VARCHAR(20),
                email VARCHAR(255),
                status ENUM('active', 'blocked') DEFAULT 'active',
                is_admin BOOLEAN DEFAULT FALSE,
                registration_step VARCHAR(50) DEFAULT 'completed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Categories table
            "CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                parent_id INT NULL,
                image_path VARCHAR(500),
                is_active BOOLEAN DEFAULT TRUE,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE,
                INDEX idx_parent_id (parent_id),
                INDEX idx_is_active (is_active),
                INDEX idx_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Products table
            "CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(10,2) NOT NULL DEFAULT 0,
                category_id INT,
                image_path VARCHAR(500),
                is_available BOOLEAN DEFAULT TRUE,
                stock_quantity INT DEFAULT 0,
                views_count INT DEFAULT 0,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                INDEX idx_category_id (category_id),
                INDEX idx_is_available (is_available),
                INDEX idx_price (price),
                INDEX idx_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Orders table
            "CREATE TABLE IF NOT EXISTS orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                order_number VARCHAR(50) UNIQUE NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                status ENUM('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
                payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
                payment_method VARCHAR(50),
                delivery_address TEXT,
                delivery_phone VARCHAR(20),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_payment_status (payment_status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Order items table
            "CREATE TABLE IF NOT EXISTS order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                INDEX idx_order_id (order_id),
                INDEX idx_product_id (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // User states table (for bot conversation management)
            "CREATE TABLE IF NOT EXISTS user_states (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                state_name VARCHAR(100) NOT NULL,
                state_data JSON,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_state (user_id, state_name),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Shopping cart table
            "CREATE TABLE IF NOT EXISTS cart_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                UNIQUE KEY unique_user_product (user_id, product_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Settings table for bot configuration
            "CREATE TABLE IF NOT EXISTS bot_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                description VARCHAR(255),
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Messages/Broadcasts table
            "CREATE TABLE IF NOT EXISTS broadcast_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                target_audience ENUM('all', 'active', 'admins') DEFAULT 'all',
                sent_count INT DEFAULT 0,
                failed_count INT DEFAULT 0,
                status ENUM('draft', 'sending', 'completed', 'failed') DEFAULT 'draft',
                scheduled_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL,
                INDEX idx_status (status),
                INDEX idx_scheduled_at (scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // User activity logs
            "CREATE TABLE IF NOT EXISTS user_activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                action VARCHAR(100) NOT NULL,
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Coupons/Discounts table
            "CREATE TABLE IF NOT EXISTS coupons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                type ENUM('percentage', 'fixed') DEFAULT 'percentage',
                value DECIMAL(10,2) NOT NULL,
                min_order_amount DECIMAL(10,2) DEFAULT 0,
                usage_limit INT NULL,
                used_count INT DEFAULT 0,
                is_active BOOLEAN DEFAULT TRUE,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_code (code),
                INDEX idx_is_active (is_active),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }

        // Insert default settings
        $defaultSettings = [
            ['welcome_message', 'به ربات ما خوش آمدید!', 'پیام خوشامدگویی'],
            ['store_status', 'open', 'وضعیت فروشگاه (open/closed)'],
            ['delivery_fee', '0', 'هزینه ارسال'],
            ['min_order_amount', '0', 'حداقل مبلغ سفارش'],
            ['currency', 'تومان', 'واحد پولی'],
            ['contact_info', '', 'اطلاعات تماس'],
            ['working_hours', '9:00-21:00', 'ساعات کاری'],
            ['auto_confirm_orders', '0', 'تایید خودکار سفارشات (0/1)']
        ];

        $insertSetting = $pdo->prepare("INSERT IGNORE INTO bot_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        foreach ($defaultSettings as $setting) {
            $insertSetting->execute($setting);
        }

        // Insert default categories
        $defaultCategories = [
            ['غذاهای اصلی', 'انواع غذاهای اصلی', null],
            ['نوشیدنی', 'انواع نوشیدنی‌ها', null],
            ['پیش‌غذا', 'انواع پیش‌غذا', null],
            ['دسر', 'انواع دسر', null]
        ];

        $insertCategory = $pdo->prepare("INSERT IGNORE INTO categories (name, description, parent_id) VALUES (?, ?, ?)");
        foreach ($defaultCategories as $category) {
            $insertCategory->execute($category);
        }
    }

    /**
     * Get bot performance statistics
     */
    public function getBotPerformanceStats(string $botId): array
    {
        try {
            AppConfig::init($botId);
            $dbConfig = AppConfig::getDbConfig();

            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
            $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

            $stats = [
                'users' => [
                    'total' => 0,
                    'active' => 0,
                    'new_today' => 0,
                    'new_this_week' => 0
                ],
                'orders' => [
                    'total' => 0,
                    'pending' => 0,
                    'completed' => 0,
                    'today' => 0,
                    'this_week' => 0,
                    'total_revenue' => 0
                ],
                'products' => [
                    'total' => 0,
                    'available' => 0,
                    'out_of_stock' => 0
                ],
                'activities' => [
                    'total_interactions' => 0,
                    'today_interactions' => 0
                ]
            ];

            // User statistics
            $stmt = $botPdo->query("SELECT COUNT(*) FROM users");
            $stats['users']['total'] = (int)$stmt->fetchColumn();

            $stmt = $botPdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'");
            $stats['users']['active'] = (int)$stmt->fetchColumn();

            $stmt = $botPdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
            $stats['users']['new_today'] = (int)$stmt->fetchColumn();

            $stmt = $botPdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $stats['users']['new_this_week'] = (int)$stmt->fetchColumn();

            // Order statistics
            try {
                $stmt = $botPdo->query("SELECT COUNT(*) FROM orders");
                $stats['orders']['total'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
                $stats['orders']['pending'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('delivered', 'completed')");
                $stats['orders']['completed'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
                $stats['orders']['today'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $stats['orders']['this_week'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'");
                $stats['orders']['total_revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                // Orders table might not exist
            }

            // Product statistics
            try {
                $stmt = $botPdo->query("SELECT COUNT(*) FROM products");
                $stats['products']['total'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM products WHERE is_available = 1");
                $stats['products']['available'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0");
                $stats['products']['out_of_stock'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                // Products table might not exist
            }

            // Activity statistics
            try {
                $stmt = $botPdo->query("SELECT COUNT(*) FROM user_activities");
                $stats['activities']['total_interactions'] = (int)$stmt->fetchColumn();

                $stmt = $botPdo->query("SELECT COUNT(*) FROM user_activities WHERE DATE(created_at) = CURDATE()");
                $stats['activities']['today_interactions'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                // Activities table might not exist
            }

            return $stats;
        } catch (Exception $e) {
            error_log("Error getting performance stats for bot {$botId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up expired data
     */
    public function cleanupExpiredData(): array
    {
        $results = [
            'logs_cleaned' => 0,
            'states_cleaned' => 0,
            'backups_cleaned' => 0,
            'errors' => []
        ];

        try {
            // Clean old logs
            $days = $this->getSystemSetting('log_retention_days', 30);
            $results['logs_cleaned'] = $this->cleanOldLogs($days);

            // Clean expired user states from all bot databases
            $bots = $this->getAllBots();
            foreach ($bots as $bot) {
                try {
                    AppConfig::init($bot['bot_id']);
                    $dbConfig = AppConfig::getDbConfig();

                    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
                    $botPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);

                    $stmt = $botPdo->prepare("DELETE FROM user_states WHERE expires_at IS NOT NULL AND expires_at < NOW()");
                    $stmt->execute();
                    $results['states_cleaned'] += $stmt->rowCount();
                } catch (Exception $e) {
                    $results['errors'][] = "Error cleaning states for bot {$bot['bot_id']}: " . $e->getMessage();
                }
            }

            // Clean old backup files
            $backupRetentionDays = $this->getSystemSetting('backup_retention_days', 30);
            $backupFiles = glob($this->backupPath . '*.zip');
            $cutoffTime = time() - ($backupRetentionDays * 24 * 60 * 60);

            foreach ($backupFiles as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $results['backups_cleaned']++;
                    }
                }
            }
        } catch (Exception $e) {
            $results['errors'][] = 'Cleanup error: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Get system setting
     */
    private function getSystemSetting(string $key, $default = null)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();

            return $result !== false ? $result : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * Update bot token in config file
     */
    public function updateBotTokenInConfig(string $botId, string $newToken): bool
    {
        $configFile = $this->configPath . $botId . '.env';
        if (!file_exists($configFile)) {
            return false;
        }

        try {
            $content = file_get_contents($configFile);
            $updatedContent = preg_replace(
                '/^BOT_TOKEN=.*$/m',
                'BOT_TOKEN=' . $newToken,
                $content
            );

            if (file_put_contents($configFile, $updatedContent) !== false) {
                // Also update in database
                $stmt = $this->pdo->prepare("UPDATE managed_bots SET bot_token = ?, updated_at = NOW() WHERE bot_id = ?");
                return $stmt->execute([$newToken, $botId]);
            }

            return false;
        } catch (Exception $e) {
            error_log("Error updating bot token for {$botId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk operations for bots
     */
    public function bulkUpdateBots(array $botIds, array $updates): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'total' => count($botIds)
        ];

        foreach ($botIds as $botId) {
            try {
                if ($this->updateBot($botId, $updates)) {
                    $results['success'][] = $botId;
                } else {
                    $results['failed'][] = $botId;
                }
            } catch (Exception $e) {
                $results['failed'][] = $botId;
                error_log("Bulk update failed for bot {$botId}: " . $e->getMessage());
            }
        }

        $this->logAction('Bulk Update', "Updated " . count($results['success']) . " bots, failed: " . count($results['failed']));

        return $results;
    }

    /**
     * Generate system report
     */
    public function generateSystemReport(): array
    {
        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => $this->getDashboardStats(),
            'system_health' => $this->getSystemHealth(),
            'bot_details' => [],
            'recent_activities' => $this->getLogs(['limit' => 50]),
            'performance_metrics' => []
        ];

        // Get detailed bot information
        $bots = $this->getAllBots();
        foreach ($bots as $bot) {
            $report['bot_details'][$bot['bot_id']] = [
                'basic_info' => $bot,
                'performance' => $this->getBotPerformanceStats($bot['bot_id']),
                'config_status' => file_exists($this->configPath . $bot['bot_id'] . '.env')
            ];
        }

        // System performance metrics
        $report['performance_metrics'] = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'database_size' => $this->getDatabaseSize(),
            'config_files_count' => count(glob($this->configPath . '*.env')),
            'backup_files_count' => count(glob($this->backupPath . '*.zip'))
        ];

        return $report;
    }

    /**
     * Get database size
     */
    private function getDatabaseSize(): int
    {
        try {
            $dbConfig = AppConfig::getMasterDbConfig();
            $stmt = $this->pdo->prepare("
                SELECT SUM(data_length + index_length) as size 
                FROM information_schema.tables 
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbConfig['database']]);

            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Destructor - cleanup
     */
    public function __destruct()
    {
        $this->pdo = null;
    }
}
