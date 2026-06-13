<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Bot\ReportBot;
use App\Services\TelegramService;
use App\Services\ReportService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Dotenv\Dotenv;

// ─── Конфигурация ─────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$reportBotToken    = $_ENV['REPORT_BOT_TOKEN']    ?? '';
$bitrix24Webhook   = rtrim($_ENV['BITRIX24_WEBHOOK_URL'] ?? '', '/') . '/';
$webhookSecret     = $_ENV['REPORT_WEBHOOK_SECRET'] ?? '';
$logFile           = $_ENV['REPORT_LOG_FILE']       ?? 'logs/report_bot.log';
$logLevel          = $_ENV['LOG_LEVEL']              ?? 'info';
$allowedIds        = $_ENV['REPORT_ALLOWED_USER_IDS'] ?? '';

$allowedUserIds = $allowedIds !== ''
    ? array_map('intval', explode(',', $allowedIds))
    : [];

// ─── Логгер ───────────────────────────────────────────────────────────────────

$level  = match (strtolower($logLevel)) {
    'debug'   => Level::Debug,
    'warning' => Level::Warning,
    'error'   => Level::Error,
    default   => Level::Info,
};

$log = new Logger('report_bot');
$log->pushHandler(new StreamHandler(__DIR__ . '/' . $logFile, $level));

// ─── Валидация токена и секрета ───────────────────────────────────────────────

if ($reportBotToken === '') {
    http_response_code(500);
    $log->error('REPORT_BOT_TOKEN not set');
    exit('Configuration error');
}

if ($webhookSecret !== '') {
    $incoming = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($webhookSecret, $incoming)) {
        http_response_code(403);
        $log->warning('Invalid webhook secret');
        exit('Forbidden');
    }
}

// ─── Обработка запроса ────────────────────────────────────────────────────────

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(200);
    exit;
}

try {
    $telegram = new TelegramService($reportBotToken, $log);
    $reports  = new ReportService($bitrix24Webhook, $log);
    $bot      = new ReportBot($telegram, $reports, $allowedUserIds, $log);
    $bot->handleWebhook($body);
} catch (\Throwable $e) {
    $log->error('Unhandled exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

http_response_code(200);
