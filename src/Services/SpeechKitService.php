<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * Yandex SpeechKit — STT (speech-to-text).
 * Telegram голосовые сообщения приходят в формате OGG/OPUS.
 * SpeechKit v1 принимает OGG OPUS напрямую через параметр format=oggopus.
 */
class SpeechKitService
{
    private const API_URL = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $folderId,
        private readonly Logger $log
    ) {}

    public function recognize(string $audioData): ?string
    {
        $params = http_build_query([
            'folderId' => $this->folderId,
            'lang'     => 'ru-RU',
            'format'   => 'oggopus',
        ]);

        $url = self::API_URL . '?' . $params;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $audioData,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Api-Key ' . $this->apiKey,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->log->error("SpeechKit cURL error: {$error}");
            return null;
        }

        $decoded = json_decode($result, true);
        $this->log->debug('SpeechKit response', ['code' => $code, 'response' => $decoded]);

        return $decoded['result'] ?? null;
    }
}
