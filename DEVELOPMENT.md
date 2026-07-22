# AzGuard — Development

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
    "axioma-studio/azguard-core": "@dev"
}
```

Mount or symlink the package directory into the app, then `composer update`.

## Quality commands

| Command | Tool | Description |
|---|---|---|
| `composer test` | Pest | Run the test suite |
| `composer test:parallel` | Pest / ParaTest | Run the SQLite suite in parallel with random order intact |
| `composer test:types` | Pest | Type-coverage gate (min 98%) |
| `composer analyse` | PHPStan / Larastan | Static analysis (level 6) |
| `composer lint` / `lint:check` | Pint | Fix / check code style |
| `composer refactor` / `refactor:check` | Rector | Apply / preview refactorings |
| `composer mutate` | Infection | Mutation testing |
| `composer check` | — | Run every CI gate (style + analysis + refactor + types + tests) |
| `composer fix` | — | Auto-fix style and apply refactorings |

Feature tests use an in-memory SQLite database, so the `pdo_sqlite` /
`sqlite3` PHP extensions must be enabled.

`composer test:parallel` keeps Pest's random execution order and passes the
same 1G memory limit to every ParaTest worker. It is intentionally limited to
the SQLite lane; run PostgreSQL and MySQL lanes sequentially with their
dedicated commands below.

## Mutation-ratchet policy

Raise `minMsi` and `minCoveredMsi` only from a fresh, successful per-package
mutation measurement: each new threshold is `floor(measured score) - 2` and
must never be below its previous value. Do not change a threshold to make a
red gate pass. Exclusions require an inline rationale and are reviewed as code.

At P4.5, Infection 0.34 cannot reconcile Pest 4 JUnit identifiers with its
coverage trace, so no valid score exists and the thresholds remain unchanged.
The advisory workflow's green job does not override a failed Infection step;
see `plans/2026.07.18-AZGUARD-STABLE/artifacts/P4-mutation-baseline.md`.

## Local database matrix

`composer test` runs against SQLite `:memory:` by default. To exercise the
package against real database engines (Postgres 16, MySQL 8) and Redis, bring
up the local stand:

```bash
cp .env.example .env   # adjust credentials if needed
make up                # docker compose up -d, waits for services to report healthy
make ps                # check status
make down               # stop and remove the stand
```

`docker-compose.yml` defines three services — `pgsql` (Postgres 16), `mysql`
(MySQL 8), `redis` (Redis 7) — each with a healthcheck (`pg_isready` /
`mysqladmin ping` / `redis-cli ping`) and a named volume for its data. Ports
are published on `127.0.0.1` only; credentials come from `.env`, never
hardcoded in the compose file. The database names default to `azguard_test`
(`.env.example`), keeping the invariant that test databases carry the `test`
substring.

With the stand up, run the suite against a real engine via `composer
test:pgsql` / `composer test:mysql` — these switch `DB_CONNECTION` and
otherwise share `tests/TestCase.php`'s env-driven connection config with the
sqlite default (`composer test`). CI runs sqlite (`tests.yml` main job) and
the PG/MySQL matrix (`test-db-matrix` job) on every push/PR; **both lanes are
required for merge** — the sqlite lane alone self-skips database-specific
code (collation, cross-process locking), so a PG/MySQL regression is only
visible in the real-database lane.

## Conventions

- `declare(strict_types=1)` in every PHP file; PHPStan level 6; Pest 4.
- Permissions and roles are referenced by **enums and classes**, never magic
  strings (see the docs).
- Role contract: `roles.name` holds a slug (`admin`), `roles.class_name` holds
  the FQCN of the PHP role class (`App\Guards\App\Roles\AdminRole`).

## Git workflow

Branch from `main` (`feat/…`, `fix/…`), keep the suite green (`composer check`),
and open a Pull Request to `main`. Commits follow Conventional Commits.
