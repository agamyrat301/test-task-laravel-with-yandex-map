# Тестовое задание: отзывы с Яндекс.Карт

Стек: **Laravel 10** (API) + **Vue 3** (SPA) + **Tailwind CSS** + **Puppeteer** (парсер).

---

## Быстрый старт

**Требования:** PHP 8.1+, Node.js 18+, MySQL/PostgreSQL, Composer.

```bash
# 1. Зависимости
composer install
npm install          # включает puppeteer — он скачает Chromium автоматически

# 2. Переменные окружения
cp .env.example .env
# Заполните DB_DATABASE, DB_USERNAME, DB_PASSWORD
# Убедитесь что QUEUE_CONNECTION=database

# 3. Ключ и БД
php artisan key:generate
php artisan migrate --seed

# 4. Запуск (три процесса параллельно)
php artisan serve          # http://localhost:8000
npm run dev                # Vite dev-сервер (HMR)
php artisan queue:work     # Воркер очереди (парсит отзывы в фоне)
```

Логин seed-пользователя: `admin@example.com` / `password`

Пример URL для проверки: `https://yandex.com/maps/org/yandex/1124715036/`

---

## Экраны

| Маршрут | Описание |
|---------|----------|
| `/login` | Аутентификация (Sanctum SPA cookie-сессия) |
| `/` | Настройки — вставить URL карточки, просмотреть рейтинг и счётчики |
| `/reviews` | Список всех отзывов, пагинация 50/страницу |

---

## Как работает парсер

### Проблема: TLS fingerprinting

Яндекс SmartCaptcha блокирует HTTP-запросы от curl/Guzzle **на уровне TLS хендшейка** — `ClientHello` у этих библиотек принципиально отличается от браузерного, и Яндекс это детектирует **независимо** от заголовков (`User-Agent`, `Sec-Ch-Ua`, `Sec-Fetch-*`). Никакие варианты имитации заголовков не помогают.

### Решение: настоящий браузер (Puppeteer)

`scripts/yandex-scraper.js` открывает страницу организации через реальный **Chromium**, который проходит все проверки SmartCaptcha. Дополнительно маскируется флаг `navigator.webdriver`, который Яндекс тоже проверяет.

### Почему не Guzzle/cURL

| | Guzzle/cURL | **Puppeteer (выбранный подход)** |
|---|---|---|
| TLS fingerprint | Уникальный, детектируется | Настоящий Chrome |
| navigator.webdriver | Нет | Скрываем через `evaluateOnNewDocument` |
| Капча | Выдаётся на первом запросе | Не выдаётся |
| Зависимости | Только PHP | Node.js + Chromium (скачивается npm'ом) |
| Скорость (~600 отзывов) | ~2 мин (если не блокирует) | ~60 сек |

### Алгоритм (`scripts/yandex-scraper.js`)

**Шаг 1 — Загрузка страницы**

```
Puppeteer → https://yandex.ru/maps/org/{orgId}/reviews/
  (редирект на yandex.com — оба домена работают)
```

После `networkidle2` + 2 сек ожидания React-гидратации проверяем наличие капчи.

**Шаг 2 — Данные организации из OG-тегов**

Яндекс всегда включает серверный рендер метаданных:

```html
<meta property="og:description"
  content="Rated 4.9 based on 21096 ratings and 5768 reviews about ...">
```

Отсюда парсим: название (`h1`), рейтинг, количество оценок, количество отзывов с текстом, адрес.

**Шаг 3 — Сбор отзывов из DOM**

Яндекс рендерит отзывы в DOM с **Schema.org microdata**:

```html
<div class="business-review-view" itemprop="review">
  <span itemprop="name">Автор</span>
  <meta itemprop="ratingValue" content="5.0">
  <meta itemprop="datePublished" content="2025-11-06T12:19:21Z">
  <div itemprop="reviewBody">Текст отзыва...</div>
</div>
```

Начально загружается ~200 отзывов. Скроллим сайдбар (`.scroll__container`) вниз — Яндекс подгружает следующие пачки. Повторяем пока не наберём ~600 или не перестанут появляться новые.

```
Скролл → ждём 2.5 сек → извлекаем новые .business-review-view → повтор
```

Уникальный ключ отзыва: `{user_id из URL профиля}_{datePublished}` — гарантирует отсутствие дублей при повторной синхронизации.

**Результат** передаётся в PHP через `stdout` как JSON.

### Разделение `ratings_count` и `reviews_count`

OG-описание содержит оба числа явно: `21096 ratings and 5768 reviews`. Яндекс разделяет:
- **оценки** — все звёзды, включая без текста
- **отзывы** — только с текстом

Оба числа хранятся в таблице `organizations` и отображаются отдельно на экранах настроек и отзывов.

---

## Архитектура данных и пагинация

### Почему отзывы хранятся в БД, а не тянутся при каждом запросе

| | Парсинг по запросу | **Кэш в БД (текущий подход)** |
|---|---|---|
| Время ответа | ~60 сек (Puppeteer) | < 5 мс |
| Нагрузка на Яндекс | При каждом листании страниц | Только при синхронизации |
| Риск бана | Высокий (частые запросы) | Низкий |
| Актуальность | Всегда свежие | Данные на момент синхронизации |

Отзывы парсятся **один раз** при сохранении URL (или по кнопке «Обновить»), сохраняются в таблицу `reviews`, пагинация 50/страницу — это чистый `SELECT` из БД (< 5 мс).

### Жизненный цикл синхронизации

```
POST /api/organization          ← пользователь сохраняет URL
  └─ dispatch(SyncOrganizationJob)  ← мгновенный ответ (201)
       └─ org.sync_status = 'pending'

php artisan queue:work          ← фоновый воркер
  └─ org.sync_status = 'syncing'
  └─ node scripts/yandex-scraper.js {orgId}
       └─ Puppeteer открывает страницу
       └─ Парсим OG-теги → данные организации
       └─ Скроллим DOM → ~600 отзывов
  └─ Review::updateOrCreate() по yandex_review_id
  └─ org.sync_status = 'done'

GET /api/organization           ← Vue опрашивает каждые 3 сек
  └─ sync_status = 'done'       ← поллинг останавливается, карточка рендерится
```

### Повторная синхронизация

`updateOrCreate` по `yandex_review_id` — повторный запуск не создаёт дублей, только обновляет изменившиеся данные.

---

## Обработка ошибок

| Ситуация | Поведение |
|----------|-----------|
| Капча / SmartCaptcha | `sync_status = 'failed'`, сообщение в UI |
| Организация не найдена | `sync_status = 'failed'`, сообщение в UI |
| Нет подключения к Яндексу | `sync_status = 'failed'`, сообщение в UI |
| Разметка страницы изменилась | `sync_status = 'failed'`, сообщение в UI |
| Ошибка при загрузке пагинации отзывов | Ошибка с кнопкой «Повторить» в UI |
| 401 в любом API-запросе | Автоматический редирект на `/login` |

---

## API

| Метод | URL | Описание |
|-------|-----|----------|
| `POST` | `/api/login` | Вход (email + password) |
| `POST` | `/api/logout` | Выход |
| `GET`  | `/api/user` | Текущий пользователь |
| `GET`  | `/api/organization` | Карточка организации |
| `POST` | `/api/organization` | Сохранить URL + запустить синхронизацию |
| `POST` | `/api/organization/{id}/sync` | Повторная синхронизация |
| `GET`  | `/api/organization/{id}/reviews?page=N` | Отзывы, 50/страницу |

---

## Структура проекта

```
app/
├── Exceptions/
│   └── YandexParseException.php        — типизированные ошибки парсера
├── Http/Controllers/Api/
│   ├── AuthController.php              — login / logout / user
│   ├── OrganizationController.php      — сохранение URL, синхронизация
│   └── ReviewController.php            — список отзывов с пагинацией
├── Jobs/
│   └── SyncOrganizationJob.php         — фоновая задача (tries=3, timeout=600s)
├── Models/
│   ├── Organization.php
│   └── Review.php
├── Policies/
│   └── OrganizationPolicy.php          — user может видеть только свою организацию
└── Services/
    └── YandexMapsService.php           — оркестратор: вызов скрапера + сохранение в БД

scripts/
└── yandex-scraper.js                   — Puppeteer-скрипт (Node.js)

resources/js/
├── api/index.js          — axios-клиент + 401-interceptor
├── stores/auth.js        — Pinia store
├── router/index.js       — Vue Router + navigation guard
└── views/
    ├── LoginView.vue
    ├── SettingsView.vue  — форма URL, карточка организации, polling синк-статуса
    └── ReviewsView.vue   — список отзывов, пагинация, рейтинг

database/migrations/
├── ..._create_organizations_table.php  — yandex_url, rating, ratings_count, reviews_count, sync_status
└── ..._create_reviews_table.php        — yandex_review_id (unique), author, rating, text, reviewed_at
```
