# Source: anonymized production project
#!/usr/bin/env bash
set -euo pipefail

# Pre-flight test database (Postgres in Docker) BEFORE running tests.
# Gates: container raised -> pg_isready -> database name *_test -> DB exists -> migrations.
# Strictly protects against running tests on dev/prod database.
#
# Usage (from the repository root):
#   bash bin/db-test-preflight.sh            # docker-mode (default)
#   MODE=host bash bin/db-test-preflight.sh  # host-mode (Postgres on 127.0.0.1:DB_PORT)
#
# We take the name of the test database from the test environment, and NOT from .env (there dev-base).

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

MODE="${MODE:-docker}"          # docker | host
DB_SERVICE="${DB_SERVICE:-pgsql}"
APP_SERVICE="${APP_SERVICE:-app}"

die() { echo "PREFLIGHT BLOCKER: $*" >&2; exit 1; }

# --- 1. compose with the necessary env-files (docker-mode) ---
compose() {
  local args=(docker compose --env-file .env)
  [[ -f .env.docker ]] && args+=(--env-file .env.docker)
  "${args[@]}" "$@"
}

# --- 2. name of target test database from phpunit.xml (source of truth - test environment) ---
TEST_DB="${TEST_DB:-}"
if [[ -z "${TEST_DB}" && -f phpunit.xml ]]; then
  TEST_DB="$(grep -oP '<env name="DB_DATABASE" value="\K[^"]+' phpunit.xml | head -n1 || true)"
fi
[[ -n "${TEST_DB}" ]] || die "could not determine the name of the test database (phpunit.xml / TEST_DB)"

# --- 3. GATE: name must end with _test ---
if [[ ! "${TEST_DB}" =~ ^[a-z0-9_]+_test$ ]]; then
  die "target database '${TEST_DB}' does not end with _test — refusal to run tests on dev/prod"
fi
echo "preflight: test database = ${TEST_DB}"

# --- 4. host- vs docker-checks ---
if [[ "${MODE}" == "host" ]]; then
  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="${DB_PORT:-5432}"
  DB_USER="${DB_USERNAME:-postgres}"

  pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" >/dev/null \
    || die "Postgres not ready for ${DB_HOST}:${DB_PORT} (host-mode)"

  exists="$(PGPASSWORD="${DB_PASSWORD:-}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" \
      -d postgres -Atqc "SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'" || true)"
else
  # container up?
  state="$(compose ps --status running --services 2>/dev/null | grep -Fx "${DB_SERVICE}" || true)"
  [[ -n "${state}" ]] || die "database container '${DB_SERVICE}' not running — do Is 'docker compose up -d ${DB_SERVICE}'"

  # Postgres accepting connections?
  compose exec -T "${DB_SERVICE}" sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null \
    || die "Postgres inside '${DB_SERVICE}' not ready yet (pg_isready != 0)"

  exists="$(compose exec -T "${DB_SERVICE}" sh -lc \
    "psql -U \"\$POSTGRES_USER\" -d postgres -Atqc \"SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'\"" \
    | tr -d '[:space:]' || true)"
fi

# --- 5. Does the database exist? create idempotently (only *_test, dev/prod do not touch) ---
if [[ "${exists}" != "1" ]]; then
  echo "preflight: '${TEST_DB}' is missing - I create it idempotently"
  if [[ "${MODE}" == "host" ]]; then
    PGPASSWORD="${DB_PASSWORD:-}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" \
      -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${TEST_DB} OWNER \"${DB_USER}\";"
  else
    compose exec -T "${DB_SERVICE}" sh -lc \
      "psql -U \"\$POSTGRES_USER\" -d postgres -v ON_ERROR_STOP=1 -c \"CREATE DATABASE ${TEST_DB} OWNER \\\"\$POSTGRES_USER\\\";\""
  fi
fi

# --- 6. migrations applied? (pass through SKIP_MIGRATE=1, if strategy RefreshDatabase) ---
if [[ "${SKIP_MIGRATE:-0}" != "1" ]]; then
  if [[ "${MODE}" == "host" ]]; then
    php artisan migrate:status --env=testing >/dev/null 2>&1 \
      && php artisan migrate --env=testing --force >/dev/null \
      || echo "preflight: migrate missing/not applicable (host)"
  else
    compose exec -T "${APP_SERVICE}" php artisan migrate --env=testing --force >/dev/null \
      || echo "preflight: migrate missing/not applicable (docker)"
  fi
fi

echo "preflight: OK — DB '${TEST_DB}' is ready, you can run tests"
