<!-- Source: anonymized production Laravel project -->
# Test environment security in {{project_name}}

## How insulation works

B {{project_name}} tests use a separate PostgreSQL database **`{{project_name}}_test`**. The main working base is set in `.env` like **`DB_DATABASE`** (often `{{project_name}}`).

- `phpunit.xml` and `tests/bootstrap.php` is forced **`DB_DATABASE={{project_name}}_test`** (and `APP_ENV=testing`).
- **`DB_HOST` / port / user / password** are inherited from the environment: in Docker is the same host **`pgsql`**, same as the application; locally - **`127.0.0.1`** and values from `.env`.
- `RefreshDatabase` starts `migrate:fresh` **only** on connection with the database **`{{project_name}}_test`**.
- Working base (`{{project_name}}` etc.) **should not** used by tests; falls when violated `tests/TestCase::assertIsolatedTestDatabase`.

## Two bases in Docker

In the container Postgres are created **`DB_DATABASE`** (from `.env`) and **`{{project_name}}_test`** (init-script `docker/postgres/init/01-{{project_name}}-test.sh` when **first** volume creation). Old volume without `{{project_name}}_test`: see `docs/docker/development.md` or `docs/docker/troubleshooting.md`.

## Pre-launch check

**Local (Postgres on host):**

```bash
# Make sure the test database exists (substitute user/port from .env)
psql -h 127.0.0.1 -p 5432 -U {{project_name}} -d postgres -c "\l" | grep {{project_name}}_test
```

**Docker:**

```bash
docker compose exec pgsql psql -U {{project_name}} -d postgres -c "\l" | grep {{project_name}}_test
```

If not, create:

```bash
# Docker (from repository root, container pgsql started)
make db-create-test
# or: bash docker/postgres/create-{{project_name}}-test-db.sh

# Host (Postgres without Docker)
psql -h 127.0.0.1 -U {{project_name}} -d postgres -c 'CREATE DATABASE {{project_name}}_test OWNER {{project_name}};'
```

## Running tests

**Host:**

```bash
php artisan test
php artisan test tests/Feature/Document/
php artisan test --filter=DocumentActionsTest
php artisan dusk
```

**Docker:**

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test tests/Feature/Document/
docker compose exec app php artisan test --filter=DocumentActionsTest
# Dusk — if configured in the image:
docker compose exec app php artisan dusk
```

## What NOT to do

- Change `phpunit.xml` so that the tests go to the main `DB_DATABASE` from working `.env`.
- Remove check `assertIsolatedTestDatabase` without replacement by another warranty.
- Run `migrate:fresh` manually against production DB without explicit intent (tests are done fresh only on `{{project_name}}_test`).
- Run **`tinker`**, **`db:seed`**, MCP with mutations without explicit translation into **`{{project_name}}_test`** — see **`testing-rules`**, section «DB isolation: holes out PHPUnit/Pest».

## Mode Docker vs host

See skill **`testing-rules`**: landmark **`DB_HOST=pgsql`** (Docker) vs **`127.0.0.1`** / IP (locally).
