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

Verify the dedicated account:

```bash
mysql -u kaevcms_user -p -h 127.0.0.1 kaevcms_db
```

Exit with `EXIT;` after a successful connection.

Use these Web Installer values:

```text
Host:      127.0.0.1
Port:      3306
Database:  kaevcms_db
Username:  kaevcms_user
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

Verify that the PHP-FPM account can create nested cache directories and files:

```bash
cd /var/www/kaevcms
sudo -u www-data php artisan kaevcms:runtime-directories --probe
```

KaevCMS keeps the general application cache on files by default, but stores login and other rate-limit counters in the CMS database:

```env
CACHE_STORE=file
CACHE_LIMITER=database
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

After installation, verify that Web Installer removed the public `/install` directory automatically, then sign in to the administrator panel. If the final page reports a cleanup failure, remove the directory manually.

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

## 15. Install the VDS update agent

This step is required only for starting updates from the administration panel. The agent is not installed automatically and is not required for manual SSH updates.

Run once from an account with `sudo` access:

```bash
cd /var/www/kaevcms
sudo bash deployment/vds/install-update-agent.sh
```

The script detects the project owner, PHP-FPM group, and PHP CLI binary, then creates dedicated `systemd.path` and `systemd.service` units for this installation. The agent opens no network port and does not grant `www-data` write access to application source code.

Verify the registration:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update-agent:status
```

Expected output includes `State: ready` and `Ready: yes`. Running the installer again safely refreshes the units and registration.

To remove the agent:

```bash
cd /var/www/kaevcms
sudo bash deployment/vds/remove-update-agent.sh
```

Removal is blocked while a queued update request remains in the agent directory.

## 16. Final verification

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

### Updating from the administration panel

For the first upgrade from a release older than `0.47.0`, use the manual CLI Updater because older releases do not contain the agent. After installing `0.47.0`, install the agent once and future compatible updates can be started from the panel.

This method requires the agent from step 15. When the agent is absent, KaevCMS displays **“VDS update agent is not installed”** with the exact installation command. A ZIP may still be uploaded and verified, but installation remains unavailable until the agent is ready.

1. Open **Settings → System → System updates**.
2. Upload the current cumulative Update ZIP.
3. Review the target version, manifest, SHA256, and preflight checks.
4. Confirm that you trust the archive source. The site owner is responsible for the selected package because an update can replace KaevCMS program files.
5. Enter the current owner password and select **Send to VDS agent**.

The website writes a local request to protected runtime storage. `systemd.path` starts a one-shot agent as the project file owner. The agent verifies the archive and permissions again, backs up application files and the database, enables maintenance mode, applies files and migrations, clears caches, and restarts the queue. Existing automatic rollback is used when installation fails.

The update page refreshes automatically while the request is waiting for the agent. After the agent starts, maintenance mode and the update log record preparation, file replacement, migrations, completion, or failure. Do not remove the ZIP or start another update until the current request finishes.

Agent diagnostics:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update-agent:status --json
sudo journalctl -u 'kaevcms-update-agent-*.service' -n 100 --no-pager
```

If registration is invalid or the units were removed, reinstall them:

```bash
cd /var/www/kaevcms
sudo bash deployment/vds/install-update-agent.sh
```

### Fallback update through SSH

The manual CLI Updater remains available without the agent. Run it as the SSH/deployment user that owns `/var/www/kaevcms`, never as `www-data`:

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


If Laravel reports `storage/framework/cache/data/...: No such file or directory`, recreate and write-test the runtime tree as the PHP-FPM account:

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

`optimize:clear` is not a substitute for correct ownership. The updater runs the runtime-directory probe both before and after clearing caches.

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
