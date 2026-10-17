#!/usr/bin/env bash
# Prepare a dedicated server. CMS installation remains in the existing Web Installer.
set -Eeuo pipefail
umask 0027

fail() { printf 'KaevCMS: %s\n' "$*" >&2; exit 1; }
usage() {
    cat <<'HELP'
Usage: sudo bash deployment/vds/install.sh [options]
  --host HOST             Domain or IPv4 address (asked interactively if omitted).
  --root PATH             Empty destination; default /var/www/kaevcms.
  --installer-ip IPV4      Browser address allowed to use /install/; defaults to SSH client.
  --https --email EMAIL    Obtain a certificate for HOST using Certbot.
  --accept-acme-terms      Explicit agreement to Let's Encrypt subscriber terms.
  --plan                  Print the platform and packages; do not change anything.
  --credentials           Print database credentials in your private root terminal.
  --help                  Show this help.

Supported: Ubuntu 24.04 / 26.04, Debian 13, systemd, one CMS per server.
Run from an extracted full release or repository checkout. No curl | bash.
Existing sites, databases, .env and installed CMS data are never replaced.
No CMS migrations, owner account, payment activation or automatic reboot.
HELP
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
SOURCE="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
ORIGINAL_ARGS=("$@")
TARGET=/var/www/kaevcms
SITE_HOST=''
INSTALLER_IP="${SSH_CONNECTION:-${SSH_CLIENT:-}}"
INSTALLER_IP="${INSTALLER_IP%% *}"
HTTPS=0 EMAIL='' ACCEPT_TERMS=0 PLAN=0 CREDENTIALS=0
STATE_DIR=/var/lib/kaevcms-installer
STATE="$STATE_DIR/state.json"
while (($#)); do
    case "$1" in
        --host|--root|--installer-ip|--email)
            (($# >= 2)) || fail "$1 requires a value"
            case "$1" in
                --host) SITE_HOST="$2" ;; --root) TARGET="$2" ;;
                --installer-ip) INSTALLER_IP="$2" ;; --email) EMAIL="$2" ;;
            esac
            shift 2 ;;
        --https) HTTPS=1; shift ;;
        --accept-acme-terms) ACCEPT_TERMS=1; shift ;;
        --plan) PLAN=1; shift ;;
        --credentials) CREDENTIALS=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) fail "Unknown option: $1" ;;
    esac
done

valid_ipv4() {
    local ip="$1" part
    [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
    local -a parts
    IFS=. read -r -a parts <<< "$ip"
    for part in "${parts[@]}"; do
        [[ ${#part} -le 3 && "$part" == "$((10#$part))" ]] || return 1
        ((10#$part <= 255)) || return 1
    done
}
valid_host() {
    local host="$1" label
    valid_ipv4 "$host" && return 0
    [[ ${#host} -le 253 && "$host" == *.* && "$host" != *..* ]] || return 1
    local -a labels
    IFS=. read -r -a labels <<< "$host"
    for label in "${labels[@]}"; do
        [[ "$label" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || return 1
    done
    [[ "$host" != *. ]]
}
[[ "$TARGET" =~ ^/var/www/[a-zA-Z0-9][a-zA-Z0-9_-]*$ ]] || fail 'Use a direct directory under /var/www, without spaces.'
[[ ! -L "$TARGET" ]] || fail 'Destination must not be a symlink.'
[[ -r /etc/os-release ]] || fail 'Missing /etc/os-release.'
# The OS file is maintained by the operating system, never taken from CLI input.
# shellcheck source=/dev/null
. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:24.04) PHP_VERSION=8.3 ;;
    ubuntu:26.04) PHP_VERSION=8.5 ;;
    debian:13) PHP_VERSION=8.4 ;;
    *) fail "Unsupported system: ${ID:-unknown} ${VERSION_ID:-unknown}. No packages changed." ;;
esac
PACKAGES=(nginx mariadb-server composer rsync curl ca-certificates unzip acl python3
    "php$PHP_VERSION-cli" "php$PHP_VERSION-fpm" "php$PHP_VERSION-mysql"
    "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" "php$PHP_VERSION-curl"
    "php$PHP_VERSION-zip" "php$PHP_VERSION-intl" "php$PHP_VERSION-gd")
((HTTPS)) && PACKAGES+=(certbot python3-certbot-nginx)
if ((PLAN)); then
    printf 'System: %s %s\nPHP: %s\nRoot: %s\nPackages: %s\n' "$ID" "$VERSION_ID" "$PHP_VERSION" "$TARGET" "${PACKAGES[*]}"
    printf 'Next: configure nginx/FPM/MariaDB, then finish CMS setup in /install/.\n'
    exit 0
fi
if ((EUID != 0)); then
    command -v sudo >/dev/null || fail 'Run as root or install sudo.'
    exec sudo env "SSH_CONNECTION=${SSH_CONNECTION:-}" "SSH_CLIENT=${SSH_CLIENT:-}" bash "$SCRIPT_DIR/install.sh" "${ORIGINAL_ARGS[@]}"
fi
PHP="/usr/bin/php$PHP_VERSION"
HELPER="$SCRIPT_DIR/install-support.php"
if ((CREDENTIALS)); then
    [[ -f "$STATE" && -x "$PHP" ]] || fail 'Server has not been prepared.'
    exec "$PHP" "$HELPER" credentials "$STATE"
fi
[[ -d /run/systemd/system ]] || fail 'A running systemd server is required; containers can use --plan.'
command -v flock >/dev/null || fail 'flock is required.'
exec 9>/run/kaevcms-install.lock
flock -n 9 || fail 'Another server installation is running.'

if [[ -f "$STATE" ]]; then
    SAVED_ROOT="$("$PHP" "$HELPER" state-get "$STATE" root)"
    SAVED_HOST="$("$PHP" "$HELPER" state-get "$STATE" host)"
    [[ "$TARGET" == "$SAVED_ROOT" ]] || fail 'This server already manages a different CMS directory.'
    [[ -z "$SITE_HOST" || "$SITE_HOST" == "$SAVED_HOST" ]] || fail 'Existing site address differs; change nginx/site settings manually.'
    SITE_HOST="$SAVED_HOST"
    if [[ -f "$STATE_DIR/ready" ]]; then
        printf 'Server already prepared. Existing CMS files, credentials and configuration were preserved.\n'
        systemctl is-active nginx mariadb "php$PHP_VERSION-fpm"
        printf 'Web Installer (if not yet completed): http://%s/install/\n' "$SITE_HOST"
        printf 'Database credentials: sudo bash %s/install.sh --credentials\n' "$SCRIPT_DIR"
        exit 0
    fi
else
    [[ ! -e "$STATE_DIR" || ( -d "$STATE_DIR" && ! -L "$STATE_DIR" ) ]] || fail 'Unsafe installer state directory.'
    [[ ! -e "$TARGET/.env" && ! -L "$TARGET/.env" && ! -e "$TARGET/storage/app/installed.lock" ]] || fail 'An existing CMS was found. Use the updater, not this installer.'
    if [[ -d "$TARGET" && "$TARGET" != "$SOURCE" ]]; then
        [[ -z "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print -quit)" ]] || fail 'Destination is not empty.'
    fi
    if [[ -d /etc/nginx/sites-enabled ]]; then
        [[ -z "$(find /etc/nginx/sites-enabled -mindepth 1 -maxdepth 1 ! -name default -print -quit)" ]] || fail 'Existing nginx sites found. Use a dedicated empty server.'
    fi
    [[ ! -e /etc/systemd/system/kaevcms-queue.service && ! -e /etc/php/$PHP_VERSION/fpm/pool.d/kaevcms.conf ]] || fail 'Existing KaevCMS service configuration found.'
fi

if [[ -z "$SITE_HOST" ]]; then
    [[ -t 0 ]] || fail 'Supply --host DOMAIN_OR_IPV4.'
    read -r -p 'Site domain or IPv4: ' SITE_HOST
fi
valid_host "$SITE_HOST" || fail 'Invalid domain/IPv4. Do not include a scheme, port or path.'
if ! valid_ipv4 "$INSTALLER_IP"; then
    [[ -t 0 ]] || fail 'Supply --installer-ip with your browser public IPv4 address.'
    read -r -p 'Your browser public IPv4 (temporary access to Web Installer): ' INSTALLER_IP
fi
valid_ipv4 "$INSTALLER_IP" || fail 'A single IPv4 address is required for installer access.'
if ((HTTPS)); then
    ! valid_ipv4 "$SITE_HOST" || fail 'Automatic HTTPS requires a domain.'
    [[ "$EMAIL" =~ ^[a-zA-Z0-9._+%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]] || fail 'Supply a valid --email for Certbot.'
    if ((!ACCEPT_TERMS)); then
        [[ -t 0 ]] || fail 'Read https://letsencrypt.org/repository/ and explicitly supply --accept-acme-terms.'
        printf 'Let\047s Encrypt subscriber terms: https://letsencrypt.org/repository/\n'
        read -r -p 'Accept these terms? [y/N] ' agreement
        [[ "$agreement" == y || "$agreement" == Y ]] || fail 'HTTPS terms were not accepted.'
    fi
fi
for required in artisan composer.lock .env.example public/index.php public/install/index.php; do
    [[ -f "$SOURCE/$required" ]] || fail "Incomplete source release: $required missing."
done
[[ ! -L /var/www && ! -L "$STATE_DIR" ]] || fail 'Unexpected directory symlink.'
printf 'Preparing %s %s, PHP %s, %s. Finish CMS installation in your browser.\n' "$ID" "$VERSION_ID" "$PHP_VERSION" "$SITE_HOST"
trap 'printf "KaevCMS preparation stopped. Existing data was not removed; correct the failing step and rerun.\n" >&2' ERR
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends "${PACKAGES[@]}"

install -d -m 0700 "$STATE_DIR"
[[ -f "$STATE" ]] || "$PHP" "$HELPER" state-create "$STATE" "$TARGET" "$SITE_HOST" "$PHP_VERSION"
if ! id kaevcms >/dev/null 2>&1; then
    useradd --system --user-group --create-home --home-dir /var/lib/kaevcms --shell /usr/sbin/nologin kaevcms
fi
[[ "$(id -u kaevcms)" != 0 && "$(getent passwd kaevcms | cut -d: -f6)" == /var/lib/kaevcms ]] || fail 'Unexpected deployment account.'
install -d -o kaevcms -g www-data -m 0750 "$TARGET"
if [[ ! -f "$STATE_DIR/source-ready" ]]; then
    [[ ! -e "$TARGET/.env" && ! -e "$TARGET/storage/app/installed.lock" ]] || fail 'CMS configuration appeared during preparation; refusing to overwrite it.'
    if [[ "$SOURCE" != "$TARGET" ]]; then
        rsync -a --safe-links --no-owner --no-group \
            --exclude=/.git --exclude=/.env --exclude=/vendor --exclude=/node_modules \
            --exclude=/storage --exclude=/bootstrap/cache --exclude=/public/uploads --exclude=/public/storage \
            --exclude=/database/'*.sqlite*' --exclude=/dist --exclude=/test-results --exclude=/playwright-report \
            "$SOURCE/" "$TARGET/"
    fi
    chown -R kaevcms:www-data "$TARGET"
    find "$TARGET" -type d -exec chmod 0750 {} +
    find "$TARGET" -type f -exec chmod 0640 {} +
    touch "$STATE_DIR/source-ready"
fi
for directory in storage bootstrap/cache public/uploads; do
    install -d -o kaevcms -g www-data -m 2770 "$TARGET/$directory"
    setfacl -m u:www-data:rwx,u:kaevcms:rwx,d:u:www-data:rwx,d:u:kaevcms:rwx,d:g::rwx,d:o::--- "$TARGET/$directory"
done
for directory in storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; do
    install -d -o kaevcms -g www-data -m 2770 "$TARGET/$directory"
done
runuser -u kaevcms -- env HOME=/var/lib/kaevcms "$PHP" /usr/bin/composer --working-dir="$TARGET" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
runuser -u kaevcms -- "$PHP" /usr/bin/composer --working-dir="$TARGET" check-platform-reqs --no-dev
# Composer runs as the deployment account; its primary group is not www-data.
# Normalize the newly created dependency tree before exposing it through FPM.
chgrp -R www-data "$TARGET/vendor" "$TARGET/bootstrap/cache"
find "$TARGET/vendor" -type d -exec chmod 0750 {} +
find "$TARGET/vendor" -type f -exec chmod 0640 {} +
chmod -R g+rwX "$TARGET/bootstrap/cache"
runuser -u www-data -- "$PHP" "$TARGET/vendor/autoload.php"
systemctl enable --now mariadb
"$PHP" "$HELPER" database "$STATE"

cat > "/etc/php/$PHP_VERSION/fpm/pool.d/kaevcms.conf" <<'FPM'
[kaevcms]
user = www-data
group = www-data
listen = /run/php/kaevcms.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 70M
php_admin_value[max_execution_time] = 120
FPM
if [[ -e /etc/nginx/sites-available/kaevcms ]]; then
    grep -Fq '# Managed by KaevCMS server installer.' /etc/nginx/sites-available/kaevcms || fail 'Site configuration is not managed by this installer.'
else
cat > /etc/nginx/sites-available/kaevcms <<NGINX
# Managed by KaevCMS server installer. Only public/ is served.
server {
    listen 80;
    server_name $SITE_HOST;
    root $TARGET/public;
    index index.php;
    charset utf-8;
    client_max_body_size 70m;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/kaevcms.sock;
    }
    location /install/ {
        allow 127.0.0.1;
        allow $INSTALLER_IP;
        deny all;
        index index.php;
        try_files \$uri \$uri/ =404;
    }
    location = /install/index.php {
        allow 127.0.0.1;
        allow $INSTALLER_IP;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/kaevcms.sock;
    }
    location ^~ /uploads/ { try_files \$uri =404; }
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
fi
if [[ ! -e /etc/nginx/sites-enabled/kaevcms && ! -L /etc/nginx/sites-enabled/kaevcms ]]; then
    ln -s /etc/nginx/sites-available/kaevcms /etc/nginx/sites-enabled/kaevcms
fi
[[ "$(readlink -f /etc/nginx/sites-enabled/kaevcms)" == /etc/nginx/sites-available/kaevcms ]] || fail 'Unexpected enabled-site path.'
if [[ -L /etc/nginx/sites-enabled/default && "$(readlink -f /etc/nginx/sites-enabled/default)" == /etc/nginx/sites-available/default ]]; then
    unlink /etc/nginx/sites-enabled/default
fi
# The existing Web Installer needs temporary parent-directory write access for
# atomic .env creation and deleting public/install after successful completion.
chmod 0770 "$TARGET" "$TARGET/public"
chmod -R g+rwX "$TARGET/public/install"
install -d -m 0755 /usr/local/lib/kaevcms
install -o root -g root -m 0755 "$SCRIPT_DIR/finish-server-setup.py" /usr/local/lib/kaevcms/finish-server-setup.py
cat > /etc/systemd/system/kaevcms-finish-setup.service <<UNIT
[Unit]
Description=Close temporary KaevCMS installer permissions
ConditionPathExists=$TARGET/storage/app/installed.lock
ConditionPathExists=!$STATE_DIR/finalized
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/kaevcms/finish-server-setup.py $TARGET
UNIT
cat > /etc/systemd/system/kaevcms-finish-setup.timer <<'UNIT'
[Unit]
Description=Check completion of KaevCMS Web Installer
[Timer]
OnCalendar=*-*-* *:*:00
[Install]
WantedBy=timers.target
UNIT
for task in scheduler queue; do
    if [[ "$task" == scheduler ]]; then
        TASK_TYPE=oneshot
        TASK_COMMAND="schedule:run"
        RESTART=''
    else
        TASK_TYPE=simple
        TASK_COMMAND="queue:work database --queue=mail-probe,mail,default --sleep=3 --tries=3 --timeout=120"
        RESTART=$'Restart=on-failure\nRestartSec=5\nTimeoutStopSec=360'
    fi
    cat > "/etc/systemd/system/kaevcms-$task.service" <<UNIT
[Unit]
Description=KaevCMS $task
After=network.target mariadb.service
ConditionPathExists=$TARGET/storage/app/installed.lock
[Service]
Type=$TASK_TYPE
User=www-data
Group=www-data
WorkingDirectory=$TARGET
ExecStart=$PHP $TARGET/artisan $TASK_COMMAND
UMask=0007
$RESTART
UNIT
    cat > "/etc/systemd/system/kaevcms-$task.timer" <<UNIT
[Unit]
Description=Start KaevCMS $task after Web Installer completes
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
done
nginx -t
"/usr/sbin/php-fpm$PHP_VERSION" -t
systemctl daemon-reload
systemctl enable --now "php$PHP_VERSION-fpm" nginx
systemctl restart "php$PHP_VERSION-fpm"
systemctl reload nginx
systemctl enable --now kaevcms-finish-setup.timer kaevcms-scheduler.timer kaevcms-queue.timer
if ((HTTPS)); then
    certbot --nginx --non-interactive --agree-tos --redirect -d "$SITE_HOST" -m "$EMAIL"
    systemctl enable --now certbot.timer
fi
CHECK_ORIGIN=http://127.0.0.1
CHECK_ARGS=(--noproxy '*' -H "Host: $SITE_HOST")
if ((HTTPS)); then
    CHECK_ORIGIN="https://$SITE_HOST"
    CHECK_ARGS+=(--resolve "$SITE_HOST:443:127.0.0.1")
fi
curl --fail --silent --show-error "${CHECK_ARGS[@]}" "$CHECK_ORIGIN/install/" -o "$STATE_DIR/installer-check.html"
for private in .env vendor/autoload.php; do
    code="$(curl --silent -o /dev/null -w '%{http_code}' "${CHECK_ARGS[@]}" "$CHECK_ORIGIN/$private")"
    [[ "$code" == 403 || "$code" == 404 ]] || fail "Private-path HTTP check failed ($code)."
done
touch "$STATE_DIR/ready"
printf '\nServer prepared. Complete the existing Web Installer: %s://%s/install/\n' "$([[ $HTTPS == 1 ]] && echo https || echo http)" "$SITE_HOST"
printf 'Only 127.0.0.1 and %s may access the unfinished installer.\n' "$INSTALLER_IP"
printf 'Database credentials (run privately over SSH):\n sudo bash %s/deployment/vds/install.sh --credentials\n' "$TARGET"
printf 'CMS migrations, owner account and .env are created only by the Web Installer. Payments remain off.\n'
