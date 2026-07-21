# Source: anonymized production project
#!/usr/bin/env bash
set -euo pipefail

# Пре-флайт тестовой БД (Postgres в Docker) ПЕРЕД запуском тестов.
# Гейты: контейнер поднят -> pg_isready -> имя БД *_test -> БД существует -> миграции.
# Жёстко защищает от прогона тестов по dev/prod базе.
#
# Использование (из корня репозитория):
#   bash bin/db-test-preflight.sh            # docker-режим (по умолчанию)
#   MODE=host bash bin/db-test-preflight.sh  # host-режим (Postgres на 127.0.0.1:DB_PORT)
#
# Имя тестовой БД берём из тестового окружения, а НЕ из .env (там dev-база).

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

MODE="${MODE:-docker}"          # docker | host
DB_SERVICE="${DB_SERVICE:-pgsql}"
APP_SERVICE="${APP_SERVICE:-app}"

die() { echo "PREFLIGHT BLOCKER: $*" >&2; exit 1; }

# --- 1. compose с нужными env-файлами (docker-режим) ---
compose() {
  local args=(docker compose --env-file .env)
  [[ -f .env.docker ]] && args+=(--env-file .env.docker)
  "${args[@]}" "$@"
}

# --- 2. имя целевой тестовой БД из phpunit.xml (источник истины — тест-окружение) ---
TEST_DB="${TEST_DB:-}"
if [[ -z "${TEST_DB}" && -f phpunit.xml ]]; then
  TEST_DB="$(grep -oP '<env name="DB_DATABASE" value="\K[^"]+' phpunit.xml | head -n1 || true)"
fi
[[ -n "${TEST_DB}" ]] || die "не удалось определить имя тестовой БД (phpunit.xml / TEST_DB)"

# --- 3. ГЕЙТ: имя обязано оканчиваться на _test ---
if [[ ! "${TEST_DB}" =~ ^[a-z0-9_]+_test$ ]]; then
  die "целевая БД '${TEST_DB}' не оканчивается на _test — отказ запускать тесты по dev/prod"
fi
echo "preflight: тестовая БД = ${TEST_DB}"

# --- 4. host- vs docker-проверки ---
if [[ "${MODE}" == "host" ]]; then
  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="${DB_PORT:-5432}"
  DB_USER="${DB_USERNAME:-postgres}"

  pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" >/dev/null \
    || die "Postgres не готов на ${DB_HOST}:${DB_PORT} (host-режим)"

  exists="$(PGPASSWORD="${DB_PASSWORD:-}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" \
      -d postgres -Atqc "SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'" || true)"
else
  # контейнер поднят?
  state="$(compose ps --status running --services 2>/dev/null | grep -Fx "${DB_SERVICE}" || true)"
  [[ -n "${state}" ]] || die "контейнер БД '${DB_SERVICE}' не running — выполните 'docker compose up -d ${DB_SERVICE}'"

  # Postgres принимает соединения?
  compose exec -T "${DB_SERVICE}" sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null \
    || die "Postgres внутри '${DB_SERVICE}' ещё не готов (pg_isready != 0)"

  exists="$(compose exec -T "${DB_SERVICE}" sh -lc \
    "psql -U \"\$POSTGRES_USER\" -d postgres -Atqc \"SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'\"" \
    | tr -d '[:space:]' || true)"
fi

# --- 5. БД существует? создаём идемпотентно (только *_test, dev/prod не трогаем) ---
if [[ "${exists}" != "1" ]]; then
  echo "preflight: '${TEST_DB}' отсутствует — создаю идемпотентно"
  if [[ "${MODE}" == "host" ]]; then
    PGPASSWORD="${DB_PASSWORD:-}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" \
      -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${TEST_DB} OWNER \"${DB_USER}\";"
  else
    compose exec -T "${DB_SERVICE}" sh -lc \
      "psql -U \"\$POSTGRES_USER\" -d postgres -v ON_ERROR_STOP=1 -c \"CREATE DATABASE ${TEST_DB} OWNER \\\"\$POSTGRES_USER\\\";\""
  fi
fi

# --- 6. миграции применены? (пропустить через SKIP_MIGRATE=1, если стратегия RefreshDatabase) ---
if [[ "${SKIP_MIGRATE:-0}" != "1" ]]; then
  if [[ "${MODE}" == "host" ]]; then
    php artisan migrate:status --env=testing >/dev/null 2>&1 \
      && php artisan migrate --env=testing --force >/dev/null \
      || echo "preflight: migrate пропущен/неприменим (host)"
  else
    compose exec -T "${APP_SERVICE}" php artisan migrate --env=testing --force >/dev/null \
      || echo "preflight: migrate пропущен/неприменим (docker)"
  fi
fi

echo "preflight: OK — БД '${TEST_DB}' готова, можно запускать тесты"
