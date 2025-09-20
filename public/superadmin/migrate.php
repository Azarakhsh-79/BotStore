<?php
// ===================================================================
// Database migration script - public/superadmin/migrate.php
// Optimized for both Web and CLI execution.
// ===================================================================

// --- Determine execution environment ---
$is_cli = (php_sapi_name() === 'cli');

// --- Web-only initializations ---
if (!$is_cli) {
    session_start();
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../../vendor/autoload.php';

use Bot\SuperAdminManager;
use Config\AppConfig;

// --- Authentication and Security Checks (for Web requests only) ---
if (!$is_cli) {
    if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Only POST requests allowed']);
        exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// --- Main Migration Logic ---
try {
    if ($is_cli) echo "🚀 Starting database migration...\n";

    $manager = new SuperAdminManager();

    // Database migrations array
    $migrations = [
        'add_bot_description' => "ALTER TABLE managed_bots ADD COLUMN description TEXT AFTER bot_name",
        'add_bot_category' => "ALTER TABLE managed_bots ADD COLUMN category VARCHAR(100) DEFAULT 'general' AFTER description",
        'add_bot_last_activity' => "ALTER TABLE managed_bots ADD COLUMN last_activity_at TIMESTAMP NULL AFTER updated_at",
        'add_system_notifications' => "CREATE TABLE IF NOT EXISTS system_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_is_read (is_read),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'add_bot_stats_cache_columns' => "
            ALTER TABLE managed_bots
            ADD COLUMN user_count INT DEFAULT 0 COMMENT 'Cached user count' AFTER status,
            ADD COLUMN total_revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Cached total revenue' AFTER user_count,
            ADD COLUMN pending_invoices INT DEFAULT 0 COMMENT 'Cached pending invoices count' AFTER total_revenue,
            ADD COLUMN stats_last_updated_at TIMESTAMP NULL COMMENT 'Timestamp of the last cache update' AFTER subscription_expires_at
        ",
    ];

    $results = [];
    $pdo = new PDO(
        "mysql:host=" . AppConfig::getMasterDbConfig()['host'] . ";dbname=" . AppConfig::getMasterDbConfig()['database'],
        AppConfig::getMasterDbConfig()['username'],
        AppConfig::getMasterDbConfig()['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($migrations as $name => $sql) {
        if ($is_cli) echo "  - Running migration '{$name}'... ";
        try {
            $pdo->exec($sql);
            $results[$name] = ['status' => 'success', 'message' => 'Migration applied successfully'];
            if ($is_cli) echo "✅ Success\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                $results[$name] = ['status' => 'skipped', 'message' => 'Already applied'];
                if ($is_cli) echo "⏭️ Skipped (already applied)\n";
            } else {
                $results[$name] = ['status' => 'error', 'message' => $e->getMessage()];
                if ($is_cli) echo "❌ Error: " . $e->getMessage() . "\n";
            }
        }
    }

    // Log the action (this works in both CLI and Web)
    $manager->logAction('Database Migration', 'Database migrations executed via ' . ($is_cli ? 'CLI' : 'Web'));

    // Output results
    if ($is_cli) {
        echo "🎉 Migration process completed.\n";
    } else {
        echo json_encode(['ok' => true, 'results' => $results]);
    }
} catch (Exception $e) {
    if ($is_cli) {
        echo "\n❌ A critical error occurred: " . $e->getMessage() . "\n";
    } else {
        echo json_encode(['ok' => false, 'error' => 'Migration failed: ' . $e->getMessage()]);
    }
    exit(1); // Exit with a non-zero status code on error
}
