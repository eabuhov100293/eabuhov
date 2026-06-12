# Голосовой помощник для Bitrix24 через Telegram

Telegram-бот, который принимает голосовые и текстовые команды, распознаёт их через **Yandex SpeechKit**, понимает намерение с помощью **Yandex GPT** и выполняет действия в **Bitrix24** через REST API.

## Возможности

| Команда (голос/текст) | Действие в Bitrix24 |
|---|---|
| «Создай задачу позвонить клиенту до пятницы» | Создание задачи со сроком |
| «Создай задачу подготовить отчёт, ответственный Иван» | Задача с ответственным |
| «Измени срок задачи 123 на 25 июня» | Обновление дедлайна |
| «Создай лид Иван Иванов телефон 79001234567» | Новый лид в CRM |
| «Создай сделку покупка оборудования сумма 50000» | Новая сделка в CRM |

## Требования

- PHP 8.1+
- Расширения: `curl`, `json`
- Composer
- Публичный HTTPS-сервер (для Telegram Webhook)

## Установка

### 1. Клонируйте репозиторий и установите зависимости

```bash
git clone <repo-url>
cd <repo-dir>
composer install
```

### 2. Настройте переменные окружения

```bash
cp .env.example .env
```

Заполните `.env`:

```env
TELEGRAM_BOT_TOKEN=...      # от @BotFather
YANDEX_API_KEY=...           # API-ключ Yandex Cloud
YANDEX_FOLDER_ID=...         # ID каталога Yandex Cloud
BITRIX24_WEBHOOK_URL=https://your-domain.bitrix24.ru/rest/1/xxx/
ALLOWED_USER_IDS=            # ID пользователей через запятую (пусто = все)
```

### 3. Получите ключи

#### Telegram Bot
1. Откройте [@BotFather](https://t.me/BotFather) → `/newbot`
2. Скопируйте токен в `TELEGRAM_BOT_TOKEN`

#### Yandex Cloud (SpeechKit + YandexGPT)
1. Зайдите на [console.cloud.yandex.ru](https://console.cloud.yandex.ru)
2. Создайте каталог (folder), скопируйте его ID → `YANDEX_FOLDER_ID`
3. IAM → Сервисные аккаунты → создайте аккаунт с ролями:
   - `ai.speechkit.tts` (SpeechKit)
   - `ai.languageModels.user` (YandexGPT)
4. Создайте API-ключ → `YANDEX_API_KEY`

> **Убедитесь**, что в Yandex Cloud для вашего каталога включены сервисы SpeechKit и YandexGPT.

#### Bitrix24 Webhook
1. В Bitrix24: **Разработчикам → Входящий вебхук** или **Приложения → Вебхуки**
2. Выдайте права:
   - `task` — задачи
   - `crm` — лиды и сделки
   - `user` — поиск пользователей
3. Скопируйте URL → `BITRIX24_WEBHOOK_URL`

### 4. Разместите на сервере

Файл `index.php` должен быть доступен по HTTPS-адресу.

Пример структуры на сервере:
```
/var/www/bot/
├── index.php
├── .env
├── vendor/
└── src/
```

Конфигурация nginx:
```nginx
location /bot/ {
    root /var/www;
    try_files $uri $uri/ /bot/index.php;
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/bot/index.php;
    include fastcgi_params;
}
```

### 5. Зарегистрируйте вебхук в Telegram

```bash
php setup_webhook.php set https://your-server.com/bot/index.php
```

Удалить вебхук:
```bash
php setup_webhook.php delete
```

## Структура проекта

```
├── index.php                   # Точка входа (webhook handler)
├── setup_webhook.php           # Утилита регистрации вебхука
├── .env.example                # Шаблон конфигурации
├── composer.json
└── src/
    ├── Config/
    │   └── Config.php          # Загрузка .env
    ├── Bot/
    │   └── TelegramBot.php     # Основная логика бота
    └── Services/
        ├── TelegramService.php  # Telegram Bot API
        ├── SpeechKitService.php # Yandex SpeechKit STT
        ├── YandexGptService.php # Yandex GPT (разбор команд)
        └── Bitrix24Service.php  # Bitrix24 REST API
```

## Логика работы

```
Голосовое сообщение
      ↓
TelegramService → скачивает OGG файл
      ↓
SpeechKitService → конвертирует в текст (ru-RU)
      ↓
YandexGptService → извлекает action + параметры (JSON)
      ↓
Bitrix24Service → вызывает нужный REST-метод
      ↓
TelegramService → отправляет результат пользователю
```

## Безопасность

- Установите `ALLOWED_USER_IDS` чтобы ограничить доступ к боту
- Храните `.env` вне webroot или защитите через nginx (`deny all` для `.env`)
- Используйте HTTPS для webhook
