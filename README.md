# Тестовое задание: отзывы с Яндекс.Карт

Стек: **Laravel 10** (API) + **Vue 3** (SPA) + **Tailwind CSS**.

---

## Быстрый старт

```bash
# 1. Зависимости
composer install
npm install

# 2. Переменные окружения
cp .env.example .env
# Заполните DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 3. Ключ и БД
php artisan key:generate
php artisan migrate --seed

# 4. Запуск (три процесса параллельно)
php artisan serve          # http://localhost:8000
npm run dev                # Vite dev-сервер (HMR)
php artisan queue:work     # Воркер очереди (парсит отзывы в фоне)
```

Логин seed-пользователя: `admin@example.com` / `password`

---

## Экраны

| Маршрут | Описание |
|---------|----------|
| `/login` | Аутентификация (Sanctum SPA cookie-сессия) |
| `/` | Настройки — вставить URL карточки, просмотреть рейтинг и счётчики |
| `/reviews` | Список всех отзывов, пагинация 50/страницу |

---

## Архитектура данных и пагинация

### Почему отзывы хранятся в БД, а не тянутся с Яндекса при каждом запросе

| | Парсинг по запросу | **Кэш в БД (текущий подход)** |
|---|---|---|
| Время ответа | 1–6 мин | < 5 мс |
| Нагрузка на Яндекс | При каждом листании | Только при синхронизации |
| Риск бана | Высокий (частые запросы) | Низкий |
| Актуальность | Всегда свежие | Данные на момент синхронизации |

Отзывы парсятся **один раз** при сохранении URL (или по кнопке «Обновить»), сохраняются в таблицу `reviews`, пагинация 50/страницу — это чистый `SELECT` из БД.

### Жизненный цикл синхронизации

```
POST /api/organization          ← пользователь сохраняет URL
  └─ dispatch(SyncOrganizationJob)  ← мгновенный ответ
       └─ org.sync_status = 'pending'

php artisan queue:work          ← фоновый воркер
  └─ initSession()              ← GET страницы, получаем cookies + CSRF
  └─ fetchAllReviews()          ← итерация /fetchReviews по 60 штук
  └─ Review::updateOrCreate()   ← upsert по yandex_review_id
  └─ org.sync_status = 'done'

GET /api/organization           ← Vue опрашивает каждые 3 сек
  └─ sync_status = 'done'       ← поллинг останавливается, карточка рендерится
```

### Повторная синхронизация

`updateOrCreate` по `yandex_review_id` — повторный запуск не создаёт дублей, только обновляет изменившиеся данные.

---

## Как работает парсер

### Почему не headless-браузер

Headless (Puppeteer / Playwright) — самый надёжный способ, но:
- требует Node.js-процесс рядом с PHP-приложением;
- в 5–10× медленнее при сборе 600 отзывов;
- избыточен, пока Яндекс отдаёт нужные данные через HTTP.

Выбранный подход — **имитация реального браузера через GuzzleHttp** — достаточен, потому что Яндекс включает server-side rendered данные в HTML страницы (для SEO-краулеров). Защита от ботов срабатывает на подозрительные UA и отсутствие cookie, а не на факт HTTP-запроса.

### Алгоритм (`app/Services/YandexMapsService.php`)

**Шаг 1 — Инициализация сессии**

```
GET https://yandex.ru/maps/org/{orgId}/reviews/
```

- Заголовки имитируют Chrome 120 (`User-Agent`, `Sec-Ch-Ua`, `Sec-Fetch-*`)
- `GuzzleHttp\Cookie\CookieJar` сохраняет все cookie Яндекса:  
  `yandexuid`, `i`, `Session_id`, `yandex_csyr` и др.
- Из HTML-ответа извлекаем:
  - **CSRF-токен** — паттерн `"csrfToken":"<value>"` внутри embedded JSON
  - **Данные организации** — `<script id="store-prefetch" type="application/json">`  
    содержит рейтинг, число оценок (`votes`), число отзывов с текстом (`reviews`), адрес
  - Fallback: OpenGraph-теги и Schema.org microdata

**Шаг 2 — Итерация по API отзывов**

```
GET https://yandex.ru/maps/api/business/fetchReviews
    ?businessId={id}&from={offset}&limit=60&lang=ru_RU&csrfToken={token}
```

Этот XHR-запрос браузер делает при прокрутке страницы. Мы воспроизводим его с теми же cookie из шага 1 и CSRF-токеном. Одна итерация = 60 отзывов, задержка 600 мс между запросами, лимит 700 записей.

На каждый отзыв сохраняем: `author`, `rating`, `text`, `reviewed_at`, `yandex_review_id`.  
При повторной синхронизации используется `updateOrCreate` по `yandex_review_id` — дубликатов нет.

### Разделение `ratings_count` и `reviews_count`

Яндекс разделяет:
- **оценки** (`votes` в блоке `rating`) — все звёзды, включая без текста
- **отзывы** (`reviews` в блоке `rating`) — только с текстом

Оба числа хранятся в таблице `organizations` и отображаются отдельно.

### Обработка rate limit и ошибок

- HTTP 429 → sleep(5s) → один повтор
- Непустой ответ не-JSON → предупреждение в `laravel.log`, пропуск батча
- Любой `GuzzleException` → предупреждение в лог, пропуск батча

### Если Яндекс начнёт блокировать

Варианты эскалации по сложности:
1. **Ротация User-Agent** — добавить пул браузерных UA в конфиг
2. **Proxy** — подключить через `GuzzleHttp` опцию `proxy`
3. **Headless-браузер** — заменить `YandexMapsService::initSession()` на  
   вызов Node.js-скрипта через `Browsershot` или `symfony/panther`;  
   остальной код (нормализация, сохранение) остаётся без изменений

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
├── Http/Controllers/Api/
│   ├── AuthController.php          — login / logout / user
│   ├── OrganizationController.php  — сохранение URL, синхронизация
│   └── ReviewController.php        — список отзывов с пагинацией
├── Models/
│   ├── Organization.php
│   └── Review.php
└── Services/
    └── YandexMapsService.php       — вся логика парсинга

resources/js/
├── api/index.js          — axios-клиент
├── stores/auth.js        — Pinia store
├── router/index.js       — Vue Router + guard
└── views/
    ├── LoginView.vue
    ├── SettingsView.vue
    └── ReviewsView.vue
```
