<?php

declare(strict_types=1);

namespace App\Bot;

use App\Config\Config;
use App\Services\SpeechKitService;
use App\Services\YandexGptService;
use App\Services\Bitrix24Service;
use App\Services\TelegramService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TelegramBot
{
    private readonly Logger           $log;
    private readonly TelegramService  $telegram;
    private readonly SpeechKitService $speechKit;
    private readonly YandexGptService $gpt;
    private readonly Bitrix24Service  $bitrix;

    public function __construct(private readonly Config $config)
    {
        $this->log = new Logger('bot');
        $logDir = dirname($config->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->log->pushHandler(new StreamHandler($config->logFile, $config->logLevel));

        $this->telegram  = new TelegramService($config->telegramBotToken, $this->log);
        $this->speechKit = new SpeechKitService($config->yandexApiKey, $config->yandexFolderId, $this->log);
        $this->gpt       = new YandexGptService($config->yandexApiKey, $config->yandexFolderId, $this->log);
        $this->bitrix    = new Bitrix24Service($config->bitrix24WebhookUrl, $this->log);
    }

    public function handleWebhook(): void
    {
        $body = file_get_contents('php://input');
        if (!$body) {
            http_response_code(200);
            return;
        }

        // Проверка секретного токена Telegram (X-Telegram-Bot-Api-Secret-Token)
        if ($this->config->webhookSecret !== '') {
            $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
            if (!hash_equals($this->config->webhookSecret, $header)) {
                http_response_code(403);
                return;
            }
        }

        $update = json_decode($body, true);
        if (!$update) {
            http_response_code(200);
            return;
        }

        $this->log->debug('Incoming update', ['update_id' => $update['update_id'] ?? null]);

        http_response_code(200);
        echo 'OK';

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$message) {
            return;
        }

        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'] ?? 0;

        if (!$this->isAllowed($userId)) {
            $this->telegram->sendMessage($chatId, 'У вас нет доступа к этому боту.');
            return;
        }

        try {
            $this->dispatch($message, $chatId);
        } catch (\Throwable $e) {
            $this->log->error('Unhandled error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->telegram->sendMessage($chatId, '❌ Произошла ошибка. Попробуйте ещё раз.');
        }
    }

    private function dispatch(array $message, int $chatId): void
    {
        if (isset($message['voice'])) {
            $this->handleVoice($message, $chatId);
            return;
        }

        if (isset($message['text'])) {
            $text = trim($message['text']);
            if (str_starts_with($text, '/')) {
                $this->handleCommand($text, $chatId);
            } else {
                $this->handleText($text, $chatId);
            }
            return;
        }

        $this->telegram->sendMessage($chatId, 'Отправьте голосовое сообщение или текстовую команду.');
    }

    private function handleVoice(array $message, int $chatId): void
    {
        $fileId = $message['voice']['file_id'];
        $this->telegram->sendMessage($chatId, '🎙 Распознаю речь...');

        $audioData = $this->telegram->downloadFile($fileId);
        $text = $this->speechKit->recognize($audioData);

        if (!$text) {
            $this->telegram->sendMessage($chatId, '❌ Не удалось распознать речь. Попробуйте ещё раз.');
            return;
        }

        $this->telegram->sendMessage($chatId, "📝 Распознано: *{$text}*", 'Markdown');
        $this->processCommand($text, $chatId, voiceMode: true);
    }

    private function handleText(string $text, int $chatId): void
    {
        $this->processCommand($text, $chatId, voiceMode: false);
    }

    private function handleCommand(string $text, int $chatId): void
    {
        $cmd = strtok($text, ' ');
        switch ($cmd) {
            case '/start':
                $this->telegram->sendMessage(
                    $chatId,
                    "👋 Привет! Я голосовой помощник для Bitrix24.\n\n"
                    . "Я умею:\n"
                    . "• Создавать и просматривать задачи\n"
                    . "• Менять сроки задач\n"
                    . "• Создавать лиды и сделки в CRM\n"
                    . "• Искать лиды и сделки\n\n"
                    . "Отправьте голосовое сообщение или напишите текстом.\n"
                    . "Введите /help для примеров команд."
                );
                break;

            case '/help':
                $this->telegram->sendMessage(
                    $chatId,
                    "📖 *Примеры команд:*\n\n"
                    . "*Задачи:*\n"
                    . "• «Создай задачу позвонить клиенту до пятницы»\n"
                    . "• «Создай задачу подготовить отчёт ответственный Иван срок 20 июня»\n"
                    . "• «Измени срок задачи 123 на 25 июня»\n"
                    . "• «Покажи задачу 123»\n"
                    . "• «Покажи мои задачи»\n\n"
                    . "*CRM:*\n"
                    . "• «Создай лид Иван Иванов телефон 79001234567»\n"
                    . "• «Создай сделку покупка оборудования сумма 50000»\n"
                    . "• «Найди лид Иванов»\n"
                    . "• «Найди сделку оборудование»\n\n"
                    . "Можно говорить голосом или писать текстом.",
                    'Markdown'
                );
                break;

            default:
                $this->telegram->sendMessage($chatId, 'Неизвестная команда. Введите /help для справки.');
        }
    }

    private function processCommand(string $text, int $chatId, bool $voiceMode = false): void
    {
        $this->telegram->sendMessage($chatId, '⚙️ Обрабатываю команду...');

        $intent = $this->gpt->parseIntent($text);
        $this->log->info('Parsed intent', ['action' => $intent['action'] ?? 'null']);

        if (!$intent || ($intent['action'] ?? '') === 'unknown') {
            $reply = "Не удалось определить действие.\n\nОтправьте /help для просмотра примеров.";
            $this->telegram->sendMessage($chatId, $reply);
            return;
        }

        $result = $this->executeIntent($intent);

        // Для чтения — Markdown, для создания — тоже
        $this->telegram->sendMessage($chatId, $result, 'Markdown');

        // Голосовой ответ — только если пришло голосовое сообщение и TTS включён
        if ($voiceMode && $this->config->ttsEnabled) {
            $this->sendVoiceReply($chatId, $result);
        }
    }

    private function executeIntent(array $intent): string
    {
        return match ($intent['action']) {
            'create_task'  => $this->bitrix->createTask($intent),
            'update_task'  => $this->bitrix->updateTaskDeadline($intent),
            'get_task'     => $this->bitrix->getTask($intent),
            'list_tasks'   => $this->bitrix->listMyTasks($intent),
            'create_lead'  => $this->bitrix->createLead($intent),
            'create_deal'  => $this->bitrix->createDeal($intent),
            'search_leads' => $this->bitrix->searchLeads($intent),
            'search_deals' => $this->bitrix->searchDeals($intent),
            default        => 'Действие не поддерживается.',
        };
    }

    private function sendVoiceReply(int $chatId, string $text): void
    {
        // Убираем Markdown-разметку для TTS
        $clean = preg_replace('/[*_`\[\]()~>#+\-=|{}.!]/', '', $text);
        $clean = preg_replace('/\n+/', '. ', $clean);

        $audio = $this->speechKit->synthesize($clean);
        if ($audio) {
            $this->telegram->sendVoice($chatId, $audio);
        } else {
            $this->log->warning('TTS synthesis failed, skipping voice reply');
        }
    }

    private function isAllowed(int $userId): bool
    {
        if (empty($this->config->allowedUserIds)) {
            return true;
        }
        return in_array($userId, $this->config->allowedUserIds, true);
    }
}
