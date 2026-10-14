# AzGuard — Development

## State of 1.0

This monorepo holds AzGuard 1.0, the first public release.

| Path | What it is |
|---|---|
| `packages/core` | `axiomasoft/azguard`, namespace `AzGuard\` (the core) |
| `packages/filament` | `axiomasoft/azguard-filament`, namespace `AzGuard\Filament\` |
| `tests/` | Pest suites `Arch`, `Unit`, `Feature`, `Regression` on Orchestra Testbench |

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
| `composer test` | Pest | The SQLite lane: every test except `engines`, `redis`, `replica` and `extended` |
| `composer test:parallel` | Pest / ParaTest | The SQLite lane on 4 workers, random order intact |
| `composer test:changed` | Pest | Only the zones a change touches and the zones that use them, see [tests/README.md](tests/README.md) |
| `composer test:db -- <driver>` | Pest / ParaTest | The database lane on `pgsql`, `mysql` or `mariadb` (`--parallel[=N]`, `--full`, `--engines`, `--zone=`) |
| `composer test:extended` | Pest | The scale tests (R58) that nightly CI and a release run |
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
same 1G memory limit to every ParaTest worker. Tests are routed by zone and by
lane; [tests/README.md](tests/README.md) has the zone map and the lane rules.

## Optional mutation testing

The owner has deferred mutation testing. Mutation is not an
acceptance or release gate for 1.0 and is excluded from `composer check`. The workflow runs only via
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

With the stand up, run the database lane against a real engine with
`composer test:db -- pgsql|mysql|mariadb`. The lane is the tests whose result depends on the engine (group
`database`: storage, database sources, the change pipeline, SQL visibility, directories, parity, contracts); the
rest of the suite runs once on SQLite (`composer test`). The lane switches `DB_CONNECTION` and otherwise shares
`tests/TestCase.php`'s env-driven connection config with the sqlite default. CI runs sqlite (`tests.yml` main job),
the PG/MySQL/MariaDB lane on Laravel 12 and 13 (`test-db-matrix` job) and a dedicated Redis lane (`test-redis`) on
every push/PR; the scale tests and all parity seeds run nightly (`tests-nightly.yml`). **All lanes are required for
merge** — sqlite alone self-skips database-specific code, and array-store tests do not prove cross-process Redis
lock behaviour.

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
In CI each database-matrix job starts only its selected service through
`docker compose up -d --wait --wait-timeout 120`. The same Compose file supplies bounded tmpfs,
durability flags, credentials and healthchecks locally and in CI. This avoids service-command
compatibility issues and keeps one database configuration. Service logs are captured and the
selected database is stopped even when tests fail.
The matrix caches only Composer's downloaded archives, keyed by the Composer manifests and
lockfiles; `composer update` still resolves and installs dependencies on every job.
The real-replica profile retains its persistent storage and WAL settings.

### Parallel database runs

Tests still drop and migrate the schema before each server-backed test. Replacing that with a single
migration and truncation is rejected: tests create their own tables, and MySQL DDL implicitly commits.
Avoid the DDL in a test that does not need a schema (`tests/Feature/Storage/DirectWritesTest.php` binds its models
without one): on a server it is the largest cost of a test.

`composer test:db -- <driver> --parallel[=N]` runs N ParaTest workers (4 by default, 8 in CI). Each worker uses its
own database `azguard_<N>_test` (`tests/Support/TestDatabase.php`), so workers never drop each other's tables; the
compose stand creates 16 of them on first start (`TEST_DATABASE_WORKERS`, `docker/initdb`). On an existing server
create one database per worker yourself (a missing one fails with the exact statement to run). More than about 8
workers gain little: the longest file bounds the run and every worker slows the others. The engine tests (races on
one database, replication) never run in parallel: `composer test:db -- <driver> --engines`.

The database lane runs parity seeds 1 and 2 (`PARITY_SEEDS`); `--full` runs all eight, as nightly CI does. The
guarded ledger of unsupported cases is compared only by a run with every seed, which the SQLite lane is.

### Infrastructure measurement (2026-10-12)

Point runs on the stand (PostgreSQL 16), before and after; the full-suite numbers are from the earlier junit
reports (`var/perf/pgsql-seq.xml`: 5011 tests, 1938 s sequential) and are not re-measured.

| What | Before | After |
|---|---:|---:|
| `DirectWritesTest` on PostgreSQL | 590 tests, 290 s | 199 tests, 8.6 s |
| `DirectWritesTest` on SQLite | 590 tests, 28 s | 199 tests, ≈4 s |
| `Scale*Test` on SQLite, without R58 | — | 2 tests, 8 s |
| Parity on PostgreSQL, seeds 1 and 2 | seeds 1-8, ≈78 s | 40 s |
| `test:changed` on the `bench` zone | all 5083 tests | 164 tests, 64 s |

The 590 → 199 tests are the same method × kind × environment cases: the refusal before SQL is a matrix without a
schema, and one test runs every method and kind in `local`, `testing` and `production` against real rows.

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
  there is no role-definition table or DB-only role.

## Git workflow

Branch from `main` (`feat/…`, `fix/…`), keep the suite green (`composer check`),
and open a Pull Request to `main`. Commits follow Conventional Commits.
