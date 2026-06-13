<?php

declare(strict_types=1);

namespace App\Dialog;

use App\Session\SessionManager;
use App\Services\Bitrix24Service;
use App\Services\TelegramService;

/**
 * Пошаговый диалог создания сделки.
 *
 * Шаги:
 *   1. title       — Название сделки
 *   2. amount      — Сумма (можно пропустить)
 *   3. company     — Компания
 *   4. responsible — Ответственный
 *   5. contact     — Заказчик (контакт)
 *   6. confirm     — Подтверждение и создание
 */
class DealDialog
{
    private const STEPS = ['title', 'amount', 'company', 'responsible', 'contact', 'confirm'];

    public function __construct(
        private readonly SessionManager  $session,
        private readonly Bitrix24Service $bitrix,
        private readonly TelegramService $telegram,
    ) {}

    public function start(int $userId, int $chatId): void
    {
        $this->session->set($userId, [
            'dialog' => 'deal',
            'step'   => 'title',
            'data'   => [],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📝 *Создание сделки*\n\nОтвечайте на вопросы по очереди. В любой момент напишите /отмена чтобы прервать.\n\n1️⃣ *Название сделки?*",
            'Markdown'
        );
    }

    public function isActive(int $userId): bool
    {
        $s = $this->session->get($userId);
        return ($s['dialog'] ?? '') === 'deal';
    }

    public function handle(int $userId, int $chatId, string $text): void
    {
        if (in_array(mb_strtolower(trim($text)), ['/отмена', 'отмена', 'cancel', '/cancel'], true)) {
            $this->session->clear($userId);
            $this->telegram->sendMessage($chatId, '❌ Создание сделки отменено.');
            return;
        }

        $s    = $this->session->get($userId);
        $step = $s['step'] ?? 'title';
        $data = $s['data'] ?? [];

        switch ($step) {
            case 'title':
                $data['title'] = $text;
                $this->session->set($userId, ['dialog' => 'deal', 'step' => 'amount', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "2️⃣ *Сумма сделки?*\n\nЕсли КП ещё нет — напишите «нет» или «пропустить».",
                    'Markdown'
                );
                break;

            case 'amount':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-', '0'], true);
                $data['amount'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'deal', 'step' => 'company', 'data' => $data]);
                $this->telegram->sendMessage($chatId, "3️⃣ *Компания?*\n\nНапишите название компании или «нет».", 'Markdown');
                break;

            case 'company':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['company'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'deal', 'step' => 'responsible', 'data' => $data]);
                $this->telegram->sendMessage($chatId, "4️⃣ *Ответственный?*\n\nНапишите имя сотрудника или «нет».", 'Markdown');
                break;

            case 'responsible':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['responsible'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'deal', 'step' => 'contact', 'data' => $data]);
                $this->telegram->sendMessage($chatId, "5️⃣ *Заказчик (контакт)?*\n\nНапишите имя заказчика или «нет».", 'Markdown');
                break;

            case 'contact':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['contact'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'deal', 'step' => 'confirm', 'data' => $data]);
                $this->telegram->sendMessage($chatId, $this->buildSummary($data), 'Markdown');
                break;

            case 'confirm':
                $answer = mb_strtolower(trim($text));
                if (in_array($answer, ['да', 'yes', 'создать', 'подтверждаю', '✅'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '⚙️ Создаю сделку...');
                    $result = $this->bitrix->createDealFromDialog($data);
                    $this->telegram->sendMessage($chatId, $result, 'Markdown');
                } elseif (in_array($answer, ['нет', 'no', 'отмена'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '❌ Создание сделки отменено.');
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
        $amount      = !empty($data['amount']) ? $this->formatAmount($data['amount']) : '—';
        $company     = !empty($data['company']) ? $data['company'] : '—';
        $responsible = !empty($data['responsible']) ? $data['responsible'] : '—';
        $contact     = !empty($data['contact']) ? $data['contact'] : '—';

        return "📋 *Проверьте данные сделки:*\n\n"
            . "🏷 Название: *{$data['title']}*\n"
            . "💰 Сумма: *{$amount}*\n"
            . "🏢 Компания: *{$company}*\n"
            . "👤 Ответственный: *{$responsible}*\n"
            . "🤝 Заказчик: *{$contact}*\n\n"
            . "Всё верно? Напишите *да* для создания или *нет* для отмены.";
    }

    private function formatAmount(string $amount): string
    {
        $num = preg_replace('/[^\d.]/', '', $amount);
        if (!$num) {
            return $amount;
        }
        return number_format((float)$num, 0, '.', ' ') . ' ₽';
    }
}
