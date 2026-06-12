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

    // ─── Задачи ──────────────────────────────────────────────────────────────

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

    // ─── CRM: Лиды ───────────────────────────────────────────────────────────

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

    // ─── CRM: Сделки ─────────────────────────────────────────────────────────

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

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    private function findUserId(string $name): ?int
    {
        $result = $this->call('user.search', ['FILTER' => ['NAME' => $name]]);
        return $result['result'][0]['ID'] ?? null;
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
