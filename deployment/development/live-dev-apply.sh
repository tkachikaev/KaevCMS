#!/usr/bin/env bash
set -Eeuo pipefail
shopt -s nocasematch

root="${1:?Project root is required}"
stage="${2:?Staging directory is required}"
[[ "${root}" == /* && "${root}" != / && ! -L "${root}" ]] || exit 1
[[ -f "${root}/artisan" && -f "${root}/VERSION" && -d "${root}/public" ]] || exit 1
[[ "${stage}" =~ ^/tmp/kaevcms-live-dev\.[A-Za-z0-9]+$ && -d "${stage}" && ! -L "${stage}" ]] || exit 1
trap 'rm -rf -- "${stage}"' EXIT
[[ -f "${stage}/files.txt" && -f "${stage}/delete.txt" && -d "${stage}/payload" ]] || exit 1

validate_path() {
    local path="$1" part probe="${root}"
    [[ "${path}" =~ ^(app|config|lang|resources|routes|modules|themes|account-themes|public/(assets|themes|account-themes))/[A-Za-z0-9_@./-]+$ ]] || return 1
    [[ ! "${path}" =~ (^|/)(\.|\.\.|\.[^/]*|database|migrations|storage|uploads|vendor|node_modules|cache)(/|$) ]] || return 1
    [[ "${path}" != *'//'* && "${path}" != */ && ! "${path}" =~ \.(sqlite|db|key|pem|zip|gz)$ ]] || return 1
    IFS='/' read -r -a parts <<< "${path}"
    for part in "${parts[@]}"; do
        probe="${probe}/${part}"
        [[ ! -L "${probe}" ]] || return 1
    done
}

# Validate the complete batch before touching application files.
if find "${stage}/payload" -type l -print -quit | grep -q .; then
    printf 'Live dev rejects symbolic links.\n' >&2
    exit 1
fi
for list in files.txt delete.txt; do
    while IFS= read -r path || [[ -n "${path}" ]]; do
        [[ -n "${path}" ]] || continue
        validate_path "${path}" || { printf 'Live dev rejected a protected or unsafe path.\n' >&2; exit 1; }
        if [[ "${list}" == files.txt ]]; then
            [[ -f "${stage}/payload/${path}" && ! -L "${stage}/payload/${path}" ]] || exit 1
            if [[ "${path}" == *.php ]]; then
                php -l "${stage}/payload/${path}" >/dev/null
            fi
        elif [[ -e "${root}/${path}" && ! -f "${root}/${path}" ]]; then
            printf 'Live dev only deletes individual source files.\n' >&2
            exit 1
        fi
    done < "${stage}/${list}"
done

umask 0027
rsync -r --checksum --perms --chmod=D2750,F640 --no-links --no-devices --no-specials \
    --files-from="${stage}/files.txt" "${stage}/payload/" "${root}/"
while IFS= read -r path || [[ -n "${path}" ]]; do
    [[ -n "${path}" ]] || continue
    rm -f -- "${root}/${path}"
done < "${stage}/delete.txt"
cd -- "${root}"
php artisan view:clear --no-ansi
printf 'Live dev batch applied.\n'
