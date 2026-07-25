# Ubuntu VDS installation

This guide covers a clean KaevCMS deployment on a dedicated VDS running **Ubuntu Server 24.04 LTS**, nginx, PHP 8.3-FPM, and MySQL.

Ubuntu 24.04 is the KaevCMS baseline because its standard repositories provide PHP 8.3, matching the minimum and pinned Composer platform. Ubuntu 26.04 LTS ships PHP 8.5; it may be tested separately, but it is not the primary validated platform until the complete quality gate passes on it.

## Resulting layout

```text
/var/www/kaevcms/
├── app/
├── bootstrap/
├── config/
├── public/          ← the only nginx Document Root
├── storage/
├── vendor/
├── .env
└── artisan
```

Never point nginx at `/var/www/kaevcms`. Only `/var/www/kaevcms/public` may be public.

## Before you begin

Prepare:

- a clean Ubuntu Server 24.04 LTS VDS;
- a domain A record pointing to the VDS IPv4 address — optional for a local or test installation;
- SSH access with `sudo`;
- the complete KaevCMS release ZIP;
- separate passwords for MySQL and the KaevCMS owner.

Replace these examples:

```text
example.com              with your domain, when available
192.168.50.111           with the actual server IP
admin@example.com        with your Let's Encrypt email
KaevCmsDbPasswordHere    with a long unique MySQL password
```

An IP address is sufficient for the first KaevCMS test. A domain and certificate may be configured later.

## 1. Update the operating system

```bash
sudo apt update
sudo apt full-upgrade -y
sudo reboot
```

Reconnect through SSH after the reboot.

## 2. Configure the firewall

When SSH uses the standard port 22:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status verbose
```

When SSH uses a custom port, allow that port before enabling UFW.

Do not expose the local KaevCMS MySQL port `3306` to the Internet.

## 3. Install nginx, MySQL, PHP, and utilities

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

Verify versions and services:

```bash
php -v
composer --version
sudo systemctl status nginx --no-pager
sudo systemctl status php8.3-fpm --no-pager
sudo systemctl status mysql --no-pager
```

KaevCMS requires PHP 8.3 or newer. For this baseline, `php -v` should report an `8.3.x` version.

## 4. Create the KaevCMS database

Open MySQL as the system administrator:

```bash
sudo mysql
```

Run:

```sql
CREATE DATABASE kaevcms
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'kaevcms'@'localhost'
    IDENTIFIED BY 'KaevCmsDbPasswordHere';

GRANT ALL PRIVILEGES ON kaevcms.*
    TO 'kaevcms'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Verify the dedicated account:

```bash
mysql -u kaevcms -p -h 127.0.0.1 kaevcms
```

Exit with `EXIT;` after a successful connection.

Use these Web Installer values:

```text
Host:      127.0.0.1
Port:      3306
Database:  kaevcms
Username:  kaevcms
Password:  the password created above
```

## 5. Upload and extract KaevCMS

Transfer the complete release ZIP through SFTP/SCP, for example to `/tmp/KaevCMS-full.zip`.

```bash
sudo mkdir -p /var/www/kaevcms
sudo unzip /tmp/KaevCMS-full.zip -d /var/www/kaevcms
sudo chown -R "$USER":www-data /var/www/kaevcms
cd /var/www/kaevcms
```

Verify that the archive did not create an extra directory level:

```bash
test -f /var/www/kaevcms/artisan && echo "KaevCMS root OK"
```

If `artisan` is under a nested path such as `/var/www/kaevcms/KaevCMS-0.x.x/artisan`, move that directory's contents one level up.

Delete the uploaded archive:

```bash
sudo rm -f /tmp/KaevCMS-full.zip
```

## 6. Install production dependencies

Run Composer as the normal SSH user that owns the project, not as `root`:

```bash
cd /var/www/kaevcms
composer install --no-dev --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

Node.js and `npm` are not required on the VDS because built frontend assets are included in the complete release.

## 7. Apply permissions

Start with safe base permissions:

```bash
sudo find /var/www/kaevcms -type d -exec chmod 755 {} \;
sudo find /var/www/kaevcms -type f -exec chmod 644 {} \;
```

Allow nginx/PHP-FPM to write only to runtime directories:

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

The Web Installer must create `.env` in the project root. Temporarily allow group write access:

```bash
sudo chmod 775 /var/www/kaevcms
```

Never recursively assign `0777` to the project.

## 8. Configure PHP limits

Open the PHP-FPM configuration:

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Recommended starting values:

```ini
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 70M
max_execution_time = 120
max_input_time = 120
```

Apply the changes:

```bash
sudo systemctl restart php8.3-fpm
```

VDS updates are applied through the CLI as the project-file owner, so a large update ZIP does not need to pass through PHP uploads.

## 9. Configure nginx

Create the site configuration:

```bash
sudo nano /etc/nginx/sites-available/kaevcms
```

Use:

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

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/kaevcms /etc/nginx/sites-enabled/kaevcms
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Verify the Document Root:

```bash
sudo nginx -T | grep -A4 -B4 '/var/www/kaevcms/public'
```

When no domain is available, use the actual server IP in nginx:

```nginx
server_name 192.168.50.111;
```

## 10. Configure HTTPS when a domain is available

If the domain and its A record already point to the VDS, install Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
```

Request the certificate:

```bash
sudo certbot --nginx \
    -d example.com \
    -d www.example.com \
    -m admin@example.com \
    --agree-tos \
    --redirect
```

Verify renewal:

```bash
sudo systemctl status certbot.timer --no-pager
sudo certbot renew --dry-run
```

If `www` is not used and has no DNS record, remove the second `-d` and remove `www.example.com` from `server_name`.

If no domain or A record is available yet, skip Certbot. Web Installer allows HTTP installation and only displays a warning. This is convenient for a test VDS, local IP, or private network. Configure a domain and HTTPS before opening a public website to users.

## 11. Run the Web Installer

With a domain and certificate, open:

```text
https://example.com/install/
```

Without a domain, open the server IP address:

```text
http://192.168.50.111/install/
```

Replace the example with the actual IP. Enter the database credentials created above and use the empty `kaevcms` database. The HTTP warning does not block the database check or final installation.

After installation, sign in to the administrator panel and review the final security report.

## 12. Remove temporary write access

After `.env` has been created:

```bash
sudo chmod 755 /var/www/kaevcms
sudo chown "$USER":www-data /var/www/kaevcms/.env
sudo chmod 640 /var/www/kaevcms/.env

sudo chown "$USER":www-data /var/www/kaevcms/storage/app/installed.lock
sudo chmod 640 /var/www/kaevcms/storage/app/installed.lock
```

Verify that private files are not served:

```bash
curl -I https://example.com/.env
curl -I https://example.com/vendor/autoload.php
```

Expect `404` or `403`, never file contents.

## 13. Configure the scheduler

Create a system cron file:

```bash
sudo nano /etc/cron.d/kaevcms
```

Contents:

```cron
* * * * * www-data cd /var/www/kaevcms && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Apply permissions and test it:

```bash
sudo chmod 644 /etc/cron.d/kaevcms
sudo -u www-data /usr/bin/php8.3 /var/www/kaevcms/artisan schedule:run
```

Check the KaevCMS scheduler heartbeat after several minutes.

## 14. Queue worker for asynchronous mail

This section is only required after asynchronous queue delivery is enabled in KaevCMS.

Create a systemd service:

```bash
sudo nano /etc/systemd/system/kaevcms-queue.service
```

Contents:

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

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now kaevcms-queue
sudo systemctl status kaevcms-queue --no-pager
```

Read the log:

```bash
sudo journalctl -u kaevcms-queue -n 100 --no-pager
```

Restart the worker after an update:

```bash
sudo -u www-data /usr/bin/php8.3 /var/www/kaevcms/artisan queue:restart
sudo systemctl restart kaevcms-queue
```

## 15. Final verification

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

Check in the browser:

- the home page;
- `/admin`;
- owner sign-in;
- news-image upload;
- SMTP test;
- LoginServer/GameServer connectivity;
- runtime diagnostics;
- uploading a Web Update ZIP without applying it.

## Updating KaevCMS on a VDS

Use the cumulative CLI Updater as the SSH user that owns `/var/www/kaevcms`. Safe permissions intentionally prevent PHP-FPM (`www-data`) from modifying application source code, so browser-based application of an update on a VDS should fail its write preflight instead of granting the web process access to the whole project.

Transfer one current cumulative update ZIP to the server, for example to `/tmp/KaevCMS-update.zip`, then run:

```bash
sudo systemctl stop kaevcms-queue
cd /var/www/kaevcms
php artisan kaevcms:update /tmp/KaevCMS-update.zip
sudo systemctl start kaevcms-queue
rm -f /tmp/KaevCMS-update.zip
```

The command displays the manifest, source and target versions, preflight checks, and asks for confirmation. Add `--yes` only for automation after independently verifying the package:

```bash
php artisan kaevcms:update /tmp/KaevCMS-update.zip --yes
```

Intermediate releases do not need to be installed one by one when the package supports the installed-version range.

When a release changes Composer dependencies, deploy the full release and run `composer install` with the supplied `composer.lock`. Never run `composer update` on production.

## Changing the site address after installation

The KaevCMS address is stored in two places:

```text
/var/www/kaevcms/.env
/etc/nginx/sites-available/kaevcms
```

### Moving to another IP or using HTTP

Open `.env`:

```bash
sudo nano /var/www/kaevcms/.env
```

Set the actual address and disable forced HTTPS:

```env
APP_URL=http://192.168.50.112
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

`APP_FORCE_HTTPS=true` makes KaevCMS generate HTTPS URLs. `SESSION_SECURE_COOKIE=true` prevents the browser from sending the session cookie over HTTP and may cause `419 Page Expired` during sign-in.

Then change `server_name` in nginx:

```bash
sudo nano /etc/nginx/sites-available/kaevcms
```

```nginx
server_name 192.168.50.112;
```

Apply the changes:

```bash
cd /var/www/kaevcms
php artisan optimize:clear
sudo nginx -t
sudo systemctl reload nginx
```

After changing the IP, domain, or protocol, remove the old site cookies or open the site in a private browser window.

### Moving to a domain and HTTPS

After configuring DNS and the certificate, update `.env`:

```env
APP_URL=https://cms.example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

And nginx:

```nginx
server_name cms.example.com;
```

Run `php artisan optimize:clear`, validate nginx, and reload it again.

## Troubleshooting

### `502 Bad Gateway`

```bash
sudo systemctl status php8.3-fpm --no-pager
ls -la /run/php/
sudo tail -n 100 /var/log/nginx/kaevcms-error.log
```

Ensure nginx references an existing socket:

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

### `403 Forbidden`

```bash
namei -l /var/www/kaevcms/public/index.php
sudo -u www-data test -r /var/www/kaevcms/public/index.php && echo readable
```

Every parent directory must provide execute/traverse permission to nginx.

### Write-access failure

```bash
sudo -u www-data test -w /var/www/kaevcms/storage && echo storage-writable
sudo -u www-data test -w /var/www/kaevcms/bootstrap/cache && echo cache-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads && echo uploads-writable
```

Do not fix this with `chmod -R 777 /var/www/kaevcms`.

### `419 Page Expired` after signing in over HTTP

Check `/var/www/kaevcms/.env`:

```env
APP_URL=http://192.168.50.111
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
SESSION_DOMAIN=
```

Then run:

```bash
cd /var/www/kaevcms
php artisan optimize:clear
```

Delete cookies for the old address or use a private browser window. `APP_URL` must match the address currently open in the browser.

### Upload directories are reported as not writable

KaevCMS uses:

```text
public/uploads/news
public/uploads/pages
public/uploads/settings
public/uploads/account-avatars
public/uploads/game-assets
```

The current installer and system diagnostics create missing directories automatically. To repair permissions manually, run:

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

Verify:

```bash
sudo -u www-data test -w /var/www/kaevcms/public/uploads/news && echo news-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads/pages && echo pages-writable
sudo -u www-data test -w /var/www/kaevcms/public/uploads/settings && echo settings-writable
```

Do not use `chmod -R 777`.

### A news item was created but is not visible on the site

Check that the item is not a draft, publishing is enabled, and the publication date is not in the future. A missing image does not prevent a published news item from appearing.

### Logs

```bash
sudo tail -n 100 /var/log/nginx/kaevcms-error.log
sudo tail -n 100 /var/www/kaevcms/storage/logs/laravel.log
sudo journalctl -u php8.3-fpm -n 100 --no-pager
sudo journalctl -u kaevcms-queue -n 100 --no-pager
```

## Official references

- [Ubuntu 24.04 LTS release notes](https://documentation.ubuntu.com/release-notes/24.04/) — PHP 8.3 baseline.
- [Ubuntu 26.04 LTS summary](https://documentation.ubuntu.com/release-notes/26.04/summary-for-lts-users/) — PHP 8.5 baseline.
- [Install nginx on Ubuntu Server](https://documentation.ubuntu.com/server/how-to/web-services/install-nginx/).
- [Install PHP on Ubuntu Server](https://ubuntu.com/server/docs/how-to/web-services/install-php/).
- [Install and configure MySQL](https://ubuntu.com/server/docs/install-and-configure-a-mysql-server).
- [Ubuntu firewall documentation](https://documentation.ubuntu.com/server/how-to/security/firewalls/).
- [Obtain TLS certificates on Ubuntu](https://ubuntu.com/server/docs/how-to/security/obtain-tls-certificates/).
- [Laravel deployment](https://laravel.com/docs/11.x/deployment) — serve only `public/index.php`.
- [Composer install command](https://getcomposer.org/doc/03-cli.md#install-i).
- [Certbot nginx instructions](https://certbot.eff.org/instructions?ws=nginx&os=snap).
