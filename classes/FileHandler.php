<?php

namespace Bot;

use Config\AppConfig;
use Exception;

class FileHandler
{
    private string $stateFilePath;

    public function __construct()
    {
        $botId = AppConfig::getCurrentBotId();
        if (!$botId) {
            throw new Exception("Bot ID not initialized for FileHandler.");
        }

        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $this->stateFilePath = "{$dataDir}/{$botId}_state.json";
    }

    private function getAllData(): array
    {
        if (!file_exists($this->stateFilePath)) {
            return [];
        }

        $content = file_get_contents($this->stateFilePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in {$this->stateFilePath}: " . json_last_error_msg());
            return [];
        }

        return $data ?? [];
    }

    private function saveAllData(array $data): void
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $fp = fopen($this->stateFilePath, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $jsonData);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // متد اصلی افزودن/بروزرسانی داده
    public function addData(int|string $chatId, array $values): void
    {
        $data = $this->getAllData();
        if (!isset($data[$chatId])) $data[$chatId] = [];

        foreach ($values as $key => $value) {
            if ($key === 'edit_cart_state') {
                $data[$chatId][$key] = $value;
                continue;
            }

            if ($value === null) {
                unset($data[$chatId][$key]);
                continue;
            }

            if (isset($data[$chatId][$key]) && is_array($data[$chatId][$key]) && is_array($value)) {
                $data[$chatId][$key] = array_replace($data[$chatId][$key], $value);
            } elseif (isset($data[$chatId][$key]) && is_array($data[$chatId][$key])) {
                $data[$chatId][$key][] = $value;
            } else {
                $data[$chatId][$key] = $value;
            }
        }

        $this->saveAllData($data);
    }

    public function getData(int|string $chatId, string $key): mixed
    {
        $data = $this->getAllData();
        return $data[$chatId][$key] ?? null;
    }

    public function saveState(int|string $chatId, ?string $state): void
    {
        $this->addData($chatId, ['state' => $state]);
    }

    public function getState(int|string $chatId): ?string
    {
        return $this->getData($chatId, 'state');
    }

    public function saveStateData(int|string $chatId, ?string $stateData): void
    {
        $this->addData($chatId, ['state_data' => $stateData]);
    }

    public function getStateData(int|string $chatId): ?string
    {
        return $this->getData($chatId, 'state_data');
    }

    // پیام‌ها
    public function addMessageId(int|string $chatId, int|string|array $messageId): void
    {
        $data = $this->getAllData();
        if (!isset($data[$chatId]['message_ids'])) $data[$chatId]['message_ids'] = [];

        if (is_array($messageId)) {
            $data[$chatId]['message_ids'] = array_merge($data[$chatId]['message_ids'], $messageId);
        } else {
            $data[$chatId]['message_ids'][] = $messageId;
        }

        $this->saveAllData($data);
    }

    public function getMessageIds(int|string $chatId): array
    {
        return $this->getData($chatId, 'message_ids') ?? [];
    }

    public function clearMessageIds(int|string $chatId): void
    {
        $this->addData($chatId, ['message_ids' => null]);
    }

    public function saveMessageId(int|string $chatId, int|string $messageId): void
    {
        $this->addData($chatId, ['message_id' => $messageId]);
    }

    public function getMessageId(int|string $chatId): int|string|null
    {
        return $this->getData($chatId, 'message_id');
    }
}
