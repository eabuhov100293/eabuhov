<?php

declare(strict_types=1);

namespace App\Session;

/**
 * Хранит состояние диалога пользователя в JSON-файлах.
 */
class SessionManager
{
    private string $dir;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/sessions';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    public function get(int $userId): ?array
    {
        $file = $this->filePath($userId);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return null;
        }

        // Сессия живёт 30 минут
        if (time() - ($data['updated_at'] ?? 0) > 1800) {
            $this->clear($userId);
            return null;
        }

        return $data;
    }

    public function set(int $userId, array $data): void
    {
        $data['updated_at'] = time();
        file_put_contents($this->filePath($userId), json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function clear(int $userId): void
    {
        $file = $this->filePath($userId);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function filePath(int $userId): string
    {
        return "{$this->dir}/{$userId}.json";
    }
}
