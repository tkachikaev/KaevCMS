# Установка на Ubuntu VDS

Эта инструкция описывает чистую установку KaevCMS на отдельный VDS с **Ubuntu Server 24.04 LTS**, nginx, PHP 8.3-FPM и MySQL.

Ubuntu 24.04 выбрана как базовая система KaevCMS, потому что её штатные репозитории содержат PHP 8.3 — ту же минимальную и фиксированную платформу, которая указана в `composer.json`. Ubuntu 26.04 LTS поставляется с PHP 8.5; её можно проверять отдельно, но до прохождения полного quality-gate она не считается основной проверенной платформой.

## Итоговая структура

```text
/var/www/kaevcms/
├── app/
├── bootstrap/
├── config/
├── public/          ← единственный Document Root nginx
├── storage/
├── vendor/
├── .env
└── artisan
```

Нельзя направлять nginx на `/var/www/kaevcms`. Публичным должен быть только `/var/www/kaevcms/public`.

## Перед началом

Подготовьте:

- чистый VDS с Ubuntu Server 24.04 LTS;
- домен с A-записью на IPv4-адрес VDS — необязательно для локальной или тестовой установки;
- SSH-доступ с `sudo`;
- полный ZIP-релиз KaevCMS;
- отдельные пароли для MySQL и владельца KaevCMS.

В примерах замените:

```text
example.com              на свой домен, если он уже есть
192.168.50.111           на фактический IP сервера
admin@example.com        на свой email для Let's Encrypt
KaevCmsDbPasswordHere    на длинный уникальный пароль MySQL
```

Для первичной проверки KaevCMS достаточно IP-адреса. Домен и сертификат можно настроить позже.

## 1. Обновите систему

```bash
sudo apt update
sudo apt full-upgrade -y
sudo reboot
```

После перезагрузки снова подключитесь по SSH.

## 2. Настройте firewall

Если SSH работает на стандартном порту 22:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status verbose
```

Если SSH перенесён на другой порт, сначала разрешите этот порт и только затем включайте UFW.

Не открывайте порт MySQL `3306` в интернет для локальной базы KaevCMS.

## 3. Установите nginx, MySQL, PHP и утилиты

```bash
sudo apt install -y \
    nginx \
    mysql-server \
    unzip \
    curl \
    ca-certificates \
    composer \
    php8.3-cli \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    php8.3-intl \
    php8.3-gd
```

Проверьте версии и службы:

```bash
php -v
composer --version
sudo systemctl status nginx --no-pager
sudo systemctl status php8.3-fpm --no-pager
sudo systemctl status mysql --no-pager
```

KaevCMS требует PHP 8.3 или новее. Для этой инструкции команда `php -v` должна показывать ветку `8.3.x`.

## 4. Создайте базу KaevCMS

Откройте MySQL от системного администратора:

```bash
sudo mysql
```

Выполните:

```sql
CREATE DATABASE kaevcms_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'kaevcms_user'@'localhost'
    IDENTIFIED BY 'KaevCmsDbPasswordHere';

GRANT ALL PRIVILEGES ON kaevcms_db.*
    TO 'kaevcms_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Проверьте отдельного пользователя:

```bash
mysql -u kaevcms_user -p -h 127.0.0.1 kaevcms_db
```

После успешного входа выполните `EXIT;`.

Для Web Installer используйте:

```text
Host:      127.0.0.1
Port:      3306
Database:  kaevcms_db
Username:  kaevcms_user
Password:  созданный пароль
```

## 5. Загрузите и распакуйте KaevCMS

Передайте полный ZIP-релиз на сервер через SFTP/SCP, например в `/tmp/KaevCMS-full.zip`.

```bash
sudo mkdir -p /var/www/kaevcms
sudo unzip /tmp/KaevCMS-full.zip -d /var/www/kaevcms
sudo chown -R "$USER":www-data /var/www/kaevcms
cd /var/www/kaevcms
```

Проверьте, что архив не создал лишний уровень каталога:

```bash
test -f /var/www/kaevcms/artisan && echo "KaevCMS root OK"
```

Если `artisan` находится, например, в `/var/www/kaevcms/KaevCMS-0.x.x/artisan`, перенесите содержимое этой вложенной папки на один уровень выше.

Удалите загруженный архив после распаковки:

```bash
sudo rm -f /tmp/KaevCMS-full.zip
```

## 6. Установите production-зависимости

Выполняйте Composer от обычного SSH-пользователя, которому принадлежит проект, а не от `root`:

```bash
cd /var/www/kaevcms
composer install --no-dev --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

Node.js и `npm` на VDS не требуются: готовые frontend-assets уже входят в полный релиз.

## 7. Назначьте права

Сначала задайте безопасные базовые права:

```bash
sudo find /var/www/kaevcms -type d -exec chmod 755 {} \;
sudo find /var/www/kaevcms -type f -exec chmod 644 {} \;
```

Разрешите nginx/PHP-FPM запись только в runtime-каталоги:

```bash
sudo chgrp -R www-data \
    /var/www/kaevcms/storage \
    /var/www/kaevcms/bootstrap/cache \
    /var/www/kaevcms/public/uploads

sudo chmod -R g+rwX \
    /var/www/kaevcms/storage \
    /var/www/kaevcms/bootstrap/cache \
    /var/www/kaevcms/public/uploads

sudo find /var/www/kaevcms/storage \
    /var/www/kaevcms/bootstrap/cache \
    /var/www/kaevcms/public/uploads \
    -type d -exec chmod 2775 {} \;
```

Проверьте, что пользователь PHP-FPM действительно может создавать вложенные каталоги кэша и файлы:

```bash
cd /var/www/kaevcms
sudo -u www-data php artisan kaevcms:runtime-directories --probe
```

Общий кэш приложения по умолчанию остаётся файловым, а счётчики ограничений входа и других запросов хранятся в основной базе CMS:

```env
CACHE_STORE=file
CACHE_LIMITER=database
```

Web Installer должен создать `.env` в корне проекта. Временно разрешите группе `www-data` запись в корень:

```bash
sudo chmod 775 /var/www/kaevcms
```

Не назначайте `0777` всему проекту.

## 8. Настройте лимиты PHP

Откройте конфигурацию PHP-FPM:

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Рекомендуемые стартовые значения:

```ini
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 70M
max_execution_time = 120
max_input_time = 120
```

Примените изменения:

```bash
sudo systemctl restart php8.3-fpm
```

На VDS обновления применяются через CLI от владельца файлов проекта, поэтому загружать большой update ZIP через PHP не требуется.

## 9. Настройте nginx

Создайте сайт:

```bash
sudo nano /etc/nginx/sites-available/kaevcms
```

Конфигурация:

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name example.com www.example.com;

    root /var/www/kaevcms/public;
    index index.php;

    charset utf-8;
    client_max_body_size 512m;

    access_log /var/log/nginx/kaevcms-access.log;
    error_log  /var/log/nginx/kaevcms-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /install/index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ^~ /uploads/ {
        try_files $uri =404;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Активируйте конфигурацию:

```bash
sudo ln -s /etc/nginx/sites-available/kaevcms /etc/nginx/sites-enabled/kaevcms
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Проверьте Document Root:

```bash
sudo nginx -T | grep -A4 -B4 '/var/www/kaevcms/public'
```

Если домена пока нет, укажите в конфигурации nginx фактический IP сервера:

```nginx
server_name 192.168.50.111;
```

## 10. Настройте HTTPS при наличии домена

Если домен и его A-запись уже указывают на VDS, установите Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

Получите сертификат:

```bash
sudo certbot --nginx \
    -d example.com \
    -d www.example.com \
    -m admin@example.com \
    --agree-tos \
    --redirect
```

Проверьте автоматическое продление:

```bash
sudo systemctl status certbot.timer --no-pager
sudo certbot renew --dry-run
```

Если поддомен `www` не используется и для него нет DNS-записи, уберите второй `-d` из команды и `www.example.com` из `server_name`.

Если домена или A-записи пока нет, пропустите Certbot. Web Installer разрешает установку по HTTP и только покажет предупреждение. Это удобно для тестового VDS, локального IP или закрытой сети. Перед открытием публичного сайта пользователям настройте домен и HTTPS.

## 11. Пройдите Web Installer

С доменом и сертификатом откройте:

```text
https://example.com/install/
```

Без домена откройте IP-адрес сервера:

```text
http://192.168.50.111/install/
```

Замените пример на фактический IP. На шаге базы укажите созданные ранее реквизиты и используйте пустую базу `kaevcms`. Предупреждение об HTTP не блокирует проверку базы или завершение установки.

После завершения установки убедитесь, что Web Installer автоматически удалил публичную папку `/install`, затем войдите в административную панель. Если итоговая страница сообщает об ошибке удаления, удалите каталог вручную.

## 12. Закройте временные права после установки

После появления `.env` выполните:

```bash
sudo chmod 755 /var/www/kaevcms
sudo chown "$USER":www-data /var/www/kaevcms/.env
sudo chmod 640 /var/www/kaevcms/.env

sudo chown "$USER":www-data /var/www/kaevcms/storage/app/installed.lock
sudo chmod 640 /var/www/kaevcms/storage/app/installed.lock
```

Проверьте, что закрытые файлы не отдаются через nginx:

```bash
curl -I https://example.com/.env
curl -I https://example.com/vendor/autoload.php
```

Ожидается `404` или `403`, но не содержимое файла.

## 13. Настройте планировщик

Создайте системный cron-файл:

```bash
sudo nano /etc/cron.d/kaevcms
```

Содержимое:

```cron
* * * * * www-data cd /var/www/kaevcms && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Примените права и проверьте:

```bash
sudo chmod 644 /etc/cron.d/kaevcms
sudo -u www-data /usr/bin/php8.3 /var/www/kaevcms/artisan schedule:run
```

Через несколько минут проверьте heartbeat планировщика в системной диагностике KaevCMS.

## 14. Queue worker для асинхронной почты

Этот раздел нужен только после включения асинхронной очереди в KaevCMS.

Создайте systemd-службу:

```bash
sudo nano /etc/systemd/system/kaevcms-queue.service
```

Содержимое:

```ini
[Unit]
Description=KaevCMS queue worker
After=network.target mysql.service php8.3-fpm.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/kaevcms
ExecStart=/usr/bin/php8.3 /var/www/kaevcms/artisan queue:work database --queue=mail-probe,mail,default --sleep=1 --tries=3 --timeout=120
Restart=always
RestartSec=5
TimeoutStopSec=360
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

Запустите службу:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now kaevcms-queue
sudo systemctl status kaevcms-queue --no-pager
```

Журнал:

```bash
sudo journalctl -u kaevcms-queue -n 100 --no-pager
```

После обновления KaevCMS перезапускайте worker:

```bash
sudo -u www-data /usr/bin/php8.3 /var/www/kaevcms/artisan queue:restart
sudo systemctl restart kaevcms-queue
```

## 15. Установите агент обновлений VDS

Этот шаг нужен только для запуска обновлений из административной панели. Агент не устанавливается автоматически и не требуется для ручного обновления через SSH.

Выполните одну команду:

```bash
cd /var/www/kaevcms
bash deployment/vds/install-update-agent.sh
```

Больше выбирать ничего не требуется. Скрипт сам:

- запросит `sudo`, если текущей учётной записи не хватает прав;
- определит владельца проекта;
- определит пользователя и группу PHP-FPM;
- настроит systemd-службу от владельца проекта;
- восстановит безопасные права программных и runtime-файлов;
- проверит, что PHP-FPM читает приложение и может записывать пакеты обновлений.

Если проект принадлежит `root`, агент будет работать от `root` и покажет предупреждение о полном доступе устанавливаемого пакета. Если проект принадлежит обычному пользователю, служба будет работать от этого пользователя. Создавать отдельную учётную запись для агента не нужно.

Только для нестандартной конфигурации значения можно указать вручную:

```bash
bash deployment/vds/install-update-agent.sh \
    --project-user kaevcms \
    --web-user www-data \
    --web-group www-data
```

Переменные `KAEVCMS_DEPLOY_USER`, `KAEVCMS_WEB_USER`, `KAEVCMS_WEB_GROUP` и `KAEVCMS_PHP_BINARY` также поддерживаются.

Скрипт записывает числовые UID/GID, оставляет исходный код доступным PHP-FPM только на чтение, создаёт групповые runtime-каталоги с правами записи и сохраняет резервные копии базы доступными только владельцу. Агент не открывает сетевой порт.

Проверьте состояние:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update-agent:status
```

Ожидаемый результат: `State: ready` и `Ready: yes`. Повторный запуск той же команды безопасно обновляет unit-файлы, регистрацию и права. После этого релиза ожидается версия контракта агента 3.

Для удаления агента:

```bash
cd /var/www/kaevcms
sudo bash deployment/vds/remove-update-agent.sh
```

Удаление блокируется, пока в каталоге агента есть ожидающий запрос обновления.

## 16. Финальная проверка

```bash
cd /var/www/kaevcms

sudo -u www-data php artisan about --only=environment
sudo -u www-data php artisan kaevcms:release-version --no-ansi
sudo -u www-data php artisan kaevcms:maintenance-status --no-ansi
sudo -u www-data php artisan kaevcms:encryption-health --no-ansi

sudo nginx -t
sudo systemctl is-active nginx php8.3-fpm mysql
sudo systemctl is-enabled nginx php8.3-fpm mysql
```

Проверьте в браузере:

- главную страницу;
- `/admin`;
- вход владельца;
- загрузку изображения новости;
- SMTP-тест;
- подключение LoginServer/GameServer;
- системную диагностику;
- загрузку Web Update ZIP без применения.

## Обновление KaevCMS на VDS

### Обновление через административную панель

Для первого перехода с версии ниже `0.47.0` используйте ручной CLI Updater: старые версии ещё не содержат агент. После установки `0.47.0` установите агент один раз, и следующие совместимые обновления можно будет запускать из панели.

Для этого способа должен быть установлен агент из шага 15. Если агент отсутствует, KaevCMS покажет сообщение **«Агент обновлений VDS не установлен»** и готовую команду установки. Загружать и проверять ZIP можно без агента, но запуск установки будет недоступен, пока агент не готов.

1. Откройте **Настройки → Система → Обновления системы**.
2. Загрузите актуальный кумулятивный Update ZIP.
3. Проверьте целевую версию, manifest, SHA256 и preflight.
4. Подтвердите, что доверяете источнику архива. Владелец сайта отвечает за выбранный пакет: обновление может заменять программные файлы KaevCMS.
5. Введите текущий пароль владельца и нажмите **«Отправить агенту VDS»**.

Сайт создаёт локальный запрос в защищённом runtime-каталоге. `systemd.path` запускает одноразовый агент от владельца файлов проекта. Агент повторно проверяет архив и права, создаёт резервные копии файлов и базы, включает технический режим, применяет файлы и миграции, очищает кеши и перезапускает очередь. При ошибке используется существующий автоматический откат.

Пока запрос ожидает агента, страница обновляется автоматически. После запуска агента технический режим и журнал показывают подготовку, замену файлов, миграции, завершение или ошибку. Не удаляйте ZIP и не запускайте второе обновление, пока текущий запрос не завершён.

Диагностика агента:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update-agent:status --json
sudo journalctl -u 'kaevcms-update-agent-*.service' -n 100 --no-pager
```

Если агент зарегистрирован неверно или unit-файлы были удалены, повторно выполните:

```bash
cd /var/www/kaevcms
bash deployment/vds/install-update-agent.sh
```

> После изменения владельца проекта или группы PHP-FPM повторно выполните `bash deployment/vds/install-update-agent.sh`. Установщик восстанавливает как runtime-каталоги Web Update, так и чтение файлов приложения группой веб-сервера, не предоставляя PHP-FPM права записи на исходный код.

### Резервное обновление через SSH

Ручной CLI Updater остаётся доступным без агента. Запускайте его от SSH-пользователя, которому принадлежит `/var/www/kaevcms`, а не от `www-data`:

```bash
sudo systemctl stop kaevcms-queue
cd /var/www/kaevcms
php artisan kaevcms:update /tmp/KaevCMS-update.zip
sudo systemctl start kaevcms-queue
rm -f /tmp/KaevCMS-update.zip
```

Команда показывает manifest, исходную и целевую версии, preflight-проверки и запрашивает подтверждение. Для автоматизированного запуска после собственной проверки пакета можно добавить `--yes`:

```bash
php artisan kaevcms:update /tmp/KaevCMS-update.zip --yes
```

Устанавливать промежуточные версии по очереди не нужно: пакет должен поддерживать диапазон от текущей версии до целевой.

Если релиз меняет Composer-зависимости, используйте полный релиз и `composer install` по поставленному `composer.lock`. Никогда не запускайте `composer update` на production-сервере.

## Как изменить адрес сайта после установки

Адрес KaevCMS хранится в двух местах:

```text
/var/www/kaevcms/.env
/etc/nginx/sites-available/kaevcms
```

### Переход на другой IP или работа по HTTP

Откройте `.env`:

```bash
sudo nano /var/www/kaevcms/.env
```

Укажите фактический адрес и отключите принудительный HTTPS:

```env
APP_URL=http://192.168.50.112
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

`APP_FORCE_HTTPS=true` заставляет KaevCMS формировать HTTPS-ссылки. `SESSION_SECURE_COOKIE=true` запрещает браузеру отправлять сессионную cookie по HTTP и может приводить к ошибке `419 Page Expired` при входе.

Затем измените `server_name` в nginx:

```bash
sudo nano /etc/nginx/sites-available/kaevcms
```

```nginx
server_name 192.168.50.112;
```

Примените изменения:

```bash
cd /var/www/kaevcms
php artisan optimize:clear
sudo nginx -t
sudo systemctl reload nginx
```

После смены IP, домена или протокола удалите старые cookies сайта либо откройте его в приватном окне браузера.

### Переход на домен и HTTPS

После настройки DNS и сертификата измените `.env`:

```env
APP_URL=https://cms.example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

И nginx:

```nginx
server_name cms.example.com;
```

После этого снова выполните `php artisan optimize:clear`, проверку nginx и его перезагрузку.

## Диагностика

### `502 Bad Gateway`

```bash
sudo systemctl status php8.3-fpm --no-pager
ls -la /run/php/
sudo tail -n 100 /var/log/nginx/kaevcms-error.log
```

Проверьте, что nginx использует существующий сокет:

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

### `403 Forbidden`

```bash
namei -l /var/www/kaevcms/public/index.php
sudo -u www-data test -r /var/www/kaevcms/public/index.php && echo readable
```

Каждый родительский каталог пути должен иметь право прохода `x` для nginx.

### Ошибка записи

```bash
sudo -u www-data test -w /var/www/kaevcms/storage && echo storage-writable
sudo -u www-data test -w /var/www/kaevcms/bootstrap/cache && echo cache-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads && echo uploads-writable
```

Не исправляйте проблему командой `chmod -R 777 /var/www/kaevcms`.


Если Laravel пишет `storage/framework/cache/data/...: No such file or directory`, восстановите runtime-каталоги и проверьте запись от пользователя PHP-FPM:

```bash
cd /var/www/kaevcms
sudo mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
sudo chown -R "$USER":www-data storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo -u www-data php artisan kaevcms:runtime-directories --probe
php artisan optimize:clear
sudo -u www-data php artisan kaevcms:runtime-directories --probe
```

`optimize:clear` не заменяет правильные права и владельца. Updater проверяет runtime-каталоги до очистки кэша и повторно создаёт и проверяет их после неё.

### `419 Page Expired` после входа по HTTP

Проверьте `/var/www/kaevcms/.env`:

```env
APP_URL=http://192.168.50.111
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
SESSION_DOMAIN=
```

Затем:

```bash
cd /var/www/kaevcms
php artisan optimize:clear
```

Удалите cookies для старого адреса или откройте сайт в приватном окне. Адрес в `APP_URL` должен совпадать с адресом, который открыт в браузере.

### Каталоги загрузок отмечены как недоступные для записи

KaevCMS использует:

```text
public/uploads/news
public/uploads/pages
public/uploads/settings
public/uploads/account-avatars
public/uploads/game-assets
```

В актуальном релизе установщик и системная диагностика создают отсутствующие каталоги автоматически. Для ручного восстановления прав выполните:

```bash
sudo mkdir -p \
    /var/www/kaevcms/public/uploads/news \
    /var/www/kaevcms/public/uploads/pages \
    /var/www/kaevcms/public/uploads/settings \
    /var/www/kaevcms/public/uploads/account-avatars \
    /var/www/kaevcms/public/uploads/game-assets

sudo chgrp -R www-data /var/www/kaevcms/public/uploads
sudo chmod -R g+rwX /var/www/kaevcms/public/uploads
sudo find /var/www/kaevcms/public/uploads -type d -exec chmod 2775 {} \;
```

Проверка:

```bash
sudo -u www-data test -w /var/www/kaevcms/public/uploads/news && echo news-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads/pages && echo pages-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads/settings && echo settings-writable
```

Не используйте `chmod -R 777`.

### Новость создана, но не отображается на сайте

Проверьте в админ-панели, что новость не является черновиком, переключатель публикации включён, а дата публикации не установлена в будущем. Изображение новости не влияет на сам факт публикации.

### Логи

```bash
sudo tail -n 100 /var/log/nginx/kaevcms-error.log
sudo tail -n 100 /var/www/kaevcms/storage/logs/laravel.log
sudo journalctl -u php8.3-fpm -n 100 --no-pager
sudo journalctl -u kaevcms-queue -n 100 --no-pager
```

## Официальные справочные материалы

- [Примечания к Ubuntu 24.04 LTS](https://documentation.ubuntu.com/release-notes/24.04/) — базовая PHP 8.3.
- [Сводка Ubuntu 26.04 LTS](https://documentation.ubuntu.com/release-notes/26.04/summary-for-lts-users/) — базовая PHP 8.5.
- [Установка nginx в Ubuntu Server](https://documentation.ubuntu.com/server/how-to/web-services/install-nginx/).
- [Установка PHP в Ubuntu Server](https://ubuntu.com/server/docs/how-to/web-services/install-php/).
- [Установка и настройка MySQL](https://ubuntu.com/server/docs/install-and-configure-a-mysql-server).
- [Документация Ubuntu по firewall](https://documentation.ubuntu.com/server/how-to/security/firewalls/).
- [Получение TLS-сертификатов в Ubuntu](https://ubuntu.com/server/docs/how-to/security/obtain-tls-certificates/).
- [Развёртывание Laravel](https://laravel.com/docs/11.x/deployment) — наружу должен смотреть только `public/index.php`.
- [Команда Composer install](https://getcomposer.org/doc/03-cli.md#install-i).
- [Инструкция Certbot для nginx](https://certbot.eff.org/instructions?ws=nginx&os=snap).

