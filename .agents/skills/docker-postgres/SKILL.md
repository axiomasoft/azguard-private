---
name: docker-postgres
bucket: devops
version: 0.3.0
description: "PostgreSQL in Docker: pg_isready healthcheck, named volume for data, init script creating test DB <app>_test for test isolation"
risk: write
persona: operator
tags: ["docker", "devops", "postgres", "testing"]
requires: []
produces_for: ["docker-dev-prod", "docker-services", "db-test-preflight"]
outputs: ["docker-compose.yml (pgsql service)", "docker/postgres/init/01-test-db.sh"]
snippets: ["postgres.conf", "init.sql", "pgsql-service.yml", "init-test-db.sh"]
adapters: [claude, cursor, fable]
sha256: ""
---

## Context

Service `pgsql` (`postgres:16-alpine`) for Laravel stack:

- **healthcheck `pg_isready`** — other services start only after DB ready (`depends_on: pgsql: { condition: service_healthy }`); in compose test escape `$$POSTGRES_USER`.
- **named volume** `pgsql_data:/var/lib/postgresql/data` — data survives container recreation.
- **Second DB `<app>_test`** via init script in `/docker-entrypoint-initdb.d/` (test isolation foundation). Pest/PHPUnit (via `phpunit.xml: DB_DATABASE=<app>_test`) run `migrate:fresh` in separate DB, never touching dev data.

Caveat: scripts in `/docker-entrypoint-initdb.d/` run **only on first volume creation**. If volume `pgsql_data` already exists, init silently skips. For that case use make target `db-create-test` — idempotently creates DB in running container (see `init-test-db.sh` backfill mode, skill `makefile`).

## Algorithm

1. Add `pgsql` service per `pgsql-service.yml`: image, healthcheck, named volume, port on `127.0.0.1` (not exposed).
2. Mount init scripts dir: `./docker/postgres/init:/docker-entrypoint-initdb.d:ro`.
3. Place `docker/postgres/init/01-test-db.sh` (from `init-test-db.sh`) — creates `<app>_test` owned by `${POSTGRES_USER}`.
4. Add make target `db-create-test` for existing volumes (idempotent psql into container).
5. In `phpunit.xml` set `DB_DATABASE=<app>_test`; ensure tests use `DB_HOST=pgsql`.
6. Tuning → `postgres.conf`; extensions → `init.sql`.

## Snippet selection

| Situation | File |
|---|---|
| pgsql service in compose (healthcheck, volume, init-mount) | `snippets/pgsql-service.yml` |
| Test DB `<app>_test` (init + backfill for old volume) | `snippets/init-test-db.sh` |
| Extensions/SQL at init | `snippets/init.sql` |
| PostgreSQL param tuning | `snippets/postgres.conf` |

## Quality checklist

- [ ] healthcheck via `pg_isready -U $$POSTGRES_USER -d $$POSTGRES_DB`
- [ ] data in named volume, not in container, not bind-mount
- [ ] port published only on `127.0.0.1` (external access — tunnel/exec)
- [ ] init dir mounted `:ro`, scripts `set -eu` + `ON_ERROR_STOP=1`
- [ ] test DB `<app>_test` created by init script; for existing volume `make db-create-test` exists
- [ ] DB consumers use `depends_on … condition: service_healthy`

## Links

- https://hub.docker.com/_/postgres — Initialization scripts section (`/docker-entrypoint-initdb.d`)
- https://www.postgresql.org/docs/current/app-pg-isready.html — pg_isready
- Related skills: `docker-services` (depends_on patterns), `docker-dev-prod` (dev/prod compose layout), `makefile` (db-create-test target), `db-test-preflight` (check `<app>_test` before test run), `laravel-testing/test-isolation-guard` (test DB isolation guard), `laravel-testing/laravel-testing` (tests on `<app>_test`)

<!-- ru-source-sha256: 26171c63271e9b7cecda199654490b1944e3e43e1fd5f6249ac2f47b48250577 -->
