<!-- Source: anonymized production Laravel project -->
# Pest-testing

## Read first

1. **`testing-rules`** — database isolation `{{project_name}}_test`, Docker vs local PHP, Dusk, prohibition of dangerous commands outside testing.
2. Test type: for HTTP, API and access scripts - Feature (`tests/Feature/`); for the domain model and Actions — often `tests/Feature/Document/`; pure logic without a database - Unit (`tests/Unit/`).

## Agent operation scenario

1. Pre-flight: containers and databases for tests - see. **`testing-rules`** and cursor rule test-preflight (Docker `docker compose ps`, then run from the same environment as the tests).
2. Open modified classes and, if necessary, factories.
3. Running only the necessary tests with a filter: `php artisan test --filter=...` or via Docker from table to **`testing-rules`**.

## Project Expectations

- Default Feature-tests unless there is a clear reason to limit Unit.
- Data via model factories.
- Behavior Statements (HTTP-code, redirect, database state, notifications), and not about the internal implementation.

## Commands

First determine the environment (**`testing-rules`**): when **`DB_HOST=pgsql`** execute commands in the container **`app`**.

**Local PHP:**

```bash
php artisan test
./vendor/bin/pest
```

**Docker:**

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pest
```

Tests always use the database **`{{project_name}}_test`** (`phpunit.xml`), not the main one `.env`.
