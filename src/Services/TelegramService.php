<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

class TelegramService
{
    private string $apiBase;

    public function __construct(
        private readonly string $token,
        private readonly Logger $log
    ) {
        $this->apiBase = "https://api.telegram.org/bot{$token}";
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = ''): void
    {
        $params = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];
        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $this->call('sendMessage', $params);
    }

    public function sendVoice(int $chatId, string $audioData): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tts_') . '.ogg';
        file_put_contents($tmpFile, $audioData);

        $url = "{$this->apiBase}/sendVoice";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'chat_id' => $chatId,
                'voice'   => new \CURLFile($tmpFile, 'audio/ogg', 'voice.ogg'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);
        @unlink($tmpFile);

        if ($error) {
            $this->log->error("sendVoice cURL error: {$error}");
        }

        $decoded = json_decode($result, true);
        if (!($decoded['ok'] ?? false)) {
            $this->log->error('sendVoice API error', ['response' => $decoded]);
        }
    }

    public function downloadFile(string $fileId): string
    {
        $response = $this->call('getFile', ['file_id' => $fileId]);
        $filePath = $response['result']['file_path'] ?? null;

        if (!$filePath) {
            throw new \RuntimeException('Telegram getFile returned no file_path');
        }

        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $data = $this->httpGet($url);

        if ($data === false || $data === '') {
            throw new \RuntimeException('Failed to download voice file from Telegram');
        }

        return $data;
    }

    private function call(string $method, array $params): array
    {
        $url  = "{$this->apiBase}/{$method}";
        $json = json_encode($params);

        $cmd    = 'curl -6 -s --max-time 10 -H ' . escapeshellarg('Content-Type: application/json')
                . ' -d ' . escapeshellarg($json)
                . ' ' . escapeshellarg($url);
        $result = shell_exec($cmd);

        if (!$result) {
            $this->log->error("Telegram shell_exec empty result", ['method' => $method, 'cmd' => $cmd]);
            throw new \RuntimeException("Telegram API shell_exec failed");
        }

        $decoded = json_decode($result, true);
        if (!($decoded['ok'] ?? false)) {
            $this->log->error('Telegram API error', ['method' => $method, 'response' => $decoded]);
        }

        return $decoded ?? [];
    }

    private function httpGet(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V6,
        ]);
        $data  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log->error("File download cURL error: {$error}");
            return false;
        }

        return $data;
    }
}
