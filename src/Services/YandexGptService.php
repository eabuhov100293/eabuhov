<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * Yandex GPT — разбирает намерение пользователя и извлекает параметры для Bitrix24.
 */
class YandexGptService
{
    private const API_URL = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    private string $modelUri;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $folderId,
        private readonly Logger $log
    ) {
        $this->modelUri = "gpt://{$folderId}/yandexgpt/latest";
    }

    /**
     * Возвращает массив с полями:
     *   action: create_task | update_task | get_task | list_tasks |
     *           create_lead | create_deal | search_leads | search_deals | unknown
     *   + поля, специфичные для каждого action
     */
    public function parseIntent(string $userText): ?array
    {
        $systemPrompt = $this->buildSystemPrompt();

        $body = [
            'modelUri' => $this->modelUri,
            'completionOptions' => [
                'stream'      => false,
                'temperature' => 0.1,
                'maxTokens'   => 150,
            ],
            'messages' => [
                ['role' => 'system', 'text' => $systemPrompt],
                ['role' => 'user',   'text' => $userText],
            ],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Api-Key ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->log->error("YandexGPT cURL error: {$error}");
            return null;
        }

        $decoded = json_decode($result, true);
        $this->log->debug('YandexGPT response', ['code' => $code, 'response' => $decoded]);

        $text = $decoded['result']['alternatives'][0]['message']['text'] ?? null;
        if (!$text) {
            $this->log->error('YandexGPT returned no text', ['decoded' => $decoded]);
            return null;
        }

        return $this->parseJson($text);
    }

    private function buildSystemPrompt(): string
    {
        $today = date('Y-m-d');

        return <<<PROMPT
Bitrix24 ассистент. Дата: {$today}. Верни ТОЛЬКО JSON.

Действия:
create_task: {"action":"create_task","title":"","responsible":"","deadline":"YYYY-MM-DD или ''"}
update_task: {"action":"update_task","task_id":0,"deadline":"YYYY-MM-DD"}
get_task: {"action":"get_task","task_id":0}
list_tasks: {"action":"list_tasks","responsible":""}
create_lead: {"action":"create_lead","name":"","phone":"","comment":""}
create_deal: {"action":"create_deal","title":"","amount":0,"contact_name":""}
search_leads: {"action":"search_leads","query":""}
search_deals: {"action":"search_deals","query":""}
unknown: {"action":"unknown"}

Правила: даты→YYYY-MM-DD, телефон нормализуй (8→+7), только JSON без markdown.
PROMPT;
    }

    private function parseJson(string $text): ?array
    {
        // Убираем возможные markdown-блоки ```json ... ```
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->error('Failed to parse GPT JSON', ['text' => $text]);
            return null;
        }

        return $decoded;
    }
}
