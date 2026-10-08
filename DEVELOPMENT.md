# AzGuard — Development

## State of 1.0

The repository is being rebuilt as AzGuard 1.0 (PLAN2). The 0.3 code is frozen and is not part of any build.

| Path | What it is |
|---|---|
| `packages/core` | `axiomasoft/azguard`, namespace `AzGuard\` (1.0 code, grows phase by phase) |
| `packages/filament` | `axiomasoft/azguard-filament`, namespace `AzGuard\Filament\` |
| `tests/` | Pest suites `Arch`, `Unit`, `Feature`, `Regression` on Orchestra Testbench |

The 0.3 reference tree was removed in PLAN2.P6.9; read it with `git show 491980a:legacy/0.3/<path>`. The `context` package is not
recreated; its behavior moves into the core package. Package documentation under `docs/` still describes 0.3
until it is rewritten in P8.2.

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
| `composer analyse` | PHPStan / Larastan | Static analysis (level 8, no baseline) |
| `composer lint` / `lint:check` | Pint | Fix / check code style |
| `composer refactor` / `refactor:check` | Rector | Apply / preview refactorings |
| `composer mutate` | Pest mutate | Per-package mutation testing |
| `composer check` | — | Run every CI gate (style + analysis + refactor + types + tests) |
| `composer fix` | — | Auto-fix style and apply refactorings |

Feature tests use an in-memory SQLite database, so the `pdo_sqlite` /
`sqlite3` PHP extensions must be enabled.

`composer test:parallel` keeps Pest's random execution order and passes the
same 1G memory limit to every ParaTest worker. It is intentionally limited to
the SQLite lane; run PostgreSQL and MySQL lanes sequentially with their
dedicated commands below.

## Mutation-ratchet policy

Pest's native mutator reports one covered mutation score per package. Raise a
blocking threshold only from a fresh, successful Xdebug measurement: each new
threshold is `floor(measured score) - 2` and must never be below the previous
threshold. Do not lower a threshold to make a red gate pass. Exclusions live
next to the package settings in `bin/mutation-gate.sh`, carry an inline
rationale, and are reviewed as code.

P4.5 measured 100.00% for core, filament, and context. A later Xdebug run on
2026-09-24 still measured 100.00%, so the native Pest gates now enforce 99%.
Line coverage on that run was 87.1%, so `composer check:coverage` and the CI
coverage job enforce `--min=85` (`floor(87.1) - 2`). Local runs use PATH `php`,
then `php8.4`, and skip only when neither binary has pcov/Xdebug; CI supplies
Xdebug and remains blocking. Evidence is in
`plans/archive/2026.07.18-AZGUARD-STABLE/artifacts/P4-mutation-baseline.md`.

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
(MySQL 8), `mariadb` (MariaDB 10.11), `redis` (Redis 7) — each with a healthcheck
and a named volume for its data. The `authority-replica` profile adds an isolated
Postgres primary and replica for the [replica qualification](tests/Engines/Support/replica-README.md). Ports
are published on `127.0.0.1` only; credentials come from `.env`, never
hardcoded in the compose file. The database names default to `azguard_test`
(`.env.example`), keeping the invariant that test database names end in `_test`.

With the stand up, run the suite against a real engine via `composer
test:pgsql` / `composer test:mysql` — these switch `DB_CONNECTION` and
otherwise share `tests/TestCase.php`'s env-driven connection config with the
sqlite default (`composer test`). CI runs sqlite (`tests.yml` main job), the PG/MySQL matrix (`test-db-matrix`
job), and a dedicated Redis lane (`test-redis`) on every push/PR. **All three
lanes are required for merge** — sqlite alone self-skips database-specific code,
and array-store tests do not prove cross-process Redis lock behaviour.

With Redis up (`make up`), run the qualification bundle:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT="${REDIS_PORT:-6379}" composer test:redis
```

The lane uses `--fail-on-skipped`; missing `ext-redis` or an unreachable service
is a hard failure, not a silent pass.

## Conventions

- `declare(strict_types=1)` in every PHP file; PHPStan level 8; Pest 4.
- Permission references support enum cases and validated names; roles are PHP
  classes with stable keys declared by `#[Role]` or `key()`.
- Role definitions live in the panel catalog. The database stores assignments
  in `role_grants`, partitioned by panel, tenant, subject, scope and origin;
  there is no role-definition table or DB-only role in 1.0.

## Git workflow

Branch from `main` (`feat/…`, `fix/…`), keep the suite green (`composer check`),
and open a Pull Request to `main`. Commits follow Conventional Commits.
