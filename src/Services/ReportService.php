<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

class ReportService
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly Logger $log
    ) {}

    // ─── Просроченные задачи ──────────────────────────────────────────────────

    public function getOverdueTasks(): string
    {
        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format(\DateTimeInterface::ATOM);
        $tasks  = $this->fetchAll('tasks.task.list', [
            'filter' => [
                '<DEADLINE'   => $now,
                '!STATUS'     => [4, 5, 6], // не завершённые/отклонённые
            ],
            'select' => ['ID', 'TITLE', 'DEADLINE', 'RESPONSIBLE_ID', 'STATUS'],
            'order'  => ['DEADLINE' => 'ASC'],
        ], 'tasks');

        if (empty($tasks)) {
            return '✅ Просроченных задач нет.';
        }

        // Группируем по ответственному
        $byUser = [];
        foreach ($tasks as $task) {
            $uid         = (int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);
            $byUser[$uid][] = $task;
        }

        // Загружаем имена пользователей
        $userNames = $this->fetchUserNames(array_keys($byUser));

        $lines   = ["⚠️ *Просроченные задачи:* " . count($tasks)];
        $lines[] = '';

        foreach ($byUser as $uid => $userTasks) {
            $name    = $userNames[$uid] ?? "Пользователь #$uid";
            $lines[] = "👤 *{$name}* (" . count($userTasks) . ' задач):';
            foreach ($userTasks as $task) {
                $id       = $task['id'] ?? $task['ID'];
                $title    = mb_substr($task['title'] ?? $task['TITLE'] ?? '—', 0, 60);
                $deadline = $task['deadline'] ?? $task['DEADLINE'] ?? '';
                $dStr     = $deadline ? date('d.m.Y', strtotime($deadline)) : 'без срока';
                $lines[]  = "  • [{$id}] {$title} — до {$dStr}";
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    // ─── Сделки по стадиям ────────────────────────────────────────────────────

    public function getDealsByStage(): string
    {
        $deals = $this->fetchAll('crm.deal.list', [
            'filter' => ['!STAGE_ID' => ['WON', 'LOSE']],
            'select' => ['ID', 'TITLE', 'STAGE_ID', 'OPPORTUNITY', 'CURRENCY_ID'],
        ], 'result');

        if (empty($deals)) {
            return '📊 Активных сделок не найдено.';
        }

        $stageNames = $this->fetchDealStageNames();

        $byStage = [];
        foreach ($deals as $deal) {
            $stage = $deal['STAGE_ID'] ?? '—';
            $byStage[$stage]['count']  = ($byStage[$stage]['count'] ?? 0) + 1;
            $byStage[$stage]['amount'] = ($byStage[$stage]['amount'] ?? 0.0) + (float)($deal['OPPORTUNITY'] ?? 0);
            $byStage[$stage]['currency'] = $deal['CURRENCY_ID'] ?? 'RUB';
        }

        arsort($byStage);

        $lines   = ['📊 *Сделки по стадиям:*', ''];
        $totalCount  = 0;
        $totalAmount = 0.0;

        foreach ($byStage as $stageId => $data) {
            $name    = $stageNames[$stageId] ?? $stageId;
            $amount  = number_format($data['amount'], 0, '.', ' ');
            $cur     = $data['currency'];
            $lines[] = "📌 *{$name}*";
            $lines[] = "   Сделок: {$data['count']} | Сумма: {$amount} {$cur}";
            $totalCount  += $data['count'];
            $totalAmount += $data['amount'];
        }

        $lines[] = '';
        $lines[] = "━━━━━━━━━━━━━━━";
        $lines[] = "Итого: {$totalCount} сделок | " . number_format($totalAmount, 0, '.', ' ') . ' RUB';

        return implode("\n", $lines);
    }

    // ─── Сделки по сотрудникам ────────────────────────────────────────────────

    public function getDealsByEmployee(): string
    {
        $deals = $this->fetchAll('crm.deal.list', [
            'filter' => ['!STAGE_ID' => ['WON', 'LOSE']],
            'select' => ['ID', 'ASSIGNED_BY_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'STAGE_ID'],
        ], 'result');

        if (empty($deals)) {
            return '📊 Активных сделок не найдено.';
        }

        $byUser = [];
        foreach ($deals as $deal) {
            $uid = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
            $byUser[$uid]['count']    = ($byUser[$uid]['count'] ?? 0) + 1;
            $byUser[$uid]['amount']   = ($byUser[$uid]['amount'] ?? 0.0) + (float)($deal['OPPORTUNITY'] ?? 0);
            $byUser[$uid]['currency'] = $deal['CURRENCY_ID'] ?? 'RUB';
        }

        // Сортируем по сумме
        uasort($byUser, fn($a, $b) => $b['amount'] <=> $a['amount']);

        $userNames = $this->fetchUserNames(array_keys($byUser));

        $lines       = ['👥 *Сделки по сотрудникам:*', ''];
        $totalCount  = 0;
        $totalAmount = 0.0;

        foreach ($byUser as $uid => $data) {
            $name    = $userNames[$uid] ?? "Пользователь #{$uid}";
            $amount  = number_format($data['amount'], 0, '.', ' ');
            $cur     = $data['currency'];
            $lines[] = "👤 *{$name}*";
            $lines[] = "   Сделок: {$data['count']} | Сумма: {$amount} {$cur}";
            $totalCount  += $data['count'];
            $totalAmount += $data['amount'];
        }

        $lines[] = '';
        $lines[] = "━━━━━━━━━━━━━━━";
        $lines[] = "Итого: {$totalCount} сделок | " . number_format($totalAmount, 0, '.', ' ') . ' RUB';

        return implode("\n", $lines);
    }

    // ─── Вспомогательные ──────────────────────────────────────────────────────

    private function fetchUserNames(array $ids): array
    {
        $ids = array_filter(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        $result = $this->call('user.get', ['filter' => ['ID' => array_values($ids)]]);
        $users  = $result['result'] ?? [];

        $names = [];
        foreach ($users as $user) {
            $id          = (int)$user['ID'];
            $names[$id]  = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));
            if ($names[$id] === '') {
                $names[$id] = $user['LOGIN'] ?? "#{$id}";
            }
        }

        return $names;
    }

    private function fetchDealStageNames(): array
    {
        $result = $this->call('crm.status.list', [
            'filter' => ['ENTITY_ID' => 'DEAL_STAGE'],
            'select' => ['STATUS_ID', 'NAME'],
        ]);

        $map = [];
        foreach ($result['result'] ?? [] as $item) {
            $map[$item['STATUS_ID']] = $item['NAME'];
        }

        return $map;
    }

    /**
     * Постранично выгружает все записи Bitrix24.
     * $resultKey — 'tasks' для tasks.task.list, 'result' для crm.*
     */
    private function fetchAll(string $method, array $params, string $resultKey): array
    {
        $items = [];
        $start = 0;

        do {
            $p        = $params;
            $p['start'] = $start;
            $response = $this->call($method, $p);

            $page   = ($resultKey === 'tasks')
                ? ($response['result']['tasks'] ?? [])
                : ($response['result'] ?? []);

            $items  = array_merge($items, $page);
            $total  = (int)($response['total'] ?? count($page));
            $start += 50;
        } while (count($page) === 50 && $start < $total && $start < 500);
        // Ограничение 500 записей чтобы не перегружать API

        return $items;
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
            CURLOPT_TIMEOUT        => 30,
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
        $this->log->debug('Bitrix24 response', ['method' => $method, 'code' => $code]);

        return $decoded;
    }
}
