
<?php
// ===================================================================
// System monitoring endpoint - public/superadmin/monitor.php
?>

<?php
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';

use Bot\SuperAdminManager;

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $manager = new SuperAdminManager();

    $monitoring = [
        'timestamp' => time(),
        'system' => [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'uptime' => $_SERVER['REQUEST_TIME'] - $_SERVER['REQUEST_TIME_FLOAT'] ?? 0,
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'disk_free' => disk_free_space(__DIR__),
            'disk_total' => disk_total_space(__DIR__)
        ],
        'database' => [
            'status' => 'unknown',
            'connections' => 0,
            'queries' => 0
        ],
        'bots' => [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'expired' => 0
        ],
        'health_score' => 100,
        'alerts' => []
    ];

    // Database monitoring
    try {
        $dbConfig = \Config\AppConfig::getMasterDbConfig();
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']}",
            $dbConfig['username'],
            $dbConfig['password']
        );

        // Database status
        $monitoring['database']['status'] = 'connected';

        // Get process list (connections)
        try {
            $stmt = $pdo->query("SHOW PROCESSLIST");
            $monitoring['database']['connections'] = $stmt->rowCount();
        } catch (Exception $e) {
            $monitoring['alerts'][] = 'Could not get database connection count';
        }

        // Get query statistics
        try {
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'");
            $result = $stmt->fetch();
            $monitoring['database']['queries'] = (int)$result['Value'];
        } catch (Exception $e) {
            $monitoring['alerts'][] = 'Could not get database query statistics';
        }
    } catch (Exception $e) {
        $monitoring['database']['status'] = 'error';
        $monitoring['alerts'][] = 'Database connection failed: ' . $e->getMessage();
        $monitoring['health_score'] -= 30;
    }

    // Bot statistics
    try {
        $bots = $manager->getAllBots();
        $monitoring['bots']['total'] = count($bots);

        foreach ($bots as $bot) {
            if ($bot['status'] === 'active') {
                $monitoring['bots']['active']++;
            } else {
                $monitoring['bots']['inactive']++;
            }

            if ($bot['subscription_expires_at'] && strtotime($bot['subscription_expires_at']) < time()) {
                $monitoring['bots']['expired']++;
            }
        }
    } catch (Exception $e) {
        $monitoring['alerts'][] = 'Could not get bot statistics: ' . $e->getMessage();
        $monitoring['health_score'] -= 10;
    }

    // System health checks
    if ($monitoring['system']['memory_usage'] / (1024 * 1024) > 256) { // > 256MB
        $monitoring['alerts'][] = 'High memory usage detected';
        $monitoring['health_score'] -= 10;
    }

    if ($monitoring['system']['disk_free'] < (1024 * 1024 * 1024)) { // < 1GB
        $monitoring['alerts'][] = 'Low disk space warning';
        $monitoring['health_score'] -= 15;
    }

    if (!empty($monitoring['alerts'])) {
        $monitoring['health_score'] = max(0, $monitoring['health_score']);
    }

    $monitoring['health_status'] = $monitoring['health_score'] >= 80 ? 'healthy' : ($monitoring['health_score'] >= 60 ? 'warning' : 'critical');

    echo json_encode(['ok' => true, 'monitoring' => $monitoring]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Monitoring failed: ' . $e->getMessage()]);
}
?>