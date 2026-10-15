# Consumer fixtures

A consumer fixture installs the **built** AzGuard archives into a clean Laravel application, the way a consumer
receives them: no path repositories and no symlinks. It catches packaging mistakes (missing files, wrong
autoload, undiscovered providers) that the monorepo test suite cannot see.

## Run

```bash
bash bin/consumer-fixture.sh                      # core archive, Laravel 13
bash bin/consumer-fixture.sh --with-filament      # core and Filament archives
bash bin/consumer-fixture.sh core-only            # the core archive without modules/ (no Scopes, Audit, DevTools)
bash bin/consumer-fixture.sh relocated-audit      # Audit moved to its own archive in an application with journal rows
bash bin/consumer-fixture.sh --laravel=12 --version=1.0.0 --keep
```

| Option | Meaning |
|:--|:--|
| `--laravel=N` | Laravel major version of the fixture application (default `13`) |
| `--version=V` | Version stamped into the **copies** of the package manifests (default `1.0.0`) |
| `--with-filament` | Also install `axiomasoft/azguard-filament` |
| `--keep` | Keep the temporary directory and print its path |
| `core-only` | Build the core archive without `modules/` (module providers and PSR-4 roots removed), install it and run `fixtures/consumer/core-only`: migrate, `azguard:doctor`, one grant, one check, no module command registered. Does not combine with `--with-filament` |
| `relocated-audit` | Module relocation rehearsal (I19), below. Does not combine with `--with-filament` |
| `--report=FILE` | With `relocated-audit`: write the before/after record (archives with sha256, facts, exit codes) as Markdown |

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

## Relocation rehearsal (`relocated-audit`)

A future extraction of a module must not migrate API, schema or data. The rehearsal moves the most demanding slice,
Audit (table, migration basename, publish tag, two commands, plugin id, doctor check, journal rows):

1. **Before.** The full core archive is installed with `fixtures/consumer/relocated-audit`: the panel `audited` with
   `AuditPlugin`, `vendor:publish --tag=azguard-audit-migrations`, `migrate` and two audited grants
   (`php journal.php grant`). The script records `migrate:status`, the journal rows (count and a sha256 of event id and
   payload per row), the synopses of `azguard:audit:*`, the published migrations and `azguard:doctor --json`.
2. **Relocate.** `build/dist-relocated/` gets a core archive without `modules/audit` (no `AzGuard\Audit\` root, no
   `AuditServiceProvider`) and a synthetic `axiomasoft/azguard-audit` archive whose root is `modules/audit` with the same
   PSR-4 prefix and provider. Both carry the next patch version (a relocation is a release; Composer replaces a package
   only for another version) and are required in the same application.
3. **After.** `AzGuard\Audit\` has one PSR-4 and classmap owner (`vendor/axiomasoft/azguard-audit`); the Audit migration
   is still ran and `migrate --pretend` has nothing to run; publishing the tag writes no file; the command synopses equal
   `tests/Fixtures/Console/commands.json`; the doctor exits 0 without an `audit.table` finding; the journal rows are
   unchanged; one more audited grant appends exactly one row; `azguard:audit:prune --force` exits 0.

Nothing of this is a package in the repository: no `packages/audit`, no split configuration, no Packagist step.

## CI

The `consumer-fixture` job in `.github/workflows/tests.yml` runs the core, core + Filament and core-only variants on PHP 8.4
and Laravel 13; the core-only step blocks the job.

## Fixtures

| Fixture | What it runs |
|:--|:--|
| `http` | A Laravel application with the `Shop` and `Stored` panels: routes with `azguard.panel` and `azguard.can`, HTTP 200/403 tests under `RefreshDatabase`, and the baseline test |
| `core-only` | The core archive without `modules/`: migrate, `azguard:doctor`, one grant, one check |
| `relocated-audit` | The relocation rehearsal above |

`--with-filament` adds the Filament package to the `http` application. The integration example
(`fixtures/example-integration`) is not part of this script.
