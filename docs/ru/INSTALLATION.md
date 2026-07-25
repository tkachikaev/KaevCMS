# Установка

## Требования

- PHP 8.3 или новее.
- MySQL или MariaDB и отдельная пустая база.
- Расширения PHP: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON и session.
- HTTPS для рабочего сайта.
- Доступ на запись в `storage`, `bootstrap/cache` и публичный каталог `uploads`.

Публичные точки входа KaevCMS показывают понятную двуязычную ошибку на старой версии PHP до загрузки Laravel и Web Installer.

## VDS или хостинг с настраиваемым Document Root

Полная пошаговая установка Ubuntu 24.04 LTS, nginx, PHP 8.3-FPM, MySQL, HTTPS, прав, планировщика и queue worker описана в [инструкции для Ubuntu VDS](VDS_UBUNTU.md).


1. Распакуйте полный релиз вне публичного каталога.
2. Направьте Document Root домена на каталог `public/` внутри релиза.
3. Установите production-зависимости:

```bash
composer install --no-dev --optimize-autoloader
```

4. Откройте `/install/` по доступному адресу. По HTTP установщик покажет предупреждение, но позволит проверить базу и завершить установку. Для публичного сайта настройте HTTPS до открытия сайта пользователям.
5. Используйте новую пустую базу. Установщик не переиспользует существующую учётную запись владельца.
6. После успешной установки удалите публичную папку `/install`, затем перед открытием сайта пользователям проверьте итоговый отчёт безопасности.

## Локальная установка в Windows

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

`setup.ps1` создаёт локальный `.env`, ключ приложения, runtime-каталоги и устанавливает зависимости для разработки. Это не скрипт развёртывания на shared-hosting.

## Изменение адреса после установки

Основной адрес сайта хранится в `.env` в параметре `APP_URL`. Для HTTP также должны быть отключены принудительный HTTPS и защищённая cookie:

```env
APP_URL=http://192.168.50.111
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

Для HTTPS:

```env
APP_URL=https://cms.example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

После изменения выполните:

```bash
php artisan optimize:clear
```

На VDS дополнительно измените `server_name` в `/etc/nginx/sites-available/kaevcms`, затем выполните `sudo nginx -t` и `sudo systemctl reload nginx`. Подробные действия и решение ошибки `419 Page Expired` описаны в [инструкции для Ubuntu VDS](VDS_UBUNTU.md#как-изменить-адрес-сайта-после-установки).

## После установки

- Войдите под владельцем.
- Настройте подключения LoginServer и GameServer.
- Настройте SMTP и отправьте тестовое письмо.
- Проверьте диагностику планировщика и очередей.
- Оставьте `APP_DEBUG=false` на рабочем сайте.
- После распаковки удалите публично доступные архивы релиза.
