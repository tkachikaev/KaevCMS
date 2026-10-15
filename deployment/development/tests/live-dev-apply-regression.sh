#!/usr/bin/env bash
set -Eeuo pipefail
script="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)/live-dev-apply.sh"
fixture="$(mktemp -d /tmp/kaevcms-live-dev-test.XXXXXXXX)"
stage=''
cleanup() {
    [[ "${fixture}" =~ ^/tmp/kaevcms-live-dev-test\.[A-Za-z0-9]+$ ]] && rm -rf -- "${fixture}"
    if [[ "${stage}" =~ ^/tmp/kaevcms-live-dev\.[A-Za-z0-9]+$ ]]; then rm -rf -- "${stage}"; fi
}
trap cleanup EXIT
root="${fixture}/site"
mkdir -p "${root}/app" "${root}/public/uploads" "${fixture}/outside"
printf '<?php exit(0);\n' > "${root}/artisan"
printf '9.9.9\n' > "${root}/VERSION"
printf 'private-data' > "${root}/.env"
printf 'user-upload' > "${root}/public/uploads/keep.txt"
printf 'old-source' > "${root}/app/old.php"
stage="$(mktemp -d /tmp/kaevcms-live-dev.XXXXXXXX)"
mkdir -p "${stage}/payload/app"
printf '<?php echo "safe";\n' > "${stage}/payload/app/new.php"
printf 'app/new.php\n' > "${stage}/files.txt"
printf 'app/old.php\n' > "${stage}/delete.txt"
bash "${script}" "${root}" "${stage}" >/dev/null
[[ -f "${root}/app/new.php" && ! -e "${root}/app/old.php" ]]
[[ "$(stat -c '%a' "${root}/app/new.php")" == 640 ]]

for path in '.env' '../outside/escape.php' 'public/uploads/keep.txt' 'modules/demo/database/migrations/change.php' 'app/link/escape.php'; do
    stage="$(mktemp -d /tmp/kaevcms-live-dev.XXXXXXXX)"
    mkdir -p "${stage}/payload/app"
    printf '<?php echo "changed";\n' > "${stage}/payload/app/new.php"
    printf 'app/new.php\n%s\n' "${path}" > "${stage}/files.txt"
    : > "${stage}/delete.txt"
    ln -sfn "${fixture}/outside" "${root}/app/link"
    if bash "${script}" "${root}" "${stage}" >/dev/null 2>&1; then
        printf 'Live dev accepted an unsafe batch.\n' >&2
        exit 1
    fi
    grep -q 'echo "safe"' "${root}/app/new.php"
done
[[ "$(cat "${root}/.env")" == private-data && "$(cat "${root}/public/uploads/keep.txt")" == user-upload ]]
[[ ! -e "${fixture}/outside/escape.php" ]]
printf 'Live-dev apply, deletion, permissions, batch validation and runtime-protection regressions passed.\n'
