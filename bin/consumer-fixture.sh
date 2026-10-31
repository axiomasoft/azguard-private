#!/usr/bin/env bash
# Consumer fixture: install the BUILT package archives into a clean Laravel
# application, the way a consumer receives them (no path repositories, no symlinks),
# then install its schema and test real HTTP requests and RefreshDatabase grants/cache.
#
#   bash bin/consumer-fixture.sh [core-only|relocated-audit] [--laravel=13] [--version=1.0.0] [--with-filament] [--keep]
#                                [--report=FILE] [--source=archive|packagist]
#
# `--source=packagist --version=V` installs the published packages from Packagist instead of the archives built from this
# checkout (default `--source=archive`): the same scenarios on a clean application, for the post-release verification.
# It needs `--version`, does not build or consume build/dist, and does not combine with core-only or relocated-audit,
# which repackage the archive.
#
# `core-only` builds the core archive WITHOUT its modules/ directory (providers and PSR-4 reduced to core), installs it
# and proves that the application boots, migrates, passes the doctor, grants a role and answers a check with no module.
#
# `relocated-audit` rehearses a future extraction of the Audit module (I19): an application with an audited panel and
# journal rows, installed from the full core archive, switches to a core archive without modules/audit plus a synthetic
# `axiomasoft/azguard-audit` archive with the same PSR-4 prefix and provider, and keeps FQCNs, commands, publish tag,
# migration basename, doctor result and journal rows with one autoload owner. `--report=FILE` writes the record.
#
# Exit codes: 0 success, 1 failure, 3 Packagist unreachable (result is `unavailable`, never green).
# Writes only to build/ in the repository and to a temporary directory.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist="${root}/build/dist"

laravel="13"
version="1.0.0"
with_filament=0
keep=0
mode="full"
report=""
source="archive"
version_given=0

usage() {
    echo "usage: consumer-fixture.sh [core-only|relocated-audit] [--laravel=N] [--version=V] [--with-filament] [--keep] [--report=FILE] [--source=archive|packagist]" >&2
    exit 2
}

for arg in "$@"; do
    case "${arg}" in
        --laravel=*) laravel="${arg#*=}" ;;
        --version=*) version="${arg#*=}"; version_given=1 ;;
        --source=archive | --source=packagist) source="${arg#*=}" ;;
        --with-filament) with_filament=1 ;;
        --keep) keep=1 ;;
        --report=?*) report="${arg#*=}" ;;
        core-only) mode="core-only" ;;
        relocated-audit) mode="relocated-audit" ;;
        *) usage ;;
    esac
done

if [[ "${mode}" != "full" ]] && ((with_filament)); then
    echo "error: ${mode} installs the core archive alone; --with-filament does not combine with it" >&2
    exit 2
fi

if [[ "${source}" == "packagist" ]]; then
    if ((version_given == 0)); then
        echo "error: --source=packagist needs --version=<released version>" >&2
        exit 2
    fi
    if [[ "${mode}" != "full" ]]; then
        echo "error: ${mode} rehearses a repackaged archive; it does not combine with --source=packagist" >&2
        exit 2
    fi
fi

if [[ -n "${report}" && "${mode}" != "relocated-audit" ]]; then
    echo "error: --report belongs to relocated-audit" >&2
    exit 2
fi
if [[ -n "${report}" && "${report}" != /* ]]; then
    report="${PWD}/${report}"
fi

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

    local package copy packages=(core filament)
    if [[ "${mode}" != "full" ]]; then
        packages=(core)
    fi
    for package in "${packages[@]}"; do
        copy="${work}/pkg-${package}"
        cp -a "${root}/packages/${package}" "${copy}"
        # The core-only archive has no module roots: the directory is gone, the providers and PSR-4 roots of the modules too.
        if [[ "${mode}" == "core-only" ]]; then
            rm -rf "${copy}/modules"
        fi
        php -r '
            $file = $argv[1];
            $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $json["version"] = $argv[2];
            if ($argv[3] === "core-only") {
                $json["autoload"]["psr-4"] = ["AzGuard\\" => "src/"];
                $json["extra"]["laravel"]["providers"] = ["AzGuard\\AzGuardServiceProvider"];
            }
            file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        ' "${copy}/composer.json" "${version}" "${mode}"
        composer archive --format=zip --dir="${dist}" --working-dir="${copy}" --no-interaction >/dev/null
    done

    log "archives in ${dist}:"
    ls -1 "${dist}" | sed 's/^/  /'
}

create_app() {
    log "creating a clean Laravel ${laravel}.x application"
    # Laravel's create-project hooks may migrate. Never inherit a host database target into those hooks.
    APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= AZGUARD_DB_CONNECTION=sqlite \
        composer create-project "laravel/laravel:^${laravel}.0" "${work}/app" --no-interaction --prefer-dist --no-progress >/dev/null
}

install_archives() {
    local packages=("axiomasoft/azguard:${version}")
    if ((with_filament)); then
        packages+=("axiomasoft/azguard-filament:${version}")
    fi

    cd "${work}/app"
    if [[ "${source}" == "packagist" ]]; then
        # A pre-release resolves only when the stability allows it; the dev stability keeps prefer-stable for the rest.
        composer config minimum-stability dev
        composer config prefer-stable true
        log "requiring from Packagist: ${packages[*]}"
        composer require "${packages[@]}" --no-interaction --no-progress
        return
    fi
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

# The core-only archive really lacks the modules: no directory, no autoload root, no provider. Otherwise it proves nothing.
assert_core_only_archive() {
    if [[ -e vendor/axiomasoft/azguard/modules ]]; then
        echo "error: the core-only archive still holds modules/" >&2
        exit 1
    fi
    if ! php -r '
        $manifest = require "bootstrap/cache/packages.php";
        $providers = $manifest["axiomasoft/azguard"]["providers"] ?? [];
        $psr4 = require "vendor/composer/autoload_psr4.php";
        $modules = array_filter(array_keys($psr4), static fn (string $prefix): bool => preg_match("/^AzGuard.(Scopes|Audit|DevTools)./", $prefix) === 1);
        exit($providers === ["AzGuard\\AzGuardServiceProvider"] && $modules === [] ? 0 : 1);
    '; then
        echo "error: the core-only application still has a module provider or PSR-4 root" >&2
        exit 1
    fi
    log "core-only: no modules/, no module provider, no module PSR-4 root"
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
    if [[ "${mode}" == "core-only" ]]; then
        assert_core_only_archive
    fi
    if ((with_filament)); then
        assert_discovered 'axiomasoft/azguard-filament' 'AzGuard\Filament\AzGuardFilamentServiceProvider'
    fi

    php artisan about >/dev/null
    # The AzGuard section of `about` (AboutCommand::add) on every supported Laravel.
    local about
    about="$(php artisan about --only=azguard)"
    for row in 'AzGuard' 'Version' 'Catalog cache' 'Host keys'; do
        if ! grep -q "${row}" <<<"${about}"; then
            echo "error: 'php artisan about' has no \"${row}\" row of the AzGuard section" >&2
            exit 1
        fi
    done
    log "about: AzGuard section printed"
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
        $providers[] = "App\\Guards\\Stored\\StoredGuardPanelProvider";
        file_put_contents($file, "<?php\n\nreturn ".var_export($providers, true).";\n");
    '
    printf "\nrequire __DIR__.'/azguard.php';\n" >> routes/web.php
    composer dump-autoload --no-interaction >/dev/null

    export APP_ENV=testing DB_CONNECTION=sqlite DB_URL= AZGUARD_DB_CONNECTION=sqlite
    export DB_DATABASE="${work}/app/database/azguard_consumer_test.sqlite"
    export AZGUARD_CONSUMER_FIXTURE_ROOT="${work}/app"
    php -r '
        if (! str_ends_with($argv[1], "/database/azguard_consumer_test.sqlite") || ! str_starts_with($argv[1], getcwd()."/")) {
            throw new RuntimeException("Consumer database is not isolated inside its temporary application.");
        }
        $database = fopen($argv[1], "x");
        if ($database === false) {
            throw new RuntimeException("Could not create the isolated consumer database.");
        }
        fclose($database);
        $xml = new DOMDocument;
        $xml->load("phpunit.xml");
        $php = $xml->getElementsByTagName("php")->item(0);
        if ($php === null) {
            throw new RuntimeException("Consumer phpunit.xml has no PHP environment section.");
        }
        $values = ["APP_ENV" => "testing", "DB_CONNECTION" => "sqlite", "DB_DATABASE" => $argv[1],
            "DB_URL" => "", "AZGUARD_DB_CONNECTION" => "sqlite", "AZGUARD_CONSUMER_FIXTURE_ROOT" => getcwd()];
        foreach ($values as $name => $value) {
            foreach (iterator_to_array($php->childNodes) as $node) {
                if ($node instanceof DOMElement && in_array($node->tagName, ["env", "server"], true) && $node->getAttribute("name") === $name) {
                    $php->removeChild($node);
                }
            }
            foreach (["server", "env"] as $channel) {
                $node = $xml->createElement($channel);
                $node->setAttribute("name", $name);
                $node->setAttribute("value", $value);
                $node->setAttribute("force", "true");
                $php->appendChild($node);
            }
        }
        $xml->save("phpunit.xml");
    ' "${DB_DATABASE}"

    log "Install: bigint host keys, sqlite connection and pre-existing config cache on Laravel ${laravel}"
    php artisan config:cache --no-interaction
    php artisan azguard:install --connection=sqlite --host-keys=bigint --migrate --force --no-interaction

    log "HTTP: #[CheckPermission] behind azguard.panel on Laravel ${laravel}"
    php vendor/bin/phpunit tests/Feature/AzGuardHttpTest.php
    log "HTTP OK"
    log "RefreshDatabase: installed writer, shared file cache, rollback/restart and production guard on Laravel ${laravel}"
    php vendor/bin/phpunit tests/Feature/AzGuardBaselineTest.php
    log "RefreshDatabase OK"
}

# The core-only fixture: a panel with stored grants and no plugin; migrate, doctor, one grant and one check with no module.
core_only_step() {
    cd "${work}/app"

    cp -R "${root}/fixtures/consumer/core-only/." .
    php -r '
        $file = "bootstrap/providers.php";
        $providers = require $file;
        $providers[] = "App\\Guards\\Core\\CoreGuardPanelProvider";
        file_put_contents($file, "<?php\n\nreturn ".var_export($providers, true).";\n");
    '
    composer dump-autoload --no-interaction >/dev/null

    export APP_ENV=testing DB_CONNECTION=sqlite DB_URL= AZGUARD_DB_CONNECTION=sqlite
    export DB_DATABASE="${work}/app/database/azguard_core_only.sqlite"
    : >"${DB_DATABASE}"

    log "core-only: migrate with the package-loaded migration on Laravel ${laravel}"
    php artisan migrate --force --no-interaction
    log "core-only: doctor"
    php artisan azguard:doctor --no-interaction
    log "core-only: grant and check"
    php check.php
    log "core-only OK"
}

# The relocated archives: the core archive without modules/audit (its PSR-4 root and provider removed, as core-only does
# for every module) and a synthetic axiomasoft/azguard-audit archive whose root is modules/audit with the same prefix and
# provider. They carry the next patch version: a relocation is a release, and Composer replaces a package only for
# another version.
build_relocated_archives() {
    local relocated="$1" target="$2"
    mkdir -p "${target}"
    find "${target}" -maxdepth 1 -type f -name 'axiomasoft-*.zip' -delete

    local core="${work}/relocated-core" audit="${work}/relocated-audit"
    cp -a "${root}/packages/core" "${core}"
    rm -rf "${core}/modules/audit"
    mkdir -p "${audit}"
    cp -a "${root}/packages/core/modules/audit/." "${audit}/"
    cp "${root}/packages/core/LICENSE" "${audit}/LICENSE"

    php -r '
        $core = $argv[1];
        $json = json_decode(file_get_contents($core), true, 512, JSON_THROW_ON_ERROR);
        $json["version"] = $argv[3];
        unset($json["autoload"]["psr-4"]["AzGuard\\Audit\\"]);
        $json["extra"]["laravel"]["providers"] = array_values(array_diff($json["extra"]["laravel"]["providers"], ["AzGuard\\Audit\\AuditServiceProvider"]));
        file_put_contents($core, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $audit = [
            "name" => "axiomasoft/azguard-audit",
            "description" => "Relocation rehearsal of the AzGuard Audit module (never published)",
            "type" => "library",
            "license" => "MIT",
            "version" => $argv[3],
            "require" => ["axiomasoft/azguard" => $argv[3]],
            "autoload" => ["psr-4" => ["AzGuard\\Audit\\" => "src/"]],
            "extra" => ["laravel" => ["providers" => ["AzGuard\\Audit\\AuditServiceProvider"]]],
        ];
        file_put_contents($argv[2], json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    ' "${core}/composer.json" "${audit}/composer.json" "${relocated}"

    composer archive --format=zip --dir="${target}" --working-dir="${core}" --no-interaction >/dev/null
    composer archive --format=zip --dir="${target}" --working-dir="${audit}" --no-interaction >/dev/null
    log "relocated archives in ${target}:"
    ls -1 "${target}" | sed 's/^/  /'
}

# Facts of the application that must not change when Audit moves: the ran migrations, the journal rows, the Audit
# commands, the files the publish tag would write and the doctor result.
relocation_snapshot() {
    local into="$1"
    mkdir -p "${into}"
    php artisan migrate:status --no-interaction | grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+.*(Ran|Pending)' \
        | sed -E 's/ *\.+ */ /; s/\[[0-9]+\] //' >"${into}/migrate-status.txt"
    php journal.php rows >"${into}/rows.json"
    php journal.php commands >"${into}/commands.json"
    php journal.php autoload >"${into}/autoload.json"
    ls -1 database/migrations >"${into}/migrations.txt"
    set +e
    php artisan azguard:doctor --json --no-interaction >"${into}/doctor.json"
    echo "$?" >"${into}/doctor.exit"
    set -e
}

relocation_fail() {
    echo "error: relocated-audit: $*" >&2
    exit 1
}

# The audited application before and after the relocation; every assertion of p4-execution#relocation.
relocated_audit_step() {
    cd "${work}/app"

    cp -R "${root}/fixtures/consumer/relocated-audit/." .
    php -r '
        $file = "bootstrap/providers.php";
        $providers = require $file;
        $providers[] = "App\\Guards\\Audited\\AuditedGuardPanelProvider";
        file_put_contents($file, "<?php\n\nreturn ".var_export($providers, true).";\n");
    '
    composer dump-autoload --no-interaction >/dev/null

    export APP_ENV=testing DB_CONNECTION=sqlite DB_URL= AZGUARD_DB_CONNECTION=sqlite
    export DB_DATABASE="${work}/app/database/azguard_relocated.sqlite"
    : >"${DB_DATABASE}"

    local before="${work}/before" after="${work}/after" relocated
    relocated="$(php -r 'preg_match("/^(\d+)\.(\d+)\.(\d+)(.*)$/", $argv[1], $m) === 1 || exit(1); echo $m[1].".".$m[2].".".($m[3] + 1).$m[4];' "${version}")" \
        || relocation_fail "--version ${version} is not MAJOR.MINOR.PATCH"

    log "relocated-audit: before — publish the Audit migration, migrate, two audited grants (core ${version})"
    php artisan vendor:publish --tag=azguard-audit-migrations --no-interaction
    php artisan migrate --force --no-interaction
    php journal.php grant
    php journal.php grant
    relocation_snapshot "${before}"
    [[ "$(cat "${before}/doctor.exit")" == 0 ]] || relocation_fail "azguard:doctor fails before the relocation: $(cat "${before}/doctor.json")"
    php -r 'exit(json_decode(file_get_contents($argv[1]), true)["count"] === 2 ? 0 : 1);' "${before}/rows.json" \
        || relocation_fail "two audited grants did not write two journal rows: $(cat "${before}/rows.json")"
    [[ "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["AuditPlugin"];' "${before}/autoload.json")" == "$(cd vendor/axiomasoft/azguard/modules/audit/src && pwd -P)/AuditPlugin.php" ]] \
        || relocation_fail "before the relocation AuditPlugin does not load from the core archive: $(cat "${before}/autoload.json")"

    log "relocated-audit: relocate — core ${relocated} without modules/audit + axiomasoft/azguard-audit ${relocated}"
    build_relocated_archives "${relocated}" "${root}/build/dist-relocated"
    composer config --unset repositories.azguard
    composer config repositories.azguard-relocated artifact "${root}/build/dist-relocated"
    composer require "axiomasoft/azguard:${relocated}" "axiomasoft/azguard-audit:${relocated}" --no-interaction --no-progress
    composer dump-autoload --optimize --no-interaction >/dev/null
    for package in azguard azguard-audit; do
        [[ ! -L "vendor/axiomasoft/${package}" ]] || relocation_fail "vendor/axiomasoft/${package} is a symlink"
        log "installed: $(composer show "axiomasoft/${package}" | grep -E '^(versions|dist)' | tr -s ' ' | paste -sd' ')"
    done
    assert_discovered 'axiomasoft/azguard-audit' 'AzGuard\Audit\AuditServiceProvider'

    log "relocated-audit: after — one autoload owner"
    [[ ! -e vendor/axiomasoft/azguard/modules/audit ]] || relocation_fail "the relocated core archive still ships modules/audit"
    php -r '
        $core = json_decode(file_get_contents("vendor/axiomasoft/azguard/composer.json"), true, 512, JSON_THROW_ON_ERROR);
        isset($core["autoload"]["psr-4"]["AzGuard\\Audit\\"]) && exit(1);
        in_array("AzGuard\\Audit\\AuditServiceProvider", $core["extra"]["laravel"]["providers"] ?? [], true) && exit(1);
        $psr4 = require "vendor/composer/autoload_psr4.php";
        $owner = realpath("vendor/axiomasoft/azguard-audit/src");
        if (array_map("realpath", $psr4["AzGuard\\Audit\\"] ?? []) !== [$owner]) { exit(1); }
        $classmap = require "vendor/composer/autoload_classmap.php";
        $audit = array_filter($classmap, static fn (string $file, string $class): bool => str_starts_with($class, "AzGuard\\Audit\\"), ARRAY_FILTER_USE_BOTH);
        if ($audit === []) { exit(1); }
        foreach ($audit as $file) {
            if (! str_starts_with((string) realpath($file), $owner.DIRECTORY_SEPARATOR)) { exit(1); }
        }
    ' || relocation_fail "AzGuard\Audit\ has another autoload owner than vendor/axiomasoft/azguard-audit"

    relocation_snapshot "${after}"
    [[ "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["AuditPlugin"];' "${after}/autoload.json")" == "$(cd vendor/axiomasoft/azguard-audit/src && pwd -P)/AuditPlugin.php" ]] \
        || relocation_fail "AuditPlugin does not load from vendor/axiomasoft/azguard-audit: $(cat "${after}/autoload.json")"

    log "relocated-audit: after — migrations, publish tag, commands, doctor, journal"
    grep -qE '^2026_10_20_000000_create_azguard_audit_log .*Ran$' "${after}/migrate-status.txt" \
        || relocation_fail "the Audit migration is not ran after the relocation: $(cat "${after}/migrate-status.txt")"
    cmp -s "${before}/migrate-status.txt" "${after}/migrate-status.txt" || relocation_fail "migrate:status changed"
    php artisan migrate --pretend --no-interaction | grep -q 'Nothing to migrate' || relocation_fail "migrate --pretend has something to run"
    php artisan vendor:publish --tag=azguard-audit-migrations --no-interaction >/dev/null
    ls -1 database/migrations | cmp -s "${before}/migrations.txt" - || relocation_fail "vendor:publish --tag=azguard-audit-migrations published a new file"
    cmp -s "${before}/commands.json" "${after}/commands.json" || relocation_fail "the Audit commands changed"
    php -r '
        $expected = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $found = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
        foreach (["azguard:audit:migration", "azguard:audit:prune"] as $name) {
            ($found[$name] ?? null) === $expected[$name]["synopsis"] || exit(1);
        }
    ' "${root}/tests/Fixtures/Console/commands.json" "${after}/commands.json" \
        || relocation_fail "the Audit command synopses differ from tests/Fixtures/Console/commands.json: $(cat "${after}/commands.json")"
    [[ "$(cat "${after}/doctor.exit")" == 0 ]] || relocation_fail "azguard:doctor fails after the relocation: $(cat "${after}/doctor.json")"
    php -r '
        foreach (json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR) as $finding) {
            str_starts_with($finding["key"], "audit.table") && exit(1);
        }
    ' "${after}/doctor.json" || relocation_fail "doctor reports audit.table after the relocation: $(cat "${after}/doctor.json")"
    cmp -s "${before}/rows.json" "${after}/rows.json" || relocation_fail "the journal rows changed"

    php journal.php grant
    php journal.php rows >"${work}/appended.json"
    php -r '
        $before = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
        exit($after["count"] === $before["count"] + 1 && array_slice($after["rows"], 0, $before["count"]) === $before["rows"] ? 0 : 1);
    ' "${after}/rows.json" "${work}/appended.json" || relocation_fail "one more audited grant did not append exactly one row"
    php artisan azguard:audit:prune --force --no-interaction || relocation_fail "azguard:audit:prune --force failed"
    log "relocated-audit OK"

    if [[ -n "${report}" ]]; then
        relocation_report "${before}" "${after}" "${relocated}" >"${report}"
        log "report: ${report}"
    fi
}

relocation_report() {
    local before="$1" after="$2" relocated="$3" archive
    echo "| Archive | sha256 |"
    echo "|:--|:--|"
    for archive in "${dist}"/axiomasoft-*.zip "${root}"/build/dist-relocated/axiomasoft-*.zip; do
        echo "| \`${archive#"${root}"/}\` | \`$(sha256sum "${archive}" | cut -d' ' -f1)\` |"
    done
    echo
    echo "| Fact | Before (core ${version}) | After (core ${relocated} + azguard-audit ${relocated}) |"
    echo "|:--|:--|:--|"
    echo "| AuditPlugin file | \`$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["AuditPlugin"];' "${before}/autoload.json" | sed "s#.*/vendor/#vendor/#")\` | \`$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["AuditPlugin"];' "${after}/autoload.json" | sed "s#.*/vendor/#vendor/#")\` |"
    echo "| migrate:status | $(paste -sd';' "${before}/migrate-status.txt") | $(paste -sd';' "${after}/migrate-status.txt") |"
    echo "| journal rows (count, sha256 of event id + payload) | $(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo $r["count"], ": ", implode(", ", array_map(static fn ($x) => substr($x["sha256"], 0, 12), $r["rows"]));' "${before}/rows.json") | $(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo $r["count"], ": ", implode(", ", array_map(static fn ($x) => substr($x["sha256"], 0, 12), $r["rows"]));' "${after}/rows.json") |"
    echo "| Audit commands | $(php -r 'foreach (json_decode(file_get_contents($argv[1]), true) as $n => $s) { echo chr(96), $n, " ", $s, chr(96), " "; }' "${before}/commands.json") | $(php -r 'foreach (json_decode(file_get_contents($argv[1]), true) as $n => $s) { echo chr(96), $n, " ", $s, chr(96), " "; }' "${after}/commands.json") |"
    echo "| published migrations | $(paste -sd' ' "${before}/migrations.txt") | $(paste -sd' ' "${after}/migrations.txt") |"
    echo "| azguard:doctor --json exit / findings | $(cat "${before}/doctor.exit") / $(php -r 'echo implode(", ", array_unique(array_column(json_decode(file_get_contents($argv[1]), true), "key"))) ?: "none";' "${before}/doctor.json") | $(cat "${after}/doctor.exit") / $(php -r 'echo implode(", ", array_unique(array_column(json_decode(file_get_contents($argv[1]), true), "key"))) ?: "none";' "${after}/doctor.json") |"
    echo
    echo "After: \`migrate --pretend\` has nothing to run, \`vendor:publish --tag=azguard-audit-migrations\` publishes no file, one more audited grant appends exactly one row, \`azguard:audit:prune --force\` exits 0, \`AzGuard\\Audit\\\` has one PSR-4 and classmap owner (vendor/axiomasoft/azguard-audit)."
}

# The Filament package: the configuration publishes by its tag and the plugin, the source and the tenant resolver load in
# the booted application of the consumer, with the defaults of the published configuration.
filament_step() {
    cd "${work}/app"

    log "Filament: published configuration, plugin and FilamentSource on Laravel ${laravel}"
    rm -f config/azguard-filament.php
    php artisan vendor:publish --tag=azguard-filament-config --no-interaction
    if [[ ! -f config/azguard-filament.php ]]; then
        echo "error: vendor:publish --tag=azguard-filament-config did not write config/azguard-filament.php" >&2
        exit 1
    fi

    php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $plugin = AzGuard\Filament\AzGuardPlugin::make();
        $checks = [
            "plugin id" => $plugin->getId() === "azguard",
            "definitions default" => $plugin->getDefinitions() === AzGuard\Filament\FilamentDefinitions::Enums,
            "enforce default" => $plugin->isEnforced() === true,
            "abilities default" => in_array("view_any", $plugin->getAbilities(), true),
            "published configuration" => config("azguard-filament.definitions") === "enums",
            "source id" => AzGuard\Filament\Sources\FilamentSource::make("admin")->id() === "filament",
            "tenant resolver" => (new AzGuard\Filament\FilamentTenantResolver) instanceof AzGuard\Contracts\Tenancy\TenantResolver,
        ];
        foreach ($checks as $name => $ok) {
            if (! $ok) {
                fwrite(STDERR, "error: Filament check failed: {$name}\n");
                exit(1);
            }
        }
    '
    log "Filament OK"
}

check_network
if [[ "${source}" == "archive" ]]; then
    build_archives
fi
create_app
install_archives
smoke
if [[ "${mode}" == "core-only" ]]; then
    core_only_step
elif [[ "${mode}" == "relocated-audit" ]]; then
    relocated_audit_step
else
    http_step
    if ((with_filament)); then
        filament_step
    fi
fi
