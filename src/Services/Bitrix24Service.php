<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

class Bitrix24Service
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly Logger $log
    ) {}

    // ─── Задачи (диалог) ─────────────────────────────────────────────────────

    public function createTaskFromDialog(array $data): string
    {
        $title = $data['task_type'] ?? 'Новая задача';
        // Убираем эмодзи из типа
        $typeClean = preg_replace('/[\x{1F300}-\x{1FFFF}\x{2600}-\x{27FF}]\s*/u', '', $title);
        $title = trim($typeClean);
        if (!empty($data['link_query'])) {
            $title .= ': ' . $data['link_query'];
        }

        $description = $data['comment'] ?? '';

        $fields = [
            'TITLE'       => $title,
            'DESCRIPTION' => $description,
        ];

        // Ответственный
        if (!empty($data['responsible'])) {
            $userId = $this->findUserId($data['responsible']);
            if ($userId) {
                $fields['RESPONSIBLE_ID'] = $userId;
            }
        }

        // Срок
        if (!empty($data['deadline'])) {
            $deadline = $this->parseRussianDate($data['deadline']);
            if ($deadline) {
                $fields['DEADLINE'] = $this->toIso8601($deadline);
            }
        }

        // Привязка к сделке / компании / контакту
        if (!empty($data['link_type']) && $data['link_type'] !== 'none' && !empty($data['link_query'])) {
            $this->attachTaskLink($fields, $data['link_type'], $data['link_query']);
        }

        $result = $this->call('tasks.task.add', ['fields' => $fields]);
        $taskId = $result['result']['task']['id'] ?? null;

        if (!$taskId) {
            $this->log->error('tasks.task.add (dialog) failed', ['result' => $result]);
            return '❌ Не удалось создать задачу. Проверьте настройки Bitrix24.';
        }

        $portalUrl   = $this->getPortalUrl();
        $responsible = !empty($data['responsible']) ? "\n👤 Ответственный: {$data['responsible']}" : '';
        $deadline    = !empty($data['deadline']) ? "\n📅 Срок: {$data['deadline']}" : '';
        $link        = (!empty($data['link_type']) && $data['link_type'] !== 'none' && !empty($data['link_query']))
            ? "\n🔗 Привязка: {$data['link_query']}"
            : '';

        return "✅ *Задача создана!*\n\n"
            . "🏷 Название: {$title}"
            . $responsible . $deadline . $link
            . "\n🆔 ID: {$taskId}\n"
            . "{$portalUrl}/company/personal/user/0/tasks/task/view/{$taskId}/";
    }

    private function attachTaskLink(array &$fields, string $linkType, string $query): void
    {
        switch ($linkType) {
            case 'сделка':
                // Если число — используем как ID, иначе ищем по названию
                if (is_numeric($query)) {
                    $fields['UF_CRM_TASK'] = ["D_{$query}"];
                } else {
                    $result = $this->call('crm.deal.list', [
                        'filter' => ['%TITLE' => $query],
                        'select' => ['ID'],
                    ]);
                    $dealId = $result['result'][0]['ID'] ?? null;
                    if ($dealId) {
                        $fields['UF_CRM_TASK'] = ["D_{$dealId}"];
                    }
                }
                break;

            case 'компания':
                $companyId = is_numeric($query)
                    ? (int)$query
                    : $this->findCompanyId($query);
                if ($companyId) {
                    $fields['UF_CRM_TASK'] = ["CO_{$companyId}"];
                }
                break;

            case 'контакт':
                $contactId = is_numeric($query)
                    ? (int)$query
                    : $this->findContactId($query);
                if ($contactId) {
                    $fields['UF_CRM_TASK'] = ["C_{$contactId}"];
                }
                break;
        }
    }

    private function parseRussianDate(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));

        $map = [
            'сегодня'      => $today->format('Y-m-d'),
            'завтра'       => $today->modify('+1 day')->format('Y-m-d'),
            'послезавтра'  => $today->modify('+2 days')->format('Y-m-d'),
            'понедельник'  => $this->nextWeekday($today, 1),
            'вторник'      => $this->nextWeekday($today, 2),
            'среда'        => $this->nextWeekday($today, 3),
            'среду'        => $this->nextWeekday($today, 3),
            'четверг'      => $this->nextWeekday($today, 4),
            'пятница'      => $this->nextWeekday($today, 5),
            'пятницу'      => $this->nextWeekday($today, 5),
            'суббота'      => $this->nextWeekday($today, 6),
            'субботу'      => $this->nextWeekday($today, 6),
            'воскресенье'  => $this->nextWeekday($today, 7),
        ];

        if (isset($map[$text])) {
            return $map[$text];
        }

        // Попробуем PHP парсер
        try {
            $dt = new \DateTimeImmutable($text, new \DateTimeZone('Europe/Moscow'));
            return $dt->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function nextWeekday(\DateTimeImmutable $from, int $weekday): string
    {
        $current = (int)$from->format('N');
        $diff = $weekday - $current;
        if ($diff <= 0) {
            $diff += 7;
        }
        return $from->modify("+{$diff} days")->format('Y-m-d');
    }

    // ─── Задачи (голос/текст) ─────────────────────────────────────────────────

    public function createTask(array $intent): string
    {
        $fields = [
            'TITLE'       => $intent['title'] ?? 'Новая задача',
            'DESCRIPTION' => $intent['description'] ?? '',
        ];

        if (!empty($intent['responsible'])) {
            $userId = $this->findUserId($intent['responsible']);
            if ($userId) {
                $fields['RESPONSIBLE_ID'] = $userId;
            }
        }

        if (!empty($intent['deadline'])) {
            $fields['DEADLINE'] = $this->toIso8601($intent['deadline']);
        }

        $result = $this->call('tasks.task.add', ['fields' => $fields]);

        $taskId = $result['result']['task']['id'] ?? null;
        if (!$taskId) {
            $this->log->error('tasks.task.add failed', ['result' => $result]);
            return '❌ Не удалось создать задачу. Проверьте настройки Bitrix24.';
        }

        $taskUrl  = $this->getPortalUrl() . "/company/personal/user/0/tasks/task/view/{$taskId}/";
        $deadline = !empty($intent['deadline']) ? "\nСрок: {$intent['deadline']}" : '';
        $resp     = !empty($intent['responsible']) ? "\nОтветственный: {$intent['responsible']}" : '';

        return "✅ Задача создана!\n\nНазвание: {$fields['TITLE']}{$deadline}{$resp}\nID: {$taskId}";
    }

    public function updateTaskDeadline(array $intent): string
    {
        $taskId   = (int)($intent['task_id'] ?? 0);
        $deadline = $intent['deadline'] ?? '';

        if (!$taskId || !$deadline) {
            return '❌ Укажите ID задачи и новый срок. Например: «Измени срок задачи 123 на 25 июня».';
        }

        $result = $this->call('tasks.task.update', [
            'taskId' => $taskId,
            'fields' => ['DEADLINE' => $this->toIso8601($deadline)],
        ]);

        if (isset($result['error'])) {
            $this->log->error('tasks.task.update failed', ['result' => $result]);
            return "❌ Не удалось обновить задачу #{$taskId}: " . ($result['error_description'] ?? '');
        }

        return "✅ Срок задачи #{$taskId} изменён на {$deadline}.";
    }

    // ─── CRM: Лиды (диалог) ──────────────────────────────────────────────────

    public function createLeadFromDialog(array $data): string
    {
        $name  = $data['name'] ?? 'Новый лид';
        $title = $name;

        $fields = [
            'TITLE'    => $title,
            'NAME'     => $name,
            'COMMENTS' => $data['comment'] ?? '',
        ];

        if (!empty($data['phone'])) {
            $fields['PHONE'] = [['VALUE' => $data['phone'], 'VALUE_TYPE' => 'WORK']];
        }

        // Компания — ищем, если не найдена — создаём
        if (!empty($data['company'])) {
            $companyId = $this->findCompanyId($data['company']);
            if (!$companyId) {
                $companyId = $this->createCompany($data['company']);
            }
            if ($companyId) {
                $fields['COMPANY_ID'] = $companyId;
            }
        }

        $result = $this->call('crm.lead.add', ['fields' => $fields]);
        $leadId = $result['result'] ?? null;

        if (!$leadId) {
            $this->log->error('crm.lead.add (dialog) failed', ['result' => $result]);
            return '❌ Не удалось создать лид. Проверьте настройки Bitrix24.';
        }

        $portalUrl = $this->getPortalUrl();
        $phone     = !empty($data['phone']) ? "\n📞 Телефон: {$data['phone']}" : '';
        $company   = !empty($data['company']) ? "\n🏢 Компания: {$data['company']}" : '';
        $comment   = !empty($data['comment']) ? "\n💬 Комментарий: {$data['comment']}" : '';

        return "✅ *Лид создан!*\n\n"
            . "👤 Имя: {$name}"
            . $phone . $company . $comment
            . "\n🆔 ID: {$leadId}\n"
            . "{$portalUrl}/crm/lead/details/{$leadId}/";
    }

    // ─── CRM: Лиды (голос/текст) ─────────────────────────────────────────────

    public function createLead(array $intent): string
    {
        $title = $intent['title'] ?? ($intent['name'] ?? 'Новый лид');

        $fields = [
            'TITLE'  => $title,
            'NAME'   => $intent['name'] ?? '',
            'COMMENTS' => $intent['comment'] ?? '',
        ];

        if (!empty($intent['phone'])) {
            $fields['PHONE'] = [['VALUE' => $intent['phone'], 'VALUE_TYPE' => 'WORK']];
        }

        if (!empty($intent['email'])) {
            $fields['EMAIL'] = [['VALUE' => $intent['email'], 'VALUE_TYPE' => 'WORK']];
        }

        $result = $this->call('crm.lead.add', ['fields' => $fields]);

        $leadId = $result['result'] ?? null;
        if (!$leadId) {
            $this->log->error('crm.lead.add failed', ['result' => $result]);
            return '❌ Не удалось создать лид.';
        }

        $phone = !empty($intent['phone']) ? "\nТелефон: {$intent['phone']}" : '';
        return "✅ Лид создан!\n\nНазвание: {$title}{$phone}\nID: {$leadId}";
    }

    // ─── CRM: Сделки (диалог) ────────────────────────────────────────────────

    public function createDealFromDialog(array $data): string
    {
        $title = $data['title'] ?? 'Новая сделка';

        $fields = [
            'TITLE'       => $title,
            'CURRENCY_ID' => 'RUB',
        ];

        // Сумма
        if (!empty($data['amount'])) {
            $num = preg_replace('/[^\d.]/', '', $data['amount']);
            if ($num) {
                $fields['OPPORTUNITY'] = (float)$num;
            }
        }

        // Компания
        if (!empty($data['company'])) {
            $companyId = $this->findCompanyId($data['company']);
            if ($companyId) {
                $fields['COMPANY_ID'] = $companyId;
            } else {
                // Создаём компанию если не найдена
                $newCompanyId = $this->createCompany($data['company']);
                if ($newCompanyId) {
                    $fields['COMPANY_ID'] = $newCompanyId;
                }
            }
        }

        // Ответственный
        if (!empty($data['responsible'])) {
            $userId = $this->findUserId($data['responsible']);
            if ($userId) {
                $fields['ASSIGNED_BY_ID'] = $userId;
            }
        }

        // Заказчик (контакт)
        if (!empty($data['contact'])) {
            $contactId = $this->findContactId($data['contact']);
            if ($contactId) {
                $fields['CONTACT_ID'] = $contactId;
            }
        }

        $result = $this->call('crm.deal.add', ['fields' => $fields]);
        $dealId = $result['result'] ?? null;

        if (!$dealId) {
            $this->log->error('crm.deal.add (dialog) failed', ['result' => $result]);
            return '❌ Не удалось создать сделку. Проверьте настройки Bitrix24.';
        }

        $portalUrl = $this->getPortalUrl();
        $amount    = !empty($data['amount']) ? "\n💰 Сумма: " . $this->formatAmount($data['amount']) : '';
        $company   = !empty($data['company']) ? "\n🏢 Компания: {$data['company']}" : '';
        $resp      = !empty($data['responsible']) ? "\n👤 Ответственный: {$data['responsible']}" : '';
        $contact   = !empty($data['contact']) ? "\n🤝 Заказчик: {$data['contact']}" : '';

        return "✅ *Сделка создана!*\n\n"
            . "🏷 Название: {$title}"
            . $amount . $company . $resp . $contact
            . "\n🆔 ID: {$dealId}\n"
            . "{$portalUrl}/crm/deal/details/{$dealId}/";
    }

    private function formatAmount(string $amount): string
    {
        $num = preg_replace('/[^\d.]/', '', $amount);
        if (!$num) {
            return $amount;
        }
        return number_format((float)$num, 0, '.', ' ') . ' ₽';
    }

    private function findCompanyId(string $name): ?int
    {
        $result = $this->call('crm.company.list', [
            'filter' => ['%TITLE' => $name],
            'select' => ['ID', 'TITLE'],
        ]);
        return isset($result['result'][0]['ID']) ? (int)$result['result'][0]['ID'] : null;
    }

    private function createCompany(string $name): ?int
    {
        $result = $this->call('crm.company.add', [
            'fields' => ['TITLE' => $name],
        ]);
        return isset($result['result']) ? (int)$result['result'] : null;
    }

    // ─── CRM: Сделки (голос/текст) ───────────────────────────────────────────

    public function createDeal(array $intent): string
    {
        $title = $intent['title'] ?? 'Новая сделка';

        $fields = [
            'TITLE'        => $title,
            'COMMENTS'     => $intent['comment'] ?? '',
            'CURRENCY_ID'  => $intent['currency'] ?? 'RUB',
        ];

        if (!empty($intent['amount'])) {
            $fields['OPPORTUNITY'] = (float)$intent['amount'];
        }

        if (!empty($intent['contact_name'])) {
            $contactId = $this->findContactId($intent['contact_name']);
            if ($contactId) {
                $fields['CONTACT_ID'] = $contactId;
            }
        }

        $result = $this->call('crm.deal.add', ['fields' => $fields]);

        $dealId = $result['result'] ?? null;
        if (!$dealId) {
            $this->log->error('crm.deal.add failed', ['result' => $result]);
            return '❌ Не удалось создать сделку.';
        }

        $amount = !empty($intent['amount'])
            ? "\nСумма: " . number_format((float)$intent['amount'], 0, '.', ' ') . ' ' . ($intent['currency'] ?? 'RUB')
            : '';

        return "✅ Сделка создана!\n\nНазвание: {$title}{$amount}\nID: {$dealId}";
    }

    // ─── Просмотр задач ──────────────────────────────────────────────────────

    public function getTask(array $intent): string
    {
        $taskId = (int)($intent['task_id'] ?? 0);
        if (!$taskId) {
            return '❌ Укажите ID задачи. Например: «Покажи задачу 123».';
        }

        $result = $this->call('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['ID', 'TITLE', 'DESCRIPTION', 'STATUS', 'DEADLINE', 'RESPONSIBLE_ID', 'CREATED_DATE'],
        ]);

        $task = $result['result']['task'] ?? null;
        if (!$task) {
            return "❌ Задача #{$taskId} не найдена.";
        }

        return $this->formatTask($task);
    }

    public function listMyTasks(array $intent): string
    {
        $filter = ['REAL_STATUS' => [2, 3]]; // в работе + ожидание
        if (!empty($intent['responsible'])) {
            $userId = $this->findUserId($intent['responsible']);
            if ($userId) {
                $filter['RESPONSIBLE_ID'] = $userId;
            }
        }

        $result = $this->call('tasks.task.list', [
            'filter' => $filter,
            'select' => ['ID', 'TITLE', 'STATUS', 'DEADLINE'],
            'order'  => ['DEADLINE' => 'ASC'],
            'params' => ['NAV_PARAMS' => ['nPageSize' => 10]],
        ]);

        $tasks = $result['result']['tasks'] ?? [];
        if (empty($tasks)) {
            return '📋 Активных задач не найдено.';
        }

        $lines = ['📋 *Активные задачи:*'];
        foreach ($tasks as $task) {
            $deadline = $task['deadline'] ? ' — до ' . date('d.m.Y', strtotime($task['deadline'])) : '';
            $lines[]  = "• [{$task['id']}] {$task['title']}{$deadline}";
        }

        return implode("\n", $lines);
    }

    // ─── Поиск в CRM ─────────────────────────────────────────────────────────

    public function searchLeads(array $intent): string
    {
        $query = $intent['query'] ?? '';
        if (!$query) {
            return '❌ Укажите строку поиска. Например: «Найди лид Иванов».';
        }

        $result = $this->call('crm.lead.list', [
            'filter' => ['%TITLE' => $query],
            'select' => ['ID', 'TITLE', 'NAME', 'PHONE', 'STATUS_ID', 'DATE_CREATE'],
            'order'  => ['DATE_CREATE' => 'DESC'],
        ]);

        $leads = $result['result'] ?? [];
        if (empty($leads)) {
            return "🔍 Лиды по запросу «{$query}» не найдены.";
        }

        $lines = ["🔍 *Найдено лидов: " . count($leads) . ":*"];
        foreach (array_slice($leads, 0, 5) as $lead) {
            $phone   = $lead['PHONE'][0]['VALUE'] ?? '';
            $phoneStr = $phone ? " | {$phone}" : '';
            $lines[] = "• [{$lead['ID']}] {$lead['TITLE']}{$phoneStr}";
        }

        return implode("\n", $lines);
    }

    public function searchDeals(array $intent): string
    {
        $query = $intent['query'] ?? '';
        if (!$query) {
            return '❌ Укажите строку поиска. Например: «Найди сделку оборудование».';
        }

        $result = $this->call('crm.deal.list', [
            'filter' => ['%TITLE' => $query],
            'select' => ['ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'STAGE_ID', 'DATE_CREATE'],
            'order'  => ['DATE_CREATE' => 'DESC'],
        ]);

        $deals = $result['result'] ?? [];
        if (empty($deals)) {
            return "🔍 Сделки по запросу «{$query}» не найдены.";
        }

        $lines = ["🔍 *Найдено сделок: " . count($deals) . ":*"];
        foreach (array_slice($deals, 0, 5) as $deal) {
            $amount   = $deal['OPPORTUNITY'] ? ' | ' . number_format((float)$deal['OPPORTUNITY'], 0, '.', ' ') . ' ' . $deal['CURRENCY_ID'] : '';
            $lines[]  = "• [{$deal['ID']}] {$deal['TITLE']}{$amount}";
        }

        return implode("\n", $lines);
    }

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    private function formatTask(array $task): string
    {
        $statusMap = [
            '1' => 'Ждёт выполнения',
            '2' => 'В работе',
            '3' => 'Ожидание',
            '4' => 'Завершена',
            '5' => 'Отклонена',
            '6' => 'Считается завершённой',
        ];

        $status   = $statusMap[$task['status'] ?? ''] ?? 'Неизвестно';
        $deadline = !empty($task['deadline'])
            ? date('d.m.Y H:i', strtotime($task['deadline']))
            : 'не указан';
        $created  = !empty($task['createdDate'])
            ? date('d.m.Y', strtotime($task['createdDate']))
            : '';

        $text  = "📌 *Задача #{$task['id']}*\n";
        $text .= "Название: {$task['title']}\n";
        $text .= "Статус: {$status}\n";
        $text .= "Срок: {$deadline}\n";
        if ($created) {
            $text .= "Создана: {$created}\n";
        }
        if (!empty($task['description'])) {
            $desc = mb_substr(strip_tags($task['description']), 0, 200);
            $text .= "Описание: {$desc}";
        }

        return $text;
    }

    private function findUserId(string $name): ?int
    {
        $result = $this->call('user.search', ['FILTER' => ['NAME' => $name]]);
        $id = $result['result'][0]['ID'] ?? null;
        return $id !== null ? (int)$id : null;
    }

    private function findContactId(string $name): ?int
    {
        $parts = explode(' ', trim($name), 2);
        $filter = ['NAME' => $parts[0]];
        if (isset($parts[1])) {
            $filter['LAST_NAME'] = $parts[1];
        }

        $result = $this->call('crm.contact.list', [
            'filter' => $filter,
            'select' => ['ID', 'NAME', 'LAST_NAME'],
        ]);

        return $result['result'][0]['ID'] ?? null;
    }

    private function toIso8601(string $date): string
    {
        try {
            $dt = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Moscow'));
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return $date;
        }
    }

    private function getPortalUrl(): string
    {
        // Извлекает https://your-domain.bitrix24.ru из URL вебхука
        $parts = parse_url($this->webhookUrl);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    }

    private function call(string $method, array $params = []): array
    {
        $url = $this->webhookUrl . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->log->error("Bitrix24 cURL error: {$error}", ['method' => $method]);
            throw new \RuntimeException("Bitrix24 API error: {$error}");
        }

        $decoded = json_decode($result, true) ?? [];
        $this->log->debug('Bitrix24 response', ['method' => $method, 'code' => $code, 'response' => $decoded]);

        return $decoded;
    }
}
