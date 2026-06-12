<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Bot\TelegramBot;
use App\Config\Config;

$config = new Config(__DIR__ . '/.env');
$bot = new TelegramBot($config);
$bot->handleWebhook();
