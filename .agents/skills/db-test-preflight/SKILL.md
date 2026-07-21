---
name: db-test-preflight
bucket: devops
version: 0.1.0
description: "Pre-flight check of test DB (Postgres in Docker) BEFORE running tests: container up (docker compose ps / pg_isready), target DB exists and its name ends with _test (not dev/prod), migrations applied; distinguishes host vs docker connection. Activate when running Postgres tests, wiring hooks/CI, or when 'tests fail on DB connection'."
risk: read
persona: operator
tags: [postgres, docker, testing, preflight, devops, ci, isolation]
requires: [docker-postgres]
produces_for: [laravel-testing, test-isolation-guard]
outputs: []
snippets: [preflight.sh, Makefile.test, composer-scripts.json]
adapters: [claude, cursor, fable]
sha256: ""
---

# DB Test Preflight

Use before any Postgres test suite (`php artisan test`, Pest, PHPUnit, `make test`) and when wiring hooks/CI that hit a real DB in Docker. Analog of `devops/node-pnpm-preflight` for the test DB.

Triggers:
- running Postgres tests (`php artisan test`, `pest`, `phpunit`, `make test`);
- wiring git hook (`pre-push`), composer `test` script, Makefile target, or pre-test CI step;
- symptom "tests fail on DB connection": `SQLSTATE[08006]` (connection refused), `database "*_test" does not exist`, `no such host`, suite-start timeout.

## Algorithm

1. **Determine connection mode — host or docker.** Run checks the SAME way tests will run.
   - **docker**: app and Postgres both in compose network; DB reachable by service name (`pgsql`/`db`); run checks via `docker compose exec -T pgsql ...`.
   - **host**: app on host, Postgres published on `127.0.0.1:<host-port>` (from `.env.docker` / `DB_PORT`); run checks via host `pg_isready -h 127.0.0.1 -p <port>`.
   - docker-mode marker: test-run commands in Makefile/CI start with `docker compose exec`.
2. **Container up.** `docker compose ps` (with required `--env-file`); service `pgsql`/`db` must be `running`/`healthy`. If absent — `docker compose up -d` the DB service, retry. If it won't come up — stop, report blocker, do NOT run tests.
3. **Postgres ready** — `pg_isready` (container can be `running` while Postgres still initializing):
   - docker: `docker compose exec -T pgsql sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'`;
   - host: `pg_isready -h 127.0.0.1 -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}"`.
   - exit `0` = ready.
4. **Get target test DB name from the TEST env, not `.env`.** SSOT: `phpunit.xml` (`<env name="DB_DATABASE" value="..._test" force="true"/>`) and/or `tests/bootstrap.php` (forced `DB_DATABASE`/`APP_ENV=testing`). Do NOT take `DB_DATABASE` from `.env` (dev DB).
5. **GATE on DB name: MUST end with `_test`.** Main guard against running tests (esp. `RefreshDatabase`/`migrate:fresh`, which DROP tables) on dev/prod. If target name does not match `*_test` — HARD STOP, report blocker, do not run. Regex: `^[a-z0-9_]+_test$`.
6. **`*_test` DB exists.** `psql ... -Atqc "SELECT 1 FROM pg_database WHERE datname='<db>_test'"` must return `1`. If missing (fresh volume, no init script) — create idempotently: `CREATE DATABASE <app>_test OWNER "<user>"` (or call existing `make db-create-test` / project init script). Do NOT create or touch dev/prod DB.
7. **Migrations applied to `*_test`.** `RefreshDatabase` migrates itself, but without refresh (or for quick "schema fails") verify: `php artisan migrate:status --env=testing` must show no `Pending`. If pending — `php artisan migrate --env=testing --force` (docker: via `docker compose exec app ...`). Never migrate a non-`_test` DB as if it were the test DB.
8. **Run tests only after all gates pass**, same way (host/docker) as checks. Any failure of steps 2–7 = blocker: report cause + fix command, do not run tests.

## Host vs Docker

| Check | docker | host |
|:---|:---|:---|
| Container up | `docker compose ps` | `docker compose ps` |
| Postgres ready | `docker compose exec -T pgsql sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'` | `pg_isready -h 127.0.0.1 -p $DB_PORT -U $DB_USERNAME` |
| App DB host | service name (`pgsql`) | `127.0.0.1` + published port |
| `*_test` exists | `docker compose exec -T pgsql psql -U "$POSTGRES_USER" -d postgres -Atqc "..."` | `psql -h 127.0.0.1 -p $DB_PORT -U $DB_USERNAME -d postgres -Atqc "..."` |
| Run tests | `docker compose exec app php artisan test` | `php artisan test` |

Checks and tests use the SAME mode. Never mix (check on host, tests in container) — different DB endpoints.

## Do not confuse test DB with dev/prod

- Test DB name source = `phpunit.xml` + `tests/bootstrap.php` (`force="true"`, `APP_ENV=testing`), not `.env`.
- Commands outside test env (`tinker`, `migrate`, `db:seed` without `--env=testing`) use `DB_*` from `.env` → write to dev DB. For test mutations: only explicit `--env=testing` or a separate `*_test` DB.
- Gate `^[a-z0-9_]+_test$` (step 5) is the last line: even on misconfigured order it stops `RefreshDatabase` from wiping dev/prod schema.

## Which snippet

| Situation | File |
|:---|:---|
| Ready pre-flight shell script: ps → pg_isready → name gate `*_test` → DB exists → migrations, host/docker | `snippets/preflight.sh` |
| Wire pre-flight into Makefile: target `test-preflight` as dependency of `test` | `snippets/Makefile.test` |
| Wire pre-flight into composer `test` script and git hook `pre-push` | `snippets/composer-scripts.json` |

## Quality checklist

- [ ] Connection mode (host/docker) determined; checks and tests run identically
- [ ] `docker compose ps` shows DB container `running`/`healthy`
- [ ] `pg_isready` returned `0`
- [ ] Target DB name taken from `phpunit.xml`/`tests/bootstrap.php`, not `.env`
- [ ] Target DB name passes gate `^[a-z0-9_]+_test$` (hard stop otherwise)
- [ ] `*_test` DB exists (or created idempotently, dev/prod untouched)
- [ ] Migrations applied to `*_test` (no `Pending`) or `RefreshDatabase` covers it
- [ ] Pre-flight wired before `test` in Makefile/composer/git hook; on failure — blocker, no run
- [ ] dev/prod DB never created or migrated as the test DB

## Links

- snippets/preflight.sh
- snippets/Makefile.test
- snippets/composer-scripts.json
- Related: `devops/docker-postgres` (init script creates `<app>_test`), `devops/makefile` (target `db-create-test`), `devops/node-pnpm-preflight` (Node/pnpm preflight analog), `laravel-testing/test-isolation-guard` (bootstrap.php isolation guard), `laravel-testing/laravel-testing` (test patterns over isolated DB)

<!-- ru-source-sha256: 0e0a4200aaf39492b75b611286e31b5bb8baf6a717cacf648501b2b34f77a35f -->
