<?php

declare(strict_types=1);

namespace App\Dialog;

use App\Session\SessionManager;
use App\Services\Bitrix24Service;
use App\Services\TelegramService;

/**
 * Пошаговый диалог постановки задачи.
 *
 * Шаги:
 *   1. link_type   — К чему привязать (сделка / компания / контакт / без привязки)
 *   2. link_query  — Название/ID сделки, компании или контакта
 *   3. responsible — Ответственный
 *   4. deadline    — Крайний срок
 *   5. type        — Назначение задачи (позвонить / написать / напомнить / узнать контакт / другое)
 *   6. comment     — Комментарий: что нужно сделать и что было сделано
 *   7. confirm     — Подтверждение
 */
class TaskDialog
{
    private const TASK_TYPES = [
        '1' => '📞 Позвонить',
        '2' => '✉️ Написать',
        '3' => '🔔 Напомнить',
        '4' => '🔍 Узнать контакт',
        '5' => '🗂 Раскопать целиком',
        '6' => '✏️ Другое',
    ];

    private const LINK_TYPES = [
        '1' => 'Сделка',
        '2' => 'Компания',
        '3' => 'Контакт',
        '4' => 'Без привязки',
    ];

    public function __construct(
        private readonly SessionManager  $session,
        private readonly Bitrix24Service $bitrix,
        private readonly TelegramService $telegram,
    ) {}

    public function start(int $userId, int $chatId): void
    {
        $this->session->set($userId, [
            'dialog' => 'task',
            'step'   => 'link_type',
            'data'   => [],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📝 *Постановка задачи*\n\nОтвечайте на вопросы по очереди. В любой момент напишите /отмена чтобы прервать.\n\n"
            . "1️⃣ *К чему привязать задачу?*\n\n"
            . "1 — Сделка\n"
            . "2 — Компания\n"
            . "3 — Контакт\n"
            . "4 — Без привязки",
            'Markdown'
        );
    }

    public function isActive(int $userId): bool
    {
        $s = $this->session->get($userId);
        return ($s['dialog'] ?? '') === 'task';
    }

    public function handle(int $userId, int $chatId, string $text): void
    {
        if (in_array(mb_strtolower(trim($text)), ['/отмена', 'отмена', 'cancel', '/cancel'], true)) {
            $this->session->clear($userId);
            $this->telegram->sendMessage($chatId, '❌ Постановка задачи отменена.');
            return;
        }

        $s    = $this->session->get($userId);
        $step = $s['step'] ?? 'link_type';
        $data = $s['data'] ?? [];

        switch ($step) {
            case 'link_type':
                $choice = trim($text);
                $linkType = self::LINK_TYPES[$choice] ?? null;

                if ($choice === '4' || mb_strtolower($choice) === 'без привязки') {
                    $data['link_type'] = 'none';
                    $this->session->set($userId, ['dialog' => 'task', 'step' => 'responsible', 'data' => $data]);
                    $this->telegram->sendMessage($chatId, "2️⃣ *Ответственный?*\n\nНапишите имя сотрудника или «нет».", 'Markdown');
                } elseif ($linkType) {
                    $data['link_type'] = mb_strtolower($linkType);
                    $this->session->set($userId, ['dialog' => 'task', 'step' => 'link_query', 'data' => $data]);
                    $this->telegram->sendMessage(
                        $chatId,
                        "1️⃣ *Введите название или ID {$linkType}:*",
                        'Markdown'
                    );
                } else {
                    $this->telegram->sendMessage($chatId, "Пожалуйста, введите цифру от 1 до 4.");
                }
                break;

            case 'link_query':
                $data['link_query'] = $text;
                $this->session->set($userId, ['dialog' => 'task', 'step' => 'responsible', 'data' => $data]);
                $this->telegram->sendMessage($chatId, "2️⃣ *Ответственный?*\n\nНапишите имя сотрудника или «нет».", 'Markdown');
                break;

            case 'responsible':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['responsible'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'task', 'step' => 'deadline', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "3️⃣ *Крайний срок?*\n\nНапример: «завтра», «пятница», «20 июня», «нет».",
                    'Markdown'
                );
                break;

            case 'deadline':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['deadline'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'task', 'step' => 'type', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "4️⃣ *Назначение задачи:*\n\n"
                    . "1 — 📞 Позвонить\n"
                    . "2 — ✉️ Написать\n"
                    . "3 — 🔔 Напомнить\n"
                    . "4 — 🔍 Узнать контакт\n"
                    . "5 — 🗂 Раскопать целиком\n"
                    . "6 — ✏️ Другое",
                    'Markdown'
                );
                break;

            case 'type':
                $choice = trim($text);
                $taskType = self::TASK_TYPES[$choice] ?? null;

                if ($taskType) {
                    $data['task_type'] = $taskType;
                } else {
                    // Если написали текстом — принимаем как есть
                    $data['task_type'] = $text;
                }

                $this->session->set($userId, ['dialog' => 'task', 'step' => 'comment', 'data' => $data]);
                $this->telegram->sendMessage(
                    $chatId,
                    "5️⃣ *Комментарий:*\n\nЧто нужно сделать и что было сделано ранее.\nМожно написать «нет».",
                    'Markdown'
                );
                break;

            case 'comment':
                $skip = in_array(mb_strtolower(trim($text)), ['нет', 'пропустить', 'skip', '-'], true);
                $data['comment'] = $skip ? '' : $text;
                $this->session->set($userId, ['dialog' => 'task', 'step' => 'confirm', 'data' => $data]);
                $this->telegram->sendMessage($chatId, $this->buildSummary($data), 'Markdown');
                break;

            case 'confirm':
                $answer = mb_strtolower(trim($text));
                if (in_array($answer, ['да', 'yes', 'создать', 'подтверждаю', '✅'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '⚙️ Создаю задачу...');
                    $result = $this->bitrix->createTaskFromDialog($data);
                    $this->telegram->sendMessage($chatId, $result, 'Markdown');
                } elseif (in_array($answer, ['нет', 'no', 'отмена'], true)) {
                    $this->session->clear($userId);
                    $this->telegram->sendMessage($chatId, '❌ Постановка задачи отменена.');
                } else {
                    $this->telegram->sendMessage($chatId, "Пожалуйста, ответьте *да* или *нет*.", 'Markdown');
                }
                break;
        }
    }

    private function buildSummary(array $data): string
    {
        $linkStr = '';
        if (!empty($data['link_type']) && $data['link_type'] !== 'none') {
            $typeLabel = match($data['link_type']) {
                'сделка'  => 'Сделка',
                'компания' => 'Компания',
                'контакт' => 'Контакт',
                default   => 'Привязка',
            };
            $linkStr = "\n🔗 {$typeLabel}: " . ($data['link_query'] ?? '—');
        }

        $responsible = !empty($data['responsible']) ? $data['responsible'] : '—';
        $deadline    = !empty($data['deadline']) ? $data['deadline'] : '—';
        $taskType    = !empty($data['task_type']) ? $data['task_type'] : '—';
        $comment     = !empty($data['comment']) ? $data['comment'] : '—';

        // Название задачи = тип + привязка
        $title = $this->buildTitle($data);

        return "📋 *Проверьте данные задачи:*\n\n"
            . "🏷 Название: *{$title}*"
            . $linkStr . "\n"
            . "👤 Ответственный: *{$responsible}*\n"
            . "📅 Срок: *{$deadline}*\n"
            . "🎯 Назначение: *{$taskType}*\n"
            . "💬 Комментарий: *{$comment}*\n\n"
            . "Всё верно? Напишите *да* для создания или *нет* для отмены.";
    }

    public function buildTitle(array $data): string
    {
        $type  = $data['task_type'] ?? '';
        $query = $data['link_query'] ?? '';

        // Убираем эмодзи из типа для заголовка
        $typeClean = preg_replace('/[\x{1F300}-\x{1FFFF}\x{2600}-\x{27FF}]\s*/u', '', $type);
        $typeClean = trim($typeClean);

        if ($query) {
            return "{$typeClean}: {$query}";
        }
        return $typeClean ?: 'Новая задача';
    }
}
