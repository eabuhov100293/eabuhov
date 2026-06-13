<?php

declare(strict_types=1);

/**
 * Регистрирует или удаляет вебхук для Report Bot.
 *
 * Использование:
 *   php setup_report_webhook.php set   https://your-domain.com/report_bot.php
 *   php setup_report_webhook.php delete
 *   php setup_report_webhook.php info
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$token  = $_ENV['REPORT_BOT_TOKEN'] ?? '';
$secret = $_ENV['REPORT_WEBHOOK_SECRET'] ?? '';

if ($token === '') {
    echo "ERROR: REPORT_BOT_TOKEN is not set in .env\n";
    exit(1);
}

$action = $argv[1] ?? 'info';
$url    = $argv[2] ?? '';

$apiBase = "https://api.telegram.org/bot{$token}";

switch ($action) {
    case 'set':
        if ($url === '') {
            echo "Usage: php setup_report_webhook.php set https://your-domain.com/report_bot.php\n";
            exit(1);
        }
        $params = ['url' => $url];
        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }
        $result = apiCall($apiBase, 'setWebhook', $params);
        echo "setWebhook: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        break;

    case 'delete':
        $result = apiCall($apiBase, 'deleteWebhook', []);
        echo "deleteWebhook: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        break;

    case 'info':
    default:
        $result = apiCall($apiBase, 'getWebhookInfo', []);
        echo "getWebhookInfo: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        break;
}

function apiCall(string $base, string $method, array $params): array
{
    $ch = curl_init("{$base}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true) ?? [];
}
