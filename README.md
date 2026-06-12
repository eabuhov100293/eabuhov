# Голосовой помощник для Bitrix24 через Telegram

Telegram-бот, который принимает голосовые и текстовые команды, распознаёт их через **Yandex SpeechKit**, понимает намерение с помощью **Yandex GPT** и выполняет действия в **Bitrix24** через REST API. Может отвечать голосом (TTS).

## Возможности

| Команда (голос/текст) | Действие в Bitrix24 |
|---|---|
| «Создай задачу позвонить клиенту до пятницы» | Создание задачи со сроком |
| «Создай задачу для Ивана срок 20 июня» | Задача с ответственным |
| «Измени срок задачи 123 на 25 июня» | Обновление дедлайна |
| «Покажи задачу 123» | Просмотр задачи |
| «Покажи мои задачи» | Список активных задач |
| «Создай лид Иван Иванов телефон 79001234567» | Новый лид в CRM |
| «Создай сделку покупка оборудования сумма 50000» | Новая сделка в CRM |
| «Найди лид Иванов» | Поиск лидов |
| «Найди сделку оборудование» | Поиск сделок |

## Требования

- PHP 8.1+ с расширениями `curl`, `json`
- Composer
- Публичный HTTPS-сервер (для Telegram Webhook)
- ИЛИ Docker + docker-compose

## Быстрый старт (Docker)

```bash
git clone <repo-url>
cd <repo-dir>

cp .env.example .env
# Заполните .env (см. ниже)

docker-compose up -d
php setup_webhook.php set https://your-domain.com/
```

## Установка без Docker

```bash
composer install
cp .env.example .env
# Заполните .env
php setup_webhook.php set https://your-domain.com/
```

## Настройка .env

```env
# Telegram Bot (от @BotFather)
TELEGRAM_BOT_TOKEN=...

# Yandex Cloud
YANDEX_API_KEY=...
YANDEX_FOLDER_ID=...

# Bitrix24 входящий вебхук
BITRIX24_WEBHOOK_URL=https://your-domain.bitrix24.ru/rest/1/your_token/

# Доступ: ID пользователей через запятую (пусто = все)
ALLOWED_USER_IDS=

# Безопасность вебхука (рекомендуется: openssl rand -hex 32)
WEBHOOK_SECRET=

# Голосовые ответы: бот отвечает голосом на голосовые команды
TTS_ENABLED=false

# Логи
LOG_LEVEL=info
LOG_FILE=logs/bot.log
```

## Получение ключей

### Telegram Bot
1. [@BotFather](https://t.me/BotFather) → `/newbot`
2. Скопируйте токен → `TELEGRAM_BOT_TOKEN`

### Yandex Cloud (SpeechKit + YandexGPT)
1. [console.cloud.yandex.ru](https://console.cloud.yandex.ru) → создайте каталог
2. Скопируйте ID каталога → `YANDEX_FOLDER_ID`
3. IAM → Сервисные аккаунты → создайте аккаунт с ролями:
   - `ai.speechkit.tts` и `ai.speechkit.stt` (SpeechKit)
   - `ai.languageModels.user` (YandexGPT)
4. Создайте API-ключ → `YANDEX_API_KEY`

### Bitrix24 Webhook
1. **Разработчикам → Входящий вебхук**
2. Права: `task`, `crm`, `user`
3. Скопируйте URL → `BITRIX24_WEBHOOK_URL`

## Структура проекта

```
├── index.php                   # Точка входа (webhook handler)
├── setup_webhook.php           # Регистрация/удаление вебхука
├── docker-compose.yml
├── Dockerfile
├── docker/
│   ├── nginx.conf
│   └── supervisord.conf
└── src/
    ├── Config/Config.php
    ├── Bot/TelegramBot.php
    └── Services/
        ├── TelegramService.php   # Telegram Bot API + sendVoice
        ├── SpeechKitService.php  # STT + TTS
        ├── YandexGptService.php  # Разбор команд
        └── Bitrix24Service.php   # REST API Bitrix24
```

## Архитектура

```
Голосовое сообщение (OGG/OPUS)
        ↓
SpeechKitService.recognize()   ← Yandex SpeechKit STT
        ↓
YandexGptService.parseIntent() ← Yandex GPT → JSON с action и полями
        ↓
Bitrix24Service.*()            ← REST API Bitrix24
        ↓
TelegramService.sendMessage()  ← текстовый ответ
        ↓  (если TTS_ENABLED=true)
SpeechKitService.synthesize()  ← Yandex SpeechKit TTS
        ↓
TelegramService.sendVoice()    ← голосовой ответ
```

## Безопасность

- Установите `ALLOWED_USER_IDS` для ограничения доступа
- Установите `WEBHOOK_SECRET` — Telegram передаёт его в заголовке, бот проверяет
- `.env` защищён nginx (`deny all`) в Docker-конфигурации
- Используйте HTTPS для webhook
