# Load tests / Нагрузочные тесты

`http-load.php` sends parallel requests to the public pages of a KaevCMS site and reports requests per second, response times (median, 95th and 99th percentile), server time to first byte and errors per stage. It needs only PHP CLI with the curl extension.

`http-load.php` отправляет параллельные запросы к публичным страницам сайта KaevCMS и по каждой ступени показывает запросы в секунду, время ответа (медиана, 95-й и 99-й процентиль), время работы сервера до первого байта и ошибки. Нужен только PHP CLI с расширением curl.

```bash
php tests/load/http-load.php --base=https://test.example.com --stages=5:30,10:30,20:30
```

- `--stages=USERS:SECONDS,...` — virtual users and duration of each stage / виртуальные посетители и длительность каждой ступени.
- `--paths=/,/news` — pages to request; by default the public pages are discovered from the site / страницы; по умолчанию публичные страницы находятся автоматически.
- `--insecure` — skip TLS verification for a self-signed test server / не проверять сертификат тестового сервера.
- `--players=players.json` — a JSON list of `{"login": ..., "password": ...}` **test** players. Every virtual user signs in through the login form first and stays signed in, so `--paths` can be cabinet pages such as `/account/characters`; being sent back to the sign-in page counts as an error. / Список **тестовых** игроков: каждый виртуальный посетитель сначала входит через форму входа, и можно нагружать страницы кабинета.

Player cabinet without a game server / Кабинет без игрового сервера:

```bash
# 1. An empty copy of the L2JMobius schema, e.g. kaevcms_load_game (mysqldump --no-data from your game database).
# 2. Generated accounts, characters, clans, castles and heroes:
php tests/load/fake-game-world.php --config=game-db.json --accounts=1000
```

`game-db.json` holds `game_db_host`, `game_db_port`, `game_db_database`, `game_db_username` and `game_db_password`. The generator refuses database names without `load` or `test`, so a live game database is never touched. Point the test site's LoginServer and GameServer at that database and link test players to the `lt_00001`… accounts. / Генератор отказывается работать с базой, в имени которой нет `load` или `test`. Подключите к этой базе логин- и игровой сервер тестового сайта и привяжите тестовых игроков к аккаунтам `lt_00001`….

Rules / Правила:

- Only a test server. Never a production site with players: they would feel the load. / Только тестовый сервер, не живой сайт с игроками.
- Payment forms are never sent, so a test never creates orders. The sign-in form is sent only with `--players`, and only test players belong in that file. / Формы оплаты не отправляются, заказы не создаются. Форма входа отправляется только с `--players`, и в этом файле только тестовые игроки.
- Every virtual user keeps its own cookies, like a returning visitor. A stage stops by itself when more than half of the requests fail. / У каждого посетителя свои cookie; ступень останавливается сама, если больше половины запросов завершились ошибкой.
- Watch the server while the test runs, e.g. `vmstat 5` over SSH: CPU (`us`/`sy`/`id`), processes waiting for CPU (`r`) and free memory. / Во время теста смотрите на сервер, например `vmstat 5`.

Results are recorded in [RESULTS.md](RESULTS.md). / Результаты записываются в [RESULTS.md](RESULTS.md).
