<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * Yandex SpeechKit — STT и TTS.
 * Telegram голосовые сообщения приходят в формате OGG/OPUS.
 * SpeechKit v1 принимает OGG OPUS напрямую через параметр format=oggopus.
 */
class SpeechKitService
{
    private const STT_URL = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize';
    private const TTS_URL = 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize';

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

        $url = self::STT_URL . '?' . $params;

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
            $this->log->error("SpeechKit STT cURL error: {$error}");
            return null;
        }

        $decoded = json_decode($result, true);
        $this->log->debug('SpeechKit STT response', ['code' => $code, 'response' => $decoded]);

        return $decoded['result'] ?? null;
    }

    /**
     * Синтез речи (TTS). Возвращает OGG/OPUS аудио или null при ошибке.
     */
    public function synthesize(string $text): ?string
    {
        // Обрезаем текст до 5000 символов — лимит SpeechKit
        $text = mb_substr($text, 0, 5000);

        $ch = curl_init(self::TTS_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'folderId'    => $this->folderId,
                'text'        => $text,
                'lang'        => 'ru-RU',
                'voice'       => 'alena',
                'format'      => 'oggopus',
                'speed'       => '1.0',
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Api-Key ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->log->error("SpeechKit TTS cURL error: {$error}");
            return null;
        }

        if ($code !== 200) {
            $this->log->error("SpeechKit TTS HTTP {$code}", ['response' => $result]);
            return null;
        }

        return $result;
    }
}
