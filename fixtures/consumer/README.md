# Consumer fixtures

A consumer fixture installs the **built** AzGuard archives into a clean Laravel application, the way a consumer
receives them: no path repositories and no symlinks. It catches packaging mistakes (missing files, wrong
autoload, undiscovered providers) that the monorepo test suite cannot see.

## Run

```bash
bash bin/consumer-fixture.sh                      # core archive, Laravel 13
bash bin/consumer-fixture.sh --with-filament      # core and Filament archives
bash bin/consumer-fixture.sh --laravel=12 --version=1.0.0-alpha.dev --keep
```

| Option | Meaning |
|:--|:--|
| `--laravel=N` | Laravel major version of the fixture application (default `13`) |
| `--version=V` | Version stamped into the **copies** of the package manifests (default `1.0.0-alpha.dev`) |
| `--with-filament` | Also install `axiomasoft/azguard-filament` |
| `--keep` | Keep the temporary directory and print its path |

What the script does:

1. Copies `packages/core` and `packages/filament` to a temporary directory, sets `version` in the copies only, and
   runs `composer archive --format=zip` into `build/dist/` (ignored by git).
2. Runs `composer create-project laravel/laravel` for the requested major version.
3. Adds `build/dist` as an `artifact` repository and requires the packages from it.
4. Checks that the packages are installed archives, that `php artisan package:discover` registers
   `AzGuard\AzGuardServiceProvider` (and the Filament provider with `--with-filament`), and that `php artisan about` runs.

The script writes only to `build/` and to a temporary directory, needs no Docker, and needs network access to
Packagist. Exit codes: `0` success, `1` failure, `2` bad arguments, `3` Packagist unreachable. Code `3` means the
result is `unavailable`, never green.

## CI

The `consumer-fixture` job in `.github/workflows/tests.yml` runs both variants on PHP 8.4 and Laravel 13. It is
non-blocking (`continue-on-error: true`) until the fixtures below exist.

## Planned fixtures

The current script is the skeleton. These fixtures and the support matrix are planned:

| | Fixture |
|:--|:--|
| (a) | One panel |
| (b) | Cabinet on code and policies, an admin panel on the database, a seller cabinet with relations, and a module |
| (c) | `azguard` + `azguard-filament` |
| (d) | The integration example (`fixtures/example-integration`) |
