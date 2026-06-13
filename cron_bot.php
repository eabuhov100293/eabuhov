<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Config;
use App\Bot\TelegramBot;

$config = new Config(__DIR__ . '/.env');
$bot    = new TelegramBot($config);

$offsetFile = dirname($config->logFile) . '/last_update_id.txt';
$offset     = file_exists($offsetFile) ? (int)file_get_contents($offsetFile) + 1 : 0;

$params = ['timeout' => 25, 'limit' => 10];
if ($offset > 0) {
    $params['offset'] = $offset;
}

$tmpFile = sys_get_temp_dir() . '/tg_poll.json';
file_put_contents($tmpFile, json_encode($params));

$url    = "https://api.telegram.org/bot{$config->telegramBotToken}/getUpdates";
$result = shell_exec(
    'curl -6 -s --max-time 30 -H ' . escapeshellarg('Content-Type: application/json')
    . ' -d ' . escapeshellarg('@' . $tmpFile)
    . ' ' . escapeshellarg($url)
);
@unlink($tmpFile);

if (!$result) {
    exit(0);
}

$data = json_decode($result, true);
if (!($data['ok'] ?? false) || empty($data['result'])) {
    exit(0);
}

foreach ($data['result'] as $update) {
    $updateId = (int)$update['update_id'];
    file_put_contents($offsetFile, (string)$updateId);
    $bot->processUpdate($update);
}
