
<?php
// ===================================================================
// Configuration validator - public/superadmin/validate_config.php
?>

<?php
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';

use Bot\SuperAdminManager;
use Config\AppConfig;

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$botId = $_GET['bot_id'] ?? '';
if (empty($botId)) {
    echo json_encode(['ok' => false, 'error' => 'Bot ID is required']);
    exit;
}

try {
    $manager = new SuperAdminManager();
    $bot = $manager->getBotById($botId);

    if (!$bot) {
        echo json_encode(['ok' => false, 'error' => 'Bot not found']);
        exit;
    }

    $validation = [
        'bot_exists' => true,
        'config_file_exists' => false,
        'config_readable' => false,
        'token_valid' => false,
        'database_accessible' => false,
        'webhook_info' => null,
        'issues' => [],
        'warnings' => []
    ];

    // Check config file
    $configPath = __DIR__ . '/../../config/' . $botId . '.env';
    if (file_exists($configPath)) {
        $validation['config_file_exists'] = true;

        if (is_readable($configPath)) {
            $validation['config_readable'] = true;

            // Initialize bot config
            try {
                AppConfig::init($botId);

                // Check database connection
                try {
                    $dbConfig = AppConfig::getDbConfig();
                    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
                    $testPdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
                    $validation['database_accessible'] = true;
                } catch (Exception $e) {
                    $validation['issues'][] = 'Database connection failed: ' . $e->getMessage();
                }
            } catch (Exception $e) {
                $validation['issues'][] = 'Config initialization failed: ' . $e->getMessage();
            }
        } else {
            $validation['issues'][] = 'Configuration file is not readable';
        }
    } else {
        $validation['issues'][] = 'Configuration file does not exist';
    }

    // Validate bot token
    if ($bot['bot_token']) {
        $url = "https://api.telegram.org/bot{$bot['bot_token']}/getMe";
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['ok']) && $data['ok'] === true) {
                $validation['token_valid'] = true;
                $validation['bot_info'] = $data['result'];
            } else {
                $validation['issues'][] = 'Bot token validation failed: ' . ($data['description'] ?? 'Unknown error');
            }
        } else {
            $validation['issues'][] = 'Could not connect to Telegram API to validate token';
        }

        // Get webhook info
        $webhookUrl = "https://api.telegram.org/bot{$bot['bot_token']}/getWebhookInfo";
        $webhookResponse = @file_get_contents($webhookUrl, false, $context);
        if ($webhookResponse) {
            $webhookData = json_decode($webhookResponse, true);
            if (isset($webhookData['ok']) && $webhookData['ok'] === true) {
                $validation['webhook_info'] = $webhookData['result'];

                if (empty($webhookData['result']['url'])) {
                    $validation['warnings'][] = 'No webhook URL is set for this bot';
                }
            }
        }
    } else {
        $validation['issues'][] = 'Bot token is empty';
    }

    // Check subscription status
    if ($bot['subscription_expires_at']) {
        $expiryTime = strtotime($bot['subscription_expires_at']);
        $now = time();
        $daysUntilExpiry = ($expiryTime - $now) / (24 * 60 * 60);

        if ($daysUntilExpiry < 0) {
            $validation['issues'][] = 'Subscription has expired';
        } elseif ($daysUntilExpiry < 7) {
            $validation['warnings'][] = 'Subscription expires in less than 7 days';
        }
    }

    // Overall status
    $validation['status'] = empty($validation['issues']) ? 'healthy' : 'unhealthy';
    if (!empty($validation['warnings']) && $validation['status'] === 'healthy') {
        $validation['status'] = 'warning';
    }

    echo json_encode(['ok' => true, 'validation' => $validation]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Validation failed: ' . $e->getMessage()]);
}
