#!/usr/bin/env bash
# Consumer fixture: install the BUILT package archives into a clean Laravel
# application, the way a consumer receives them (no path repositories, no symlinks),
# then install its schema and test real HTTP requests and RefreshDatabase grants/cache.
#
#   bash bin/consumer-fixture.sh [core-only] [--laravel=13] [--version=0.7.0] [--with-filament] [--keep]
#
# `core-only` builds the core archive WITHOUT its modules/ directory (providers and PSR-4 reduced to core), installs it
# and proves that the application boots, migrates, passes the doctor, grants a role and answers a check with no module.
#
# Exit codes: 0 success, 1 failure, 3 Packagist unreachable (result is `unavailable`, never green).
# Writes only to build/ in the repository and to a temporary directory.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist="${root}/build/dist"

laravel="13"
version="0.7.0"
with_filament=0
keep=0
mode="full"

usage() {
    echo "usage: consumer-fixture.sh [core-only] [--laravel=N] [--version=V] [--with-filament] [--keep]" >&2
    exit 2
}

for arg in "$@"; do
    case "${arg}" in
        --laravel=*) laravel="${arg#*=}" ;;
        --version=*) version="${arg#*=}" ;;
        --with-filament) with_filament=1 ;;
        --keep) keep=1 ;;
        core-only) mode="core-only" ;;
        *) usage ;;
    esac
done

if [[ "${mode}" == "core-only" ]] && ((with_filament)); then
    echo "error: core-only installs the core archive alone; --with-filament does not combine with it" >&2
    exit 2
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
    if [[ "${mode}" == "core-only" ]]; then
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
build_archives
create_app
install_archives
smoke
if [[ "${mode}" == "core-only" ]]; then
    core_only_step
else
    http_step
    if ((with_filament)); then
        filament_step
    fi
fi
