# AzGuard — Development

## State of 0.7.0

This monorepo holds AzGuard 0.7.0, the first public release. The API may change before 1.0. The 0.3 code is not part of any build.

| Path | What it is |
|---|---|
| `packages/core` | `axiomasoft/azguard`, namespace `AzGuard\` (the core) |
| `packages/filament` | `axiomasoft/azguard-filament`, namespace `AzGuard\Filament\` |
| `tests/` | Pest suites `Arch`, `Unit`, `Feature`, `Regression` on Orchestra Testbench |

The 0.3 reference tree was removed; read it with `git show 491980a:legacy/0.3/<path>`. The 0.3 `context` package
is not recreated; its behavior is part of the core package.

## Local development

Test the package against a real Laravel app via a path repository.

```json
// the consuming app's composer.json
"repositories": [
    {
        "type": "path",
        "url": "../azguard/packages/core",
        "options": { "symlink": true }
    }
],
"require": {
    "axiomasoft/azguard": "@dev"
}
```

Mount or symlink the package directory into the app, then `composer update`.

## Quality commands

| Command | Tool | Description |
|---|---|---|
| `composer test` | Pest | Run the test suite |
| `composer test:parallel` | Pest / ParaTest | Run the SQLite suite in parallel with random order intact |
| `composer test:types` | Pest | Type-coverage gate (min 98%) |
| `composer analyse` | PHPStan / Larastan | Static analysis (level 9, no baseline) |
| `composer lint` / `lint:check` | Pint | Fix / check code style |
| `composer refactor` / `refactor:check` | Rector | Apply / preview refactorings |
| `composer mutate` | Pest mutate | Optional per-package mutation testing (`composer mutate:core` or `composer mutate:filament`) |
| `composer check:coverage` | Pest / pcov or Xdebug | Line-coverage gate (`--min=85`) |
| `composer bench` | — | Benchmarks of the public API, see `docs/advanced/performance.md` |
| `composer bench:load` | — | Load bench with concurrent workers, see `bench/README.md` |
| `composer check` | — | Run the local gates: style, analysis, refactor, API manifest, types, tests, coverage |
| `composer fix` | — | Auto-fix style and apply refactorings |

Feature tests use an in-memory SQLite database, so the `pdo_sqlite` /
`sqlite3` PHP extensions must be enabled.

`composer test:parallel` keeps Pest's random execution order and passes the
same 1G memory limit to every ParaTest worker. It is intentionally limited to
the SQLite lane; run PostgreSQL and MySQL lanes sequentially with their
dedicated commands below.

## Optional mutation testing

The owner has deferred mutation testing while the API is unstable before 1.0. Mutation is not an
acceptance or release gate for 0.7.0 and is excluded from `composer check`. The workflow runs only via
`workflow_dispatch`; `composer mutate`, `composer mutate:core` and `composer mutate:filament` remain
available with their existing thresholds and fail on errors. Further validation of the existing
mutation-runner compatibility patch is a separate task.

### Mutation-ratchet policy

Pest's native mutator reports one covered mutation score per package. Raise a
per-package threshold only from a fresh, successful Xdebug measurement: each new
threshold is `floor(measured score) - 2` and must never be below the previous
threshold. Do not lower a threshold to make a red gate pass. Exclusions live
next to the package settings in `bin/mutation-gate.sh`, carry an inline
rationale, and are reviewed as code.

The first native Pest measurement was 100.00% for every package, and an Xdebug run on
2026-09-24 confirmed it, so the native Pest gates enforce 99%.
Line coverage on that run was 87.1%, so `composer check:coverage` and the CI
coverage job enforce `--min=85` (`floor(87.1) - 2`). Local runs use PATH `php`,
then `php8.4`, and fail when neither binary has pcov/Xdebug. `AZGUARD_ALLOW_NO_COVERAGE=1`
is the only opt-out; it prints `NOT VERIFIED`. CI supplies Xdebug.

## Local database matrix

`composer test` runs against SQLite `:memory:` by default and excludes the
`engines`, `redis` and `replica` groups. Those require their dedicated test services;
excluding them does not qualify those backends. To exercise the
package against real database engines (Postgres 16, MySQL 8, MariaDB 10.11) and Redis, bring
up the local stand:

```bash
cp .env.example .env   # adjust credentials if needed
make up                # docker compose up -d, waits for services to report healthy
make ps                # check status
make down               # stop and remove the stand
```

`docker-compose.yml` defines four default services — `postgres` (Postgres 16), `mysql`
(MySQL 8), `mariadb` (MariaDB 10.11), `redis` (Redis 7) — each with a healthcheck.
The three test databases store data on tmpfs (a 2 GiB limit per service); Redis keeps its named volume.
These database containers deliberately disable crash durability: PostgreSQL disables `fsync`,
`synchronous_commit` and `full_page_writes`; MySQL disables binlog, commit-time log flushing and
doublewrite, with `O_DIRECT_NO_FSYNC`; MariaDB disables binlog, commit-time log flushing and doublewrite.
Their data disappears on stop/restart, and the image entrypoints recreate `azguard_test` on startup.
Use this stand only for tests. Versions, charset, collation, SQL modes and test isolation are unchanged.
The `authority-replica` profile adds an isolated
Postgres primary and replica for the [replica qualification](tests/Engines/Support/replica-README.md). Ports
are published on `127.0.0.1` only; credentials come from `.env`, never
hardcoded in the compose file. The database names default to `azguard_test`
(`.env.example`), keeping the invariant that test database names end in `_test`.

With the stand up, run the suite against a real engine via `composer
test:pgsql` / `composer test:mysql` / `composer test:mariadb` — these switch `DB_CONNECTION` and
otherwise share `tests/TestCase.php`'s env-driven connection config with the
sqlite default (`composer test`). CI runs sqlite (`tests.yml` main job), the PG/MySQL/MariaDB matrix (`test-db-matrix`
job), and a dedicated Redis lane (`test-redis`) on every push/PR. **All three
lanes are required for merge** — sqlite alone self-skips database-specific code,
and array-store tests do not prove cross-process Redis lock behaviour.

On hosts with existing PostgreSQL/MySQL servers, export `PGSQL_PORT=55432 MYSQL_PORT=53306`
before **both** Compose and test commands (MariaDB defaults to 3307). Before recreating any database
service, check `pgrep -af 'composer test|vendor/bin/pest'` and wait for active runs to finish.
To apply the tmpfs configuration to an existing stand:

```bash
export PGSQL_PORT=55432 MYSQL_PORT=53306
docker compose up -d --force-recreate --wait postgres mysql mariadb
```

The old `azguard_pgsql_data`, `azguard_mysql_data` and `azguard_mariadb_data` volumes remain on disk;
Compose no longer attaches them. Their deletion is a separate owner action.
In CI each database-matrix job starts only its selected service and uses the same bounded tmpfs.
CI retains the image's durability defaults: the local `actionlint` 1.7.12 rejects `services.command`,
so the workflow uses portable service `options` without an image or entrypoint workaround.
The real-replica profile retains its persistent storage and WAL settings.

Tests still drop and migrate the schema before each server-backed test. Replacing that with a single
migration and truncation is rejected: tests create their own tables, and MySQL DDL implicitly commits.
Driver suites remain sequential. Concurrent drivers would need separate Testbench skeletons;
`TESTBENCH_APP_BASE_PATH` is supported by the installed Testbench, but this change does not add a
parallel launcher because full-suite equivalence and the required 30% gain have not been measured.

Before enabling per-driver `pest --parallel`, the P2 follow-up must fix
`ContractWorld::rowCounts()` to count only the selected database: neighbouring databases on the same
MySQL server can hide writes from negative fixtures. Every worker also needs its own Testbench
skeleton and test database; Laravel's `azguard_test_test_N` names currently fail the `_test` suffix
guard. Keep these prerequisites together. This infrastructure change leaves the stabilization plan untouched.

### Infrastructure measurement (2026-10-11)

The owner requested short checks instead of full-suite runs. The same 51 tests ran sequentially on
each server before and after the change, with `--order-by=random --random-order-seed=20261011`.
They cover DDL snapshots, constraints, JSON grants, committed-state isolation, database source reads,
change races, storage schemas and negative contract fixtures. Test identities and assertion counts
match before/after; every measured run exits 0 with no errors, failures or skips.

| Engine | Tests | Assertions | Before (wall seconds) | After (wall seconds) | Speedup |
|---|---:|---:|---:|---:|---:|
| PostgreSQL | 51 | 485 | 15.37 | 8.94 | 1.72× |
| MySQL | 51 | 485 | 50.90 | 11.01 | 4.62× |
| MariaDB | 51 | 543 | 29.93 | 8.36 | 3.58× |

SQLite's matching non-engine selection passes 33 tests / 419 assertions in 0.86 wall seconds.
The historical full-suite durations (SQLite 113 s, PostgreSQL 538 s, MySQL 5449 s / 6813 s under
disk load) are not comparable to this subset; full-suite speed and equivalence remain unmeasured.
No full 4895/4959-test suite or complete 68-test engine bundle was run for this change.
Discovery with `--list-tests-xml` confirms the unchanged inventories without executing them:
SQLite 4895, each server driver 4959, and the engine group 68.

To reproduce the server selection, replace `test:mysql` with `test:pgsql` or `test:mariadb`:

```bash
export PGSQL_PORT=55432 MYSQL_PORT=53306
composer test:mysql -- \
  tests/Engines/StorageDdlTest.php tests/Engines/StorageConstraintsTest.php \
  tests/Engines/GrantFieldsEngineTest.php tests/Engines/StorageIsolationTest.php \
  tests/Engines/DatabaseSourceEngineTest.php tests/Engines/ChangeRaceTest.php \
  tests/Feature/Storage/StorageSchemaTest.php tests/Contracts/NegativeFixturesTest.php \
  --order-by=random --random-order-seed=20261011
```

An initial diagnostic invocation omitted `--order-by=random`: PHPUnit warned about the seed and
exited 1 despite passing assertions. PAO's JSON omitted that runner warning. The table uses only
corrected, successful invocations; no failure/skip gate was relaxed.

With Redis up (`make up`), run the qualification bundle:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT="${REDIS_PORT:-6379}" composer test:redis
```

The lane uses `--fail-on-skipped`; missing `ext-redis` or an unreachable service
is a hard failure, not a silent pass.

## Conventions

- `declare(strict_types=1)` in every PHP file; PHPStan level 9; Pest 4.
- Permission references support enum cases and validated names; roles are PHP
  classes with stable keys declared by `#[Role]` or `key()`.
- Role definitions live in the panel catalog. The database stores assignments
  in `role_grants`, partitioned by panel, tenant, subject, scope and origin;
  there is no role-definition table or DB-only role in 0.7.0.

## Git workflow

Branch from `main` (`feat/…`, `fix/…`), keep the suite green (`composer check`),
and open a Pull Request to `main`. Commits follow Conventional Commits.
