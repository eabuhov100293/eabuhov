<?php

declare(strict_types=1);

namespace App\Dialog;

use App\Session\SessionManager;
use App\Services\Bitrix24Service;
use App\Services\TelegramService;

/**
 * Пошаговый диалог создания лида.
 *
 * Шаги:
 *   1. name      — Имя контакта
 *   2. phone     — Телефон
 *   3. company   — Компания (поиск или создание)
 *   4. comment   — Комментарий
 *   5. confirm   — Подтверждение и создание
 */
class LeadDialog
{
    public function __construct(
        private readonly SessionManager  $session,
        private readonly Bitrix24Service $bitrix,
        private readonly TelegramService $telegram,
    ) {}

    public function start(int $userId, int $chatId): void
    {
        $this->session->set($userId, [
            'dialog' => 'lead',
            'step'   => 'name',
            'data'   => [],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📝 *Создание лида*\n\nОтвечайте на вопросы по очереди. В любой момент напишите /отмена чтобы прервать.\n\n1️⃣ *Имя контакта?*",
            'Markdown'
        );
    }

    public function isActive(int $userId): bool
    {
        $s = $this->session->get($userId);
        return ($s['dialog'] ?? '') === 'lead';
    }

    public function handle(int $userId, int $chatId, string $text): void
    {
        if (in_array(mb_strtolower(trim($text)), ['/отмена', 'отмена', 'cancel', '/cancel'], true)) {
            $this->session->clear($userId);
            $this->telegram->sendMessage($chatId, '❌ Создание лида отменено.');
            return;
        }

        $s    = $this->session->get($userId);
        $step = $s['step'] ?? 'name';
        $data = $s['data'] ?? [];

        switch ($step) {
            case 'name':
                $data['name'] = $text;
                $this->session->set($userId, ['dialog' => 'lead', 'step' => 'phone', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "2️⃣ *Телефон?*\n\nНапишите номер или «нет».",
                    'Markdown'
                );
                break;

            case 'phone':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['phone'] = $skip ? '' : $this->normalizePhone($text);
                $this->session->set($userId, ['dialog' => 'lead', 'step' => 'company', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "3️⃣ *Компания?*\n\nНапишите название компании или «нет».",
                    'Markdown'
                );
                break;

            case 'company':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['company'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'lead', 'step' => 'comment', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "4️⃣ *Комментарий?*\n\nНапишите примечание или «нет».",
                    'Markdown'
                );
                break;

            case 'comment':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['comment'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'lead', 'step' => 'confirm', 'data' => $data]);
                $this->telegram->sendMessage($chatId, $this->buildSummary($data), 'Markdown');
                break;

            case 'confirm':
                $answer = mb_strtolower(trim($text));
                if (in_array($answer, ['да', 'yes', 'создать', 'подтверждаю', '✅'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '⚙️ Создаю лид...');
                    $result = $this->bitrix->createLeadFromDialog($data);
                    $this->telegram->sendMessage($chatId, $result, 'Markdown');
                } elseif (in_array($answer, ['нет', 'no', 'отмена'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '❌ Создание лида отменено.');
                } else {
                    $this->telegram->sendMessage(
                        $chatId,
                        "Пожалуйста, ответьте *да* или *нет*.",
                        'Markdown'
                    );
                }
                break;
        }
    }

    private function buildSummary(array $data): string
    {
        $phone   = !empty($data['phone']) ? $data['phone'] : '—';
        $company = !empty($data['company']) ? $data['company'] : '—';
        $comment = !empty($data['comment']) ? $data['comment'] : '—';

        return "📋 *Проверьте данные лида:*\n\n"
            . "👤 Имя: *{$data['name']}*\n"
            . "📞 Телефон: *{$phone}*\n"
            . "🏢 Компания: *{$company}*\n"
            . "💬 Комментарий: *{$comment}*\n\n"
            . "Всё верно? Напишите *да* для создания или *нет* для отмены.";
    }

    private function normalizePhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (str_starts_with($clean, '8') && strlen($clean) === 11) {
            $clean = '+7' . substr($clean, 1);
        } elseif (str_starts_with($clean, '7') && strlen($clean) === 11) {
            $clean = '+' . $clean;
        }
        return $clean;
    }
}
