#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
    printf 'KaevCMS update agent: %s\n' "$1" >&2
    exit 1
}

[[ "${EUID}" -eq 0 ]] || fail "run this script with sudo."
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="${KAEVCMS_PROJECT_ROOT:-$(cd -- "${SCRIPT_DIR}/../.." && pwd -P)}"
REQUEST_DIR="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/requests"
MARKER="${PROJECT_ROOT}/storage/app/kaevcms/update-agent/agent.json"
INSTANCE_ID="$(printf '%s' "${PROJECT_ROOT}" | sha256sum | cut -c1-12)"
SERVICE_NAME="kaevcms-update-agent-${INSTANCE_ID}.service"
PATH_NAME="kaevcms-update-agent-${INSTANCE_ID}.path"

if find "${REQUEST_DIR}" -maxdepth 1 -type f -name '*.request' -print -quit 2>/dev/null | grep -q .; then
    fail "queued update requests exist. Finish or discard them before removing the agent."
fi

systemctl disable --now "${PATH_NAME}" >/dev/null 2>&1 || true
systemctl stop "${SERVICE_NAME}" >/dev/null 2>&1 || true
rm -f "/etc/systemd/system/${PATH_NAME}" "/etc/systemd/system/${SERVICE_NAME}" "${MARKER}"
systemctl daemon-reload
systemctl reset-failed "${SERVICE_NAME}" >/dev/null 2>&1 || true

printf 'KaevCMS VDS update agent removed for %s.\n' "${PROJECT_ROOT}"
