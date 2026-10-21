# Load tests / Нагрузочные тесты

`http-load.php` sends parallel requests to the public pages of a KaevCMS site and reports requests per second, response times (median, 95th and 99th percentile), server time to first byte and errors per stage. It needs only PHP CLI with the curl extension.

`http-load.php` отправляет параллельные запросы к публичным страницам сайта KaevCMS и по каждой ступени показывает запросы в секунду, время ответа (медиана, 95-й и 99-й процентиль), время работы сервера до первого байта и ошибки. Нужен только PHP CLI с расширением curl.

```bash
php tests/load/http-load.php --base=https://test.example.com --stages=5:30,10:30,20:30
```

- `--stages=USERS:SECONDS,...` — virtual users and duration of each stage / виртуальные посетители и длительность каждой ступени.
- `--paths=/,/news` — pages to request; by default the public pages are discovered from the site / страницы; по умолчанию публичные страницы находятся автоматически.
- `--insecure` — skip TLS verification for a self-signed test server / не проверять сертификат тестового сервера.

Rules / Правила:

- Only a test server. Never a production site with players: they would feel the load. / Только тестовый сервер, не живой сайт с игроками.
- Payment and sign-in forms are not part of the default pages, so a test never creates orders or accounts. / Страницы оплаты и формы входа не отправляются, тест не создаёт заказов и аккаунтов.
- Every virtual user keeps its own cookies, like a returning visitor. A stage stops by itself when more than half of the requests fail. / У каждого посетителя свои cookie; ступень останавливается сама, если больше половины запросов завершились ошибкой.
- Watch the server while the test runs, e.g. `vmstat 5` over SSH: CPU (`us`/`sy`/`id`), processes waiting for CPU (`r`) and free memory. / Во время теста смотрите на сервер, например `vmstat 5`.

Results are recorded in [RESULTS.md](RESULTS.md). / Результаты записываются в [RESULTS.md](RESULTS.md).
