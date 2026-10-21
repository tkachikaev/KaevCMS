# Load test results / Результаты нагрузочных тестов

## 2026-10-21 — baseline / исходный замер, KaevCMS 0.51.3

Server / сервер: test VPS, 1 vCPU, 1 GB RAM, Ubuntu 26.04, nginx 1.28, PHP 8.5 FPM (`pm = ondemand`, `max_children = 2`, OPcache on), MySQL 8.4. No Laravel config/route cache. Load sent over the internet from a workstation.

Pages / страницы: `/`, `/news`, `/statistics`, `/downloads`, `/about`, `/login`, `/register`.

| Users | Req/s | p50 ms | p95 ms | p99 ms | Server p50/p95 ms | Errors |
|---|---|---|---|---|---|---|
| 5 | 5.6 | 702 | 1034 | 2063 | 702/972 | 0 |
| 10 | 4.8 | 1617 | 3546 | 3715 | 1581/3412 | 0 |
| 20 | 6.6 | 2761 | 3443 | 4118 | 2725/3333 | 0 |

- CPU is 100 % busy from 5 users on (`id` 0, 2–6 processes waiting): the single core is the limit, not the number of PHP-FPM workers. / Процессор занят на 100 % уже с 5 посетителей: предел — одно ядро, а не число процессов PHP-FPM.
- One page costs 135–710 ms on the server itself without network. About two thirds of a request is the application bootstrap, not the page. / Одна страница стоит на самом сервере 135–710 мс; около 2/3 — запуск приложения, а не страница.
- Database work per home page view (measured locally): 34 queries during bootstrap (12 identical `cms_settings` table-exists checks, 11 single-setting reads, 8 module migration reads) plus 31 while rendering (6–10 more table-exists checks). / На главной 34 запроса при запуске и 31 при выводе страницы, из них около 20 — проверки существования таблиц.
- No errors at any stage: under load the site slows down instead of failing. / Ошибок нет: под нагрузкой сайт замедляется, а не падает.

## 2026-10-21 — fewer queries per request / меньше запросов на запрос (branch perf/load-testing)

Measured locally (SQLite) with the same pages; the server measurement follows after deployment. / Замер локальный, повторный замер на сервере — после установки.

| | Before / было | After / стало |
|---|---|---|
| Queries during bootstrap / при запуске | 34 | 6 |
| Home page view (bootstrap + page) / главная | 65 | 24 |
| News list / новости | 52 | 14 |
| Sign-in page / вход | 51 | 13 |

- `TableExistence` remembers tables that exist for the request (only "exists"; missing tables are checked again, migrations flush it). / Запоминается только «таблица есть».
- `CmsSettings` reads all settings with one query instead of one query per setting; model writes refresh it. / Все настройки одним запросом.
- Applied module migrations are read once for all modules. / Миграции модулей одним запросом.
- `tests/Feature/Performance/QueryBudgetTest.php` fails if public pages exceed their query budget again. / Тест следит за бюджетом запросов.