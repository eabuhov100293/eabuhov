<?php

/**
 * Регистрирует или удаляет вебхук Telegram-бота.
 * Запуск: php setup_webhook.php [set|delete]
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Config;

$config = new Config(__DIR__ . '/.env');

$action = $argv[1] ?? 'set';
$token  = $config->telegramBotToken;
$base   = "https://api.telegram.org/bot{$token}";

if ($action === 'delete') {
    $url = "{$base}/deleteWebhook";
    $result = file_get_contents($url);
    $data = json_decode($result, true);
    echo $data['ok'] ? "Вебхук удалён.\n" : "Ошибка: " . ($data['description'] ?? '') . "\n";
    exit;
}

if ($action === 'set') {
    if (empty($argv[2])) {
        echo "Использование: php setup_webhook.php set https://your-server.com/bot/index.php\n";
        exit(1);
    }

    $webhookUrl = $argv[2];

    $ch = curl_init("{$base}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'url'             => $webhookUrl,
            'allowed_updates' => ['message', 'edited_message'],
            'max_connections' => 40,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    if ($data['ok'] ?? false) {
        echo "Вебхук установлен: {$webhookUrl}\n";
    } else {
        echo "Ошибка: " . ($data['description'] ?? $result) . "\n";
    }
}
