#!/usr/bin/env bash
# Schema upgrade fixture: install the archive of an older ref in a clean Laravel application, seed it through the public
# surface, capture decisions and the doctor, swap to the archive of a newer ref, run `php artisan migrate`, and prove
# that the captured decisions are unchanged. It also records whether `migrate:rollback` works on the new schema.
#
#   bash bin/upgrade-fixture.sh --from=<ref> [--to=HEAD] [--driver=sqlite|pgsql|mysql|mariadb] [--laravel=13] [--keep]
#
# Before the first stable tag, `--from` is the `candidate` of compatibility.json:
#   bash bin/upgrade-fixture.sh --from=$(python3 -c 'import json; print(json.load(open("compatibility.json"))["candidate"])')
#
# Server drivers use the docker services of the repository (PGSQL_PORT, MYSQL_PORT, MARIADB_PORT; DB_USERNAME and
# DB_PASSWORD override the defaults); a fresh database is created and dropped at the end. The archive and application
# bootstrap steps are the ones of bin/consumer-fixture.sh (build_archives, create_app, install_archives), reduced to the
# core archive; this script does not source it so that the two evolve independently.
#
# Exit codes: 0 equal decisions, 1 failure or a decision that changed, 2 usage.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

from=""
to="HEAD"
driver="sqlite"
laravel="13"
keep=0

usage() {
    echo "usage: upgrade-fixture.sh --from=<ref> [--to=HEAD] [--driver=sqlite|pgsql|mysql|mariadb] [--laravel=N] [--keep]" >&2
    exit 2
}

for arg in "$@"; do
    case "${arg}" in
        --from=?*) from="${arg#*=}" ;;
        --to=?*) to="${arg#*=}" ;;
        --driver=sqlite | --driver=pgsql | --driver=mysql | --driver=mariadb) driver="${arg#*=}" ;;
        --laravel=*) laravel="${arg#*=}" ;;
        --keep) keep=1 ;;
        *) usage ;;
    esac
done

[[ -n "${from}" ]] || usage
[[ "${laravel}" =~ ^[0-9]+$ ]] || usage

from_sha="$(git -C "${root}" rev-parse --verify --quiet "${from}^{commit}")" || { echo "error: unknown ref ${from}" >&2; exit 2; }
to_sha="$(git -C "${root}" rev-parse --verify --quiet "${to}^{commit}")" || { echo "error: unknown ref ${to}" >&2; exit 2; }

work="$(mktemp -d)"
database=""

log() {
    echo "[upgrade-fixture] $*"
}

case "${driver}" in
    pgsql) port="${PGSQL_PORT:-25432}" ;;
    mysql) port="${MYSQL_PORT:-23306}" ;;
    mariadb) port="${MARIADB_PORT:-23307}" ;;
    *) port="" ;;
esac

create_database() {
    database="azguard_upgrade_$$"
    case "${driver}" in
        pgsql) PGPASSWORD="${DB_PASSWORD:-azguard}" psql -h 127.0.0.1 -p "${port}" -U "${DB_USERNAME:-azguard}" -d postgres -qc "create database ${database}" ;;
        mysql | mariadb) mysql -h 127.0.0.1 -P "${port}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-azguard}" -e "create database ${database}" ;;
    esac
}

drop_database() {
    case "${driver}" in
        pgsql) PGPASSWORD="${DB_PASSWORD:-azguard}" psql -h 127.0.0.1 -p "${port}" -U "${DB_USERNAME:-azguard}" -d postgres -qc "drop database if exists ${database}" ;;
        mysql | mariadb) mysql -h 127.0.0.1 -P "${port}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-azguard}" -e "drop database if exists ${database}" ;;
    esac
}

cleanup() {
    if [[ -n "${database}" ]]; then
        drop_database >/dev/null 2>&1 || true
    fi
    if ((keep)); then
        echo "kept: ${work}"
    else
        rm -r -f "${work}"
    fi
}
trap cleanup EXIT

# The environment of the application commands for the chosen driver.
export_database_env() {
    export APP_ENV=testing DB_URL= AZGUARD_DB_CONNECTION="${driver}"
    case "${driver}" in
        sqlite)
            export DB_CONNECTION=sqlite DB_DATABASE="${work}/app/database/azguard_upgrade.sqlite"
            : >"${DB_DATABASE}"
            ;;
        *)
            export DB_CONNECTION="${driver}" DB_HOST=127.0.0.1 DB_PORT="${port}" DB_DATABASE="${database}" \
                DB_USERNAME="${DB_USERNAME:-$([[ "${driver}" == pgsql ]] && echo azguard || echo root)}" DB_PASSWORD="${DB_PASSWORD:-azguard}"
            ;;
    esac
}

# The core archive of a ref: `git archive` of packages/core into a copy, the version set in the copy only.
build_archive() {
    local ref_sha="$1" version="$2" target="$3"
    local copy="${work}/pkg-${version}"
    mkdir -p "${copy}" "${target}"
    git -C "${root}" archive "${ref_sha}" packages/core | tar -x -C "${copy}"
    php -r '
        $file = $argv[1];
        $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $json["version"] = $argv[2];
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    ' "${copy}/packages/core/composer.json" "${version}"
    composer archive --format=zip --dir="${target}" --working-dir="${copy}/packages/core" --no-interaction >/dev/null
}

# Decisions and the doctor, as stable text.
capture() {
    local out="$1" request
    {
        for request in 'user:1 notes.view' 'user:1 notes.edit' 'user:2 notes.view' 'user:2 notes.edit' 'user:3 notes.view' 'user:3 notes.edit'; do
            echo "== explain ${request}"
            # shellcheck disable=SC2086
            # `now` is the clock of the capture; every other field must be equal across the upgrade.
            php artisan azguard:explain --panel=core --json ${request} | sed -E 's/^( *"now": ").*(",?)$/\1<clock>\2/'
        done
        echo "== doctor"
        php artisan azguard:doctor --json || true
    } >"${out}"
}

log "from ${from} (${from_sha:0:8}) to ${to} (${to_sha:0:8}), driver ${driver}, Laravel ${laravel}"

dist_from="${work}/dist-from"
dist_to="${work}/dist-to"
build_archive "${from_sha}" "1.0.0" "${dist_from}"
build_archive "${to_sha}" "1.0.1" "${dist_to}"

log "creating a clean Laravel ${laravel}.x application"
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= AZGUARD_DB_CONNECTION=sqlite \
    composer create-project "laravel/laravel:^${laravel}.0" "${work}/app" --no-interaction --prefer-dist --no-progress >/dev/null

cd "${work}/app"
if [[ "${driver}" != "sqlite" ]]; then
    create_database
fi
export_database_env

composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.azguard artifact "${dist_from}"
log "installing the older archive"
composer require "axiomasoft/azguard:*" --no-interaction --no-progress >/dev/null

# The panel of the core-only fixture (stored grants, one role), copied as in consumer-fixture.sh core_only_step.
cp -R "${root}/fixtures/consumer/core-only/app/." app/
php -r '
    $file = "bootstrap/providers.php";
    $providers = require $file;
    $providers[] = "App\\Guards\\Core\\CoreGuardPanelProvider";
    file_put_contents($file, "<?php\n\nreturn ".var_export($providers, true).";\n");
'
composer dump-autoload --no-interaction >/dev/null

log "migrate and seed on the older schema"
php artisan migrate --force --no-interaction >/dev/null
cp "${root}/fixtures/consumer/upgrade/seed.php" seed.php
php seed.php
capture "${work}/before.txt"

log "swapping to the newer archive"
composer config repositories.azguard artifact "${dist_to}"
composer update "axiomasoft/azguard" --no-interaction --no-progress >/dev/null
composer show axiomasoft/azguard | grep -E '^versions' | tr -s ' ' | sed 's/^/[upgrade-fixture] /'
php artisan migrate --force --no-interaction >/dev/null
capture "${work}/after.txt"

status=0
if ! diff -u "${work}/before.txt" "${work}/after.txt"; then
    echo "error: a decision or the doctor changed across the upgrade" >&2
    status=1
else
    log "decisions and the doctor are unchanged ($(grep -c '^== explain' "${work}/after.txt") requests)"
fi

# Rollback probe: recorded, never an error by itself. An irreversible step must be declared in UPGRADING.md.
if php artisan migrate:rollback --force --no-interaction >/dev/null 2>&1; then
    log "rollback of the newest migration batch: succeeded"
    php artisan migrate --force --no-interaction >/dev/null
else
    log "rollback of the newest migration batch: FAILED (declare the irreversible step in UPGRADING.md)"
fi

exit "${status}"
