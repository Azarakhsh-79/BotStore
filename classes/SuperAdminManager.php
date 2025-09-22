<?php

namespace Bot;

use Config\AppConfig;
use PDO;
use Bot\jdf;
use Exception;

class SuperAdminManager
{
    private PDO $pdo;
    private array $masterConfig;

    public function __construct()
    {
        $this->masterConfig = AppConfig::getMasterDbConfig();
        $dsn = "mysql:host={$this->masterConfig['host']};dbname={$this->masterConfig['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $this->masterConfig['username'], $this->masterConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function login(string $username, string $password): bool
    {
        if ($username === ($this->masterConfig['admin_user'] ?? '') && password_verify($password, $this->masterConfig['admin_pass'] ?? '')) {
            $_SESSION['super_admin_logged_in'] = true;
            return true;
        }
        return false;
    }

    public function checkAuth(): bool
    {
        return isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function getBots(): array
    {
        return $this->pdo->query("SELECT * FROM managed_bots ORDER BY id DESC")->fetchAll();
    }

    public function getBot(int $id): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM managed_bots WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function saveBot(array $data): bool
    {
        $id = empty($data['id']) ? null : (int)$data['id'];
        $jalaliDateStr = $data['subscription_expires_at'] ?? '';
        $gregorianDate = null;

        if (!empty($jalaliDateStr)) {
            if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})\s*(\d{1,2})?:?(\d{1,2})?/', $jalaliDateStr, $matches)) {
                $jy = (int)$matches[1];
                $jm = (int)$matches[2];
                $jd = (int)$matches[3];
                $h = $matches[4] ?? '00';
                $i = $matches[5] ?? '00';

                list($gy, $gm, $gd) = jdf::jalali_to_gregorian($jy, $jm, $jd);
                $gregorianDate = sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $h, $i);
            }
        }

        if ($id) {
            $sql = "UPDATE managed_bots SET bot_id_string=?, bot_name=?, bot_token=?, status=?, subscription_expires_at=? WHERE id=?";
            $params = [$data['bot_id_string'], $data['bot_name'], $data['bot_token'], $data['status'], $gregorianDate, $id];
        } else {
            $sql = "INSERT INTO managed_bots (bot_id_string, bot_name, bot_token, status, subscription_expires_at) VALUES (?, ?, ?, ?, ?)";
            $params = [$data['bot_id_string'], $data['bot_name'], $data['bot_token'], $data['status'], $gregorianDate];
        }
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }


    public function deleteBot(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM managed_bots WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function isBotAllowedToRun(string $botIdString): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, subscription_expires_at FROM managed_bots WHERE bot_id_string = ? LIMIT 1"
        );
        $stmt->execute([$botIdString]);
        $bot = $stmt->fetch();

        if (!$bot) {
            return [
                'allowed' => false,
                'reason'  => "Bot '{$botIdString}' not found in 'managed_bots' table."
            ];
        }

        if ($bot['status'] !== 'active') {
            return [
                'allowed' => false,
                'reason'  => "Bot '{$botIdString}' status is '{$bot['status']}' (expected 'active')."
            ];
        }

        if ($bot['subscription_expires_at'] !== null && strtotime($bot['subscription_expires_at']) < time()) {
            return [
                'allowed' => false,
                'reason'  => "Bot '{$botIdString}' subscription expired on '{$bot['subscription_expires_at']}'."
            ];
        }

        return [
            'allowed' => true,
            'reason'  => null
        ];
    }


    private function sendTelegramRequest(string $token, string $method, array $params = []): array
    {
        $queryString = http_build_query($params);
        $apiUrl = "https://api.telegram.org/bot{$token}/{$method}?{$queryString}";
        $response = @file_get_contents($apiUrl);
        if ($response === false) {
            return ['ok' => false, 'description' => 'Failed to connect to Telegram API.'];
        }
        return json_decode($response, true);
    }

    public function setWebhook(int $botId): array
    {
        $bot = $this->getBot($botId);
        if (!$bot) return ['ok' => false, 'description' => 'Bot not found.'];
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $webhookUrl = "{$appUrl}/public/bot.php?bot_id={$bot['bot_id_string']}";
        return $this->sendTelegramRequest($bot['bot_token'], 'setWebhook', ['url' => $webhookUrl]);
    }

    public function deleteWebhook(int $botId): array
    {
        $bot = $this->getBot($botId);
        if (!$bot) return ['ok' => false, 'description' => 'Bot not found.'];
        return $this->sendTelegramRequest($bot['bot_token'], 'deleteWebhook');
    }

    public function getWebhookInfo(int $botId): array
    {
        $bot = $this->getBot($botId);
        if (!$bot) return ['ok' => false, 'description' => 'Bot not found.'];
        return $this->sendTelegramRequest($bot['bot_token'], 'getWebhookInfo');
    }
}
