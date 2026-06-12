<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

class Config
{
    public readonly string $telegramBotToken;
    public readonly string $yandexApiKey;
    public readonly string $yandexFolderId;
    public readonly string $bitrix24WebhookUrl;
    public readonly array  $allowedUserIds;
    public readonly string $logLevel;
    public readonly string $logFile;

    public function __construct(string $envPath)
    {
        $dotenv = Dotenv::createImmutable(dirname($envPath), basename($envPath));
        $dotenv->safeLoad();

        $this->telegramBotToken  = $this->require('TELEGRAM_BOT_TOKEN');
        $this->yandexApiKey      = $this->require('YANDEX_API_KEY');
        $this->yandexFolderId    = $this->require('YANDEX_FOLDER_ID');
        $this->bitrix24WebhookUrl = rtrim($this->require('BITRIX24_WEBHOOK_URL'), '/') . '/';

        $ids = $_ENV['ALLOWED_USER_IDS'] ?? '';
        $this->allowedUserIds = $ids !== ''
            ? array_map('intval', explode(',', $ids))
            : [];

        $this->logLevel = $_ENV['LOG_LEVEL'] ?? 'info';
        $this->logFile  = $_ENV['LOG_FILE'] ?? 'logs/bot.log';
    }

    private function require(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if ($value === '') {
            throw new \RuntimeException("Required env variable \"{$key}\" is not set.");
        }
        return $value;
    }
}
