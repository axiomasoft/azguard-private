#!/usr/bin/env bash
# Consumer fixture: install the BUILT package archives into a clean Laravel
# application, the way a consumer receives them (no path repositories, no symlinks),
# then serve real HTTP requests through `azguard.panel` and `#[CheckPermission]`.
#
#   bash bin/consumer-fixture.sh [--laravel=13] [--version=1.0.0-alpha.dev] [--with-filament] [--keep]
#
# Exit codes: 0 success, 1 failure, 3 Packagist unreachable (result is `unavailable`, never green).
# Writes only to build/ in the repository and to a temporary directory.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist="${root}/build/dist"

laravel="13"
version="1.0.0-alpha.dev"
with_filament=0
keep=0

usage() {
    echo "usage: consumer-fixture.sh [--laravel=N] [--version=V] [--with-filament] [--keep]" >&2
    exit 2
}

for arg in "$@"; do
    case "${arg}" in
        --laravel=*) laravel="${arg#*=}" ;;
        --version=*) version="${arg#*=}" ;;
        --with-filament) with_filament=1 ;;
        --keep) keep=1 ;;
        *) usage ;;
    esac
done

if [[ ! "${laravel}" =~ ^[0-9]+$ ]]; then
    echo "error: --laravel must be a major version number: ${laravel}" >&2
    exit 2
fi

work="$(mktemp -d)"
cleanup() {
    if ((keep)); then
        echo "kept: ${work}"
    else
        rm -rf "${work}"
    fi
}
trap cleanup EXIT

log() {
    echo "[consumer-fixture] $*"
}

check_network() {
    if command -v curl >/dev/null && ! curl --silent --fail --head --max-time 20 https://repo.packagist.org/packages.json >/dev/null; then
        echo "[consumer-fixture] UNAVAILABLE: Packagist is not reachable; this run proves nothing." >&2
        exit 3
    fi
}

# Archives are built from COPIES: the version is set only in the copy, never in the source composer.json.
build_archives() {
    mkdir -p "${dist}"
    find "${dist}" -maxdepth 1 -type f -name 'axiomasoft-*.zip' -delete

    local package copy
    for package in core filament; do
        copy="${work}/pkg-${package}"
        cp -a "${root}/packages/${package}" "${copy}"
        php -r '
            $file = $argv[1];
            $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $json["version"] = $argv[2];
            file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        ' "${copy}/composer.json" "${version}"
        composer archive --format=zip --dir="${dist}" --working-dir="${copy}" --no-interaction >/dev/null
    done

    log "archives in ${dist}:"
    ls -1 "${dist}" | sed 's/^/  /'
}

create_app() {
    log "creating a clean Laravel ${laravel}.x application"
    composer create-project "laravel/laravel:^${laravel}.0" "${work}/app" --no-interaction --prefer-dist --no-progress >/dev/null
}

install_archives() {
    local packages=("axiomasoft/azguard:${version}")
    if ((with_filament)); then
        packages+=("axiomasoft/azguard-filament:${version}")
    fi

    cd "${work}/app"
    composer config minimum-stability dev
    composer config prefer-stable true
    composer config repositories.azguard artifact "${dist}"

    log "requiring from the artifact repository: ${packages[*]}"
    composer require "${packages[@]}" --no-interaction --no-progress
}

# Reads Laravel's own discovery manifest instead of grepping its escaped source.
assert_discovered() {
    local package="$1" provider="$2"
    if ! php -r '
        $manifest = require "bootstrap/cache/packages.php";
        exit(in_array($argv[2], $manifest[$argv[1]]["providers"] ?? [], true) ? 0 : 1);
    ' "${package}" "${provider}"; then
        echo "error: ${provider} was not discovered from ${package}" >&2
        exit 1
    fi
    log "discovered: ${provider}"
}

smoke() {
    cd "${work}/app"

    local package installed=(azguard)
    if ((with_filament)); then
        installed+=(azguard-filament)
    fi
    for package in "${installed[@]}"; do
        if [[ -L "vendor/axiomasoft/${package}" ]]; then
            echo "error: vendor/axiomasoft/${package} is a symlink; expected an installed archive" >&2
            exit 1
        fi
        log "installed: $(composer show "axiomasoft/${package}" | grep -E '^(versions|dist)' | tr -s ' ' | paste -sd' ')"
    done

    php artisan package:discover --ansi
    assert_discovered 'axiomasoft/azguard' 'AzGuard\AzGuardServiceProvider'
    if ((with_filament)); then
        assert_discovered 'axiomasoft/azguard-filament' 'AzGuard\Filament\AzGuardFilamentServiceProvider'
    fi

    php artisan about >/dev/null
    log "OK"
}

# A panel, a controller with #[CheckPermission] behind azguard.panel and a feature test from fixtures/consumer/http:
# 403 without the permission, 200 with it. Laravel 13 applies the attribute itself; 11 and 12 rely on azguard.panel.
http_step() {
    cd "${work}/app"

    cp -R "${root}/fixtures/consumer/http/." .
    php -r '
        $file = "bootstrap/providers.php";
        $providers = require $file;
        $providers[] = "App\\Guards\\Shop\\ShopGuardPanelProvider";
        file_put_contents($file, "<?php\n\nreturn ".var_export($providers, true).";\n");
    '
    printf "\nrequire __DIR__.'/azguard.php';\n" >> routes/web.php
    composer dump-autoload --no-interaction >/dev/null

    log "HTTP: #[CheckPermission] behind azguard.panel on Laravel ${laravel}"
    php vendor/bin/phpunit tests/Feature/AzGuardHttpTest.php
    log "HTTP OK"
}

check_network
build_archives
create_app
install_archives
smoke
http_step
