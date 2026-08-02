#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
    printf 'KaevCMS update agent: %s\n' "$1" >&2
    exit 1
}

usage() {
    cat <<'TEXT'
Usage: bash deployment/vds/install-update-agent.sh [options]

Options:
  --project-user USER   Account that owns KaevCMS and runs the update service.
  --web-group GROUP     PHP-FPM group that must read the application.
  --web-user USER       PHP-FPM worker account used for the final access check.
  --php-binary PATH     PHP CLI binary used by the service.
  --help                Show this help.

The script requests sudo automatically when needed. The same values can be
supplied through KAEVCMS_DEPLOY_USER, KAEVCMS_WEB_GROUP, KAEVCMS_WEB_USER
and KAEVCMS_PHP_BINARY.
TEXT
}

if (($# == 1)) && [[ "$1" == "--help" || "$1" == "-h" ]]; then
    usage
    exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || fail "administrator rights are required. Run the same command from a root shell or install sudo."
    SELF_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"
    [[ -n "${SELF_PATH}" ]] || fail "could not resolve the installer path."
    ELEVATED_ENV=()
    for name in KAEVCMS_PROJECT_ROOT KAEVCMS_DEPLOY_USER KAEVCMS_WEB_GROUP KAEVCMS_WEB_USER KAEVCMS_PHP_BINARY; do
        if [[ -n "${!name:-}" ]]; then
            ELEVATED_ENV+=("${name}=${!name}")
        fi
    done
    printf 'KaevCMS update agent: administrator rights are required; requesting sudo...\n'
    exec sudo env "${ELEVATED_ENV[@]}" bash "${SELF_PATH}" "$@"
fi

command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
[[ -d /run/systemd/system ]] || fail "systemd is not running on this server."

PROJECT_USER_OPTION=""
WEB_GROUP_OPTION=""
WEB_USER_OPTION=""
PHP_BINARY_OPTION=""
while (($# > 0)); do
    case "$1" in
        --project-user=*) PROJECT_USER_OPTION="${1#*=}" ;;
        --project-user)
            shift
            (($# > 0)) || fail "--project-user requires a value."
            PROJECT_USER_OPTION="$1"
            ;;
        --web-group=*) WEB_GROUP_OPTION="${1#*=}" ;;
        --web-group)
            shift
            (($# > 0)) || fail "--web-group requires a value."
            WEB_GROUP_OPTION="$1"
            ;;
        --web-user=*) WEB_USER_OPTION="${1#*=}" ;;
        --web-user)
            shift
            (($# > 0)) || fail "--web-user requires a value."
            WEB_USER_OPTION="$1"
            ;;
        --php-binary=*) PHP_BINARY_OPTION="${1#*=}" ;;
        --php-binary)
            shift
            (($# > 0)) || fail "--php-binary requires a value."
            PHP_BINARY_OPTION="$1"
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *) fail "unknown option: $1" ;;
    esac
    shift
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="${KAEVCMS_PROJECT_ROOT:-$(cd -- "${SCRIPT_DIR}/../.." && pwd -P)}"
[[ "${PROJECT_ROOT}" != *$'\n'* && "${PROJECT_ROOT}" != *$'\r'* ]] || fail "the project path contains an unsupported newline."
ARTISAN="${PROJECT_ROOT}/artisan"
[[ -f "${ARTISAN}" ]] || fail "artisan was not found in ${PROJECT_ROOT}."
[[ -f "${PROJECT_ROOT}/.env" ]] || fail ".env was not found. Complete the KaevCMS installation first."

DEPLOY_USER="${PROJECT_USER_OPTION:-${KAEVCMS_DEPLOY_USER:-$(stat -c '%U' "${ARTISAN}")}}"
[[ -n "${DEPLOY_USER}" ]] || fail "could not determine the deployment owner."
id "${DEPLOY_USER}" >/dev/null 2>&1 || fail "deployment user ${DEPLOY_USER} does not exist."
DEPLOY_UID="$(id -u "${DEPLOY_USER}")"
DEPLOY_PRIMARY_GROUP="$(id -gn "${DEPLOY_USER}")"

AGENT_PRIVILEGE_MODE="project-owner"
if [[ "${DEPLOY_USER}" == "root" ]]; then
    AGENT_PRIVILEGE_MODE="root"
    printf '\nWARNING: the KaevCMS update agent will run as root because the project is owned by root.\n' >&2
    printf 'Only install update archives from a source you trust. An applied package receives root-level access to this server.\n\n' >&2
fi

FPM_IDENTITY="$(ps -eo user=,group=,args= 2>/dev/null | awk '$1 != "root" && $0 ~ /php-fpm: pool/ { print $1 " " $2; exit }')"
DETECTED_WEB_USER=""
DETECTED_WEB_GROUP=""
if [[ -n "${FPM_IDENTITY}" ]]; then
    DETECTED_WEB_USER="${FPM_IDENTITY%% *}"
    DETECTED_WEB_GROUP="${FPM_IDENTITY#* }"
fi

if [[ -n "${WEB_GROUP_OPTION}" ]]; then
    WEB_GROUP="${WEB_GROUP_OPTION}"
elif [[ -n "${KAEVCMS_WEB_GROUP:-}" ]]; then
    WEB_GROUP="${KAEVCMS_WEB_GROUP}"
elif [[ -n "${DETECTED_WEB_GROUP}" ]] && getent group "${DETECTED_WEB_GROUP}" >/dev/null 2>&1; then
    WEB_GROUP="${DETECTED_WEB_GROUP}"
else
    STORAGE_GROUP="$(stat -c '%G' "${PROJECT_ROOT}/storage")"
    if [[ "${STORAGE_GROUP}" != "root" && "${STORAGE_GROUP}" != "${DEPLOY_PRIMARY_GROUP}" ]] && getent group "${STORAGE_GROUP}" >/dev/null 2>&1; then
        WEB_GROUP="${STORAGE_GROUP}"
    elif getent group www-data >/dev/null 2>&1; then
        WEB_GROUP="www-data"
    elif getent group nginx >/dev/null 2>&1; then
        WEB_GROUP="nginx"
    else
        fail "could not detect the PHP-FPM group. Use --web-group GROUP."
    fi
fi
getent group "${WEB_GROUP}" >/dev/null 2>&1 || fail "web group ${WEB_GROUP} does not exist. Use --web-group or KAEVCMS_WEB_GROUP."
WEB_GID="$(getent group "${WEB_GROUP}" | awk -F: 'NR == 1 { print $3 }')"
[[ "${WEB_GID}" =~ ^[0-9]+$ ]] || fail "could not determine the numeric id of web group ${WEB_GROUP}."

if [[ -n "${WEB_USER_OPTION}" ]]; then
    WEB_USER="${WEB_USER_OPTION}"
elif [[ -n "${KAEVCMS_WEB_USER:-}" ]]; then
    WEB_USER="${KAEVCMS_WEB_USER}"
elif [[ -n "${DETECTED_WEB_USER}" ]] && id "${DETECTED_WEB_USER}" >/dev/null 2>&1; then
    WEB_USER="${DETECTED_WEB_USER}"
elif id www-data >/dev/null 2>&1 && [[ "${WEB_GROUP}" == "www-data" ]]; then
    WEB_USER="www-data"
elif id nginx >/dev/null 2>&1 && [[ "${WEB_GROUP}" == "nginx" ]]; then
    WEB_USER="nginx"
else
    WEB_USER=""
fi
if [[ -n "${WEB_USER}" ]]; then
    id "${WEB_USER}" >/dev/null 2>&1 || fail "web user ${WEB_USER} does not exist. Use --web-user or KAEVCMS_WEB_USER."
fi

if [[ -n "${PHP_BINARY_OPTION}" ]]; then
    PHP_BINARY="${PHP_BINARY_OPTION}"
elif [[ -n "${KAEVCMS_PHP_BINARY:-}" ]]; then
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

set_program_file_mode() {
    local path="$1"
    if [[ -x "${path}" ]] || head -c 2 "${path}" 2>/dev/null | grep -q '^#!'; then
        chmod 0750 "${path}"
    else
        chmod 0640 "${path}"
    fi
}

repair_program_path() {
    local path="$1"
    [[ -e "${path}" ]] || return 0
    chown -R "${DEPLOY_USER}:${WEB_GROUP}" "${path}"
    find "${path}" -type d -exec chmod 2750 {} +
    while IFS= read -r -d '' file; do
        set_program_file_mode "${file}"
    done < <(find "${path}" -type f -print0)
}

repair_public_program_files() {
    local public_root="${PROJECT_ROOT}/public"
    [[ -d "${public_root}" ]] || return 0
    chown "${DEPLOY_USER}:${WEB_GROUP}" "${public_root}"
    chmod 2750 "${public_root}"
    while IFS= read -r -d '' directory; do
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${directory}"
        chmod 2750 "${directory}"
    done < <(find "${public_root}" -mindepth 1 \
        \( -path "${public_root}/uploads" -o -path "${public_root}/storage" \) -prune -o \
        -type d -print0)
    while IFS= read -r -d '' file; do
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${file}"
        set_program_file_mode "${file}"
    done < <(find "${public_root}" \
        \( -path "${public_root}/uploads" -o -path "${public_root}/storage" \) -prune -o \
        -type f -print0)
}

repair_runtime_path() {
    local path="$1"
    [[ -e "${path}" ]] || return 0
    chown -R "${DEPLOY_USER}:${WEB_GROUP}" "${path}"
    find "${path}" -type d -exec chmod 2770 {} +
    find "${path}" -type f -exec chmod 0660 {} +
}

repair_kaevcms_runtime() {
    local root="${PROJECT_ROOT}/storage/app/kaevcms"
    [[ -d "${root}" ]] || return 0
    chown "${DEPLOY_USER}:${WEB_GROUP}" "${root}"
    chmod 2770 "${root}"
    while IFS= read -r -d '' directory; do
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${directory}"
        chmod 2770 "${directory}"
    done < <(find "${root}" -mindepth 1 -path "${root}/update-backups" -prune -o -type d -print0)
    while IFS= read -r -d '' file; do
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${file}"
        chmod 0660 "${file}"
    done < <(find "${root}" -path "${root}/update-backups" -prune -o -type f -print0)
}

repair_project_permissions() {
    chown "${DEPLOY_USER}:${WEB_GROUP}" "${PROJECT_ROOT}"
    chmod 2750 "${PROJECT_ROOT}"

    local path
    for path in app config database deployment docs lang modules resources routes tests vendor; do
        repair_program_path "${PROJECT_ROOT}/${path}"
    done

    if [[ -d "${PROJECT_ROOT}/bootstrap" ]]; then
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${PROJECT_ROOT}/bootstrap"
        chmod 2750 "${PROJECT_ROOT}/bootstrap"
        while IFS= read -r -d '' file; do
            chown "${DEPLOY_USER}:${WEB_GROUP}" "${file}"
            set_program_file_mode "${file}"
        done < <(find "${PROJECT_ROOT}/bootstrap" -maxdepth 1 -type f -print0)
    fi

    repair_public_program_files

    while IFS= read -r -d '' file; do
        chown "${DEPLOY_USER}:${WEB_GROUP}" "${file}"
        set_program_file_mode "${file}"
    done < <(find "${PROJECT_ROOT}" -maxdepth 1 -type f -print0)

    install -d -o "${DEPLOY_USER}" -g "${WEB_GROUP}" -m 2770 \
        "${PROJECT_ROOT}/storage" \
        "${PROJECT_ROOT}/storage/app" \
        "${PROJECT_ROOT}/storage/framework" \
        "${PROJECT_ROOT}/storage/logs" \
        "${PROJECT_ROOT}/bootstrap/cache" \
        "${PROJECT_ROOT}/public/uploads"

    repair_runtime_path "${PROJECT_ROOT}/storage/framework"
    repair_runtime_path "${PROJECT_ROOT}/storage/logs"
    repair_runtime_path "${PROJECT_ROOT}/bootstrap/cache"
    repair_runtime_path "${PROJECT_ROOT}/public/uploads"
    repair_kaevcms_runtime

    if [[ -d "${PROJECT_ROOT}/storage/app/kaevcms/update-backups" ]]; then
        chown -R "${DEPLOY_USER}:${WEB_GROUP}" "${PROJECT_ROOT}/storage/app/kaevcms/update-backups"
        find "${PROJECT_ROOT}/storage/app/kaevcms/update-backups" -type d -exec chmod 0700 {} +
        find "${PROJECT_ROOT}/storage/app/kaevcms/update-backups" -type f -exec chmod 0600 {} +
    fi
}

verify_web_access() {
    [[ -n "${WEB_USER}" ]] || return 0
    runuser -u "${WEB_USER}" -g "${WEB_GROUP}" -- test -x "${PROJECT_ROOT}" || fail "PHP-FPM user ${WEB_USER} cannot enter the project directory."
    runuser -u "${WEB_USER}" -g "${WEB_GROUP}" -- test -r "${ARTISAN}" || fail "PHP-FPM user ${WEB_USER} cannot read application files."
    runuser -u "${WEB_USER}" -g "${WEB_GROUP}" -- test -r "${PROJECT_ROOT}/public/index.php" || fail "PHP-FPM user ${WEB_USER} cannot read public/index.php."
}

repair_project_permissions
verify_web_access

INSTANCE_ID="$(printf '%s' "${PROJECT_ROOT}" | sha256sum | cut -c1-12)"
SERVICE_NAME="kaevcms-update-agent-${INSTANCE_ID}.service"
PATH_NAME="kaevcms-update-agent-${INSTANCE_ID}.path"
REQUEST_DIR="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/requests"
UPDATE_ROOT="${PROJECT_ROOT}/storage/app/kaevcms/updates"
PACKAGE_DIR="${UPDATE_ROOT}/packages"
STAGING_DIR="${UPDATE_ROOT}/staging"
MARKER="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/agent.json"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}"
PATH_FILE="/etc/systemd/system/${PATH_NAME}"

install -d -o "${DEPLOY_USER}" -g "${WEB_GROUP}" -m 2770 \
    "${REQUEST_DIR}" \
    "${UPDATE_ROOT}" \
    "${PACKAGE_DIR}" \
    "${STAGING_DIR}"

if [[ -n "${WEB_USER}" ]]; then
    for directory in "${REQUEST_DIR}" "${PACKAGE_DIR}" "${STAGING_DIR}"; do
        probe="${directory}/.kaevcms-agent-write-test-$$"
        runuser -u "${WEB_USER}" -g "${WEB_GROUP}" -- sh -c ': > "$1" && rm -f "$1"' sh "${probe}" \
            || fail "PHP-FPM user ${WEB_USER} cannot write to ${directory}."
    done
fi

cat > "${SERVICE_FILE}" <<EOF_SERVICE
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
UMask=0027
TimeoutStartSec=infinity
Nice=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${PROJECT_ROOT}

[Install]
WantedBy=multi-user.target
EOF_SERVICE

cat > "${PATH_FILE}" <<EOF_PATH
[Unit]
Description=Watch KaevCMS VDS update requests (${INSTANCE_ID})

[Path]
DirectoryNotEmpty=${REQUEST_DIR}
Unit=${SERVICE_NAME}

[Install]
WantedBy=multi-user.target
EOF_PATH

chmod 0644 "${SERVICE_FILE}" "${PATH_FILE}"

REGISTER_ARGS=(
    kaevcms:update-agent:register
    --service-name="${SERVICE_NAME}"
    --path-unit="${PATH_NAME}"
    --php-binary="${PHP_BINARY}"
    --deployment-user="${DEPLOY_USER}"
    --deployment-uid="${DEPLOY_UID}"
    --web-group="${WEB_GROUP}"
    --web-gid="${WEB_GID}"
    --installed-at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    --no-ansi
)

if [[ "${DEPLOY_USER}" == "root" ]]; then
    "${PHP_BINARY}" "${ARTISAN}" "${REGISTER_ARGS[@]}"
else
    runuser -u "${DEPLOY_USER}" -g "${WEB_GROUP}" -- "${PHP_BINARY}" "${ARTISAN}" "${REGISTER_ARGS[@]}"
fi

install -d -o "${DEPLOY_USER}" -g "${WEB_GROUP}" -m 2770 \
    "${REQUEST_DIR}" \
    "${UPDATE_ROOT}" \
    "${PACKAGE_DIR}" \
    "${STAGING_DIR}"
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
printf 'Deployment user: %s (uid %s)\n' "${DEPLOY_USER}" "${DEPLOY_UID}"
printf 'Agent privilege mode: %s\n' "${AGENT_PRIVILEGE_MODE}"
printf 'Web group: %s (gid %s)\n' "${WEB_GROUP}" "${WEB_GID}"
if [[ -n "${WEB_USER}" ]]; then
    printf 'PHP-FPM user: %s\n' "${WEB_USER}"
fi
printf 'Path unit: %s\n' "${PATH_NAME}"
printf 'Service: %s\n' "${SERVICE_NAME}"
printf 'Web upload directories: %s, %s\n' "${PACKAGE_DIR}" "${STAGING_DIR}"
printf 'Status: cd %q && %q artisan kaevcms:update-agent:status\n' "${PROJECT_ROOT}" "${PHP_BINARY}"
