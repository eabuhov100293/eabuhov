<?php

declare(strict_types=1);

namespace App\Bot;

use App\Services\TelegramService;
use App\Services\ReportService;
use Monolog\Logger;

class ReportBot
{
    private const MENU = <<<TEXT
        📊 *Бот отчётов Bitrix24*

        Доступные команды:

        /overdue — ⚠️ Просроченные задачи всех сотрудников
        /deals\_stages — 📌 Сделки по стадиям воронки
        /deals\_employees — 👥 Сделки по сотрудникам
        /help — показать это меню
        TEXT;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly ReportService   $reports,
        private readonly array           $allowedUserIds,
        private readonly Logger          $log
    ) {}

    public function handleWebhook(string $body): void
    {
        $update = json_decode($body, true);
        if (!$update) {
            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$message) {
            return;
        }

        $chatId = (int)$message['chat']['id'];
        $userId = (int)($message['from']['id'] ?? 0);
        $text   = trim($message['text'] ?? '');

        if (!$this->isAllowed($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ У вас нет доступа к этому боту.');
            return;
        }

        $this->log->info('ReportBot message', ['user' => $userId, 'text' => $text]);

        $command = strtolower(explode(' ', explode('@', $text)[0])[0]);

        try {
            match ($command) {
                '/start', '/help' => $this->telegram->sendMessage($chatId, self::MENU, 'Markdown'),
                '/overdue'            => $this->sendReport($chatId, fn() => $this->reports->getOverdueTasks()),
                '/deals_stages'       => $this->sendReport($chatId, fn() => $this->reports->getDealsByStage()),
                '/deals_employees'    => $this->sendReport($chatId, fn() => $this->reports->getDealsByEmployee()),
                default               => $this->telegram->sendMessage($chatId, '❓ Неизвестная команда. /help — список команд.'),
            };
        } catch (\Throwable $e) {
            $this->log->error('ReportBot error', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, '❌ Произошла ошибка при получении отчёта. Попробуйте позже.');
        }
    }

    private function sendReport(int $chatId, callable $generator): void
    {
        $this->telegram->sendMessage($chatId, '⏳ Загружаю данные из Bitrix24...');

        $text = $generator();

        // Telegram ограничивает сообщение 4096 символами — разбиваем если нужно
        foreach ($this->splitMessage($text) as $chunk) {
            $this->telegram->sendMessage($chatId, $chunk, 'Markdown');
        }
    }

    /** Разбивает длинный текст на части по 4000 символов по границам строк */
    private function splitMessage(string $text, int $limit = 4000): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $lines  = explode("\n", $text);
        $chunk  = '';

        foreach ($lines as $line) {
            if (mb_strlen($chunk) + mb_strlen($line) + 1 > $limit) {
                if ($chunk !== '') {
                    $chunks[] = $chunk;
                }
                $chunk = $line;
            } else {
                $chunk .= ($chunk === '' ? '' : "\n") . $line;
            }
        }

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    private function isAllowed(int $userId): bool
    {
        if (empty($this->allowedUserIds)) {
            return true;
        }
        return in_array($userId, $this->allowedUserIds, true);
    }
}
