#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
    printf 'KaevCMS update agent: %s\n' "$1" >&2
    exit 1
}

[[ "${EUID}" -eq 0 ]] || fail "run this script with sudo."
command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
[[ -d /run/systemd/system ]] || fail "systemd is not running on this server."

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="${KAEVCMS_PROJECT_ROOT:-$(cd -- "${SCRIPT_DIR}/../.." && pwd -P)}"
[[ "${PROJECT_ROOT}" != *$'\n'* && "${PROJECT_ROOT}" != *$'\r'* ]] || fail "the project path contains an unsupported newline."
ARTISAN="${PROJECT_ROOT}/artisan"
[[ -f "${ARTISAN}" ]] || fail "artisan was not found in ${PROJECT_ROOT}."
[[ -f "${PROJECT_ROOT}/.env" ]] || fail ".env was not found. Complete the KaevCMS installation first."

DEPLOY_USER="${KAEVCMS_DEPLOY_USER:-$(stat -c '%U' "${ARTISAN}")}"
[[ -n "${DEPLOY_USER}" ]] || fail "could not determine the deployment owner."
[[ "${DEPLOY_USER}" != "root" ]] || fail "the project is owned by root. Set the correct owner before installing the agent."
id "${DEPLOY_USER}" >/dev/null 2>&1 || fail "deployment user ${DEPLOY_USER} does not exist."

WEB_GROUP="${KAEVCMS_WEB_GROUP:-$(stat -c '%G' "${PROJECT_ROOT}/storage")}"
getent group "${WEB_GROUP}" >/dev/null 2>&1 || fail "web group ${WEB_GROUP} does not exist."

if [[ -n "${KAEVCMS_PHP_BINARY:-}" ]]; then
    PHP_BINARY="${KAEVCMS_PHP_BINARY}"
elif command -v php8.3 >/dev/null 2>&1; then
    PHP_BINARY="$(command -v php8.3)"
elif command -v php >/dev/null 2>&1; then
    PHP_BINARY="$(command -v php)"
else
    fail "PHP CLI was not found."
fi
[[ -x "${PHP_BINARY}" ]] || fail "PHP CLI is not executable: ${PHP_BINARY}."
"${PHP_BINARY}" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || fail "PHP CLI 8.3 or newer is required."
"${PHP_BINARY}" -r 'exit(extension_loaded("zip") ? 0 : 1);' || fail "the PHP zip extension is required by the update agent."

INSTANCE_ID="$(printf '%s' "${PROJECT_ROOT}" | sha256sum | cut -c1-12)"
SERVICE_NAME="kaevcms-update-agent-${INSTANCE_ID}.service"
PATH_NAME="kaevcms-update-agent-${INSTANCE_ID}.path"
REQUEST_DIR="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/requests"
MARKER="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/agent.json"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}"
PATH_FILE="/etc/systemd/system/${PATH_NAME}"

install -d -o "${DEPLOY_USER}" -g "${WEB_GROUP}" -m 2770 "${REQUEST_DIR}"

cat > "${SERVICE_FILE}" <<EOF
[Unit]
Description=KaevCMS VDS update agent (${INSTANCE_ID})
After=network.target
ConditionPathExists=${ARTISAN}

[Service]
Type=oneshot
User=${DEPLOY_USER}
Group=${WEB_GROUP}
WorkingDirectory=${PROJECT_ROOT}
ExecStart=${PHP_BINARY} ${ARTISAN} kaevcms:update-agent:run --no-ansi
UMask=0007
TimeoutStartSec=infinity
Nice=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${PROJECT_ROOT}

[Install]
WantedBy=multi-user.target
EOF

cat > "${PATH_FILE}" <<EOF
[Unit]
Description=Watch KaevCMS VDS update requests (${INSTANCE_ID})

[Path]
DirectoryNotEmpty=${REQUEST_DIR}
Unit=${SERVICE_NAME}

[Install]
WantedBy=multi-user.target
EOF

chmod 0644 "${SERVICE_FILE}" "${PATH_FILE}"

runuser -u "${DEPLOY_USER}" -- "${PHP_BINARY}" "${ARTISAN}" kaevcms:update-agent:register \
    --service-name="${SERVICE_NAME}" \
    --path-unit="${PATH_NAME}" \
    --php-binary="${PHP_BINARY}" \
    --installed-at="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --no-ansi

chown "${DEPLOY_USER}:${WEB_GROUP}" "${MARKER}"
chmod 0660 "${MARKER}"
trap 'rm -f "${MARKER}"' ERR

systemctl daemon-reload
systemctl enable --now "${PATH_NAME}"
systemctl start "${SERVICE_NAME}"
systemctl is-active --quiet "${PATH_NAME}" || fail "the systemd path unit did not become active."
trap - ERR

printf '\nKaevCMS VDS update agent installed.\n'
printf 'Project: %s\n' "${PROJECT_ROOT}"
printf 'Deployment user: %s\n' "${DEPLOY_USER}"
printf 'Web group: %s\n' "${WEB_GROUP}"
printf 'Path unit: %s\n' "${PATH_NAME}"
printf 'Service: %s\n' "${SERVICE_NAME}"
printf 'Status: cd %q && %q artisan kaevcms:update-agent:status\n' "${PROJECT_ROOT}" "${PHP_BINARY}"
