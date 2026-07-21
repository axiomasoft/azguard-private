#!/bin/sh
# Source: anonymized production Laravel project
#
# ВАРИАНТ 1 — init-скрипт: docker/postgres/init/01-test-db.sh
# Монтируется в /docker-entrypoint-initdb.d (:ro) и создаёт вторую БД <app>_test —
# фундамент изоляции тестов (phpunit.xml: DB_DATABASE=<app>_test).
# ВАЖНО: выполняется только при ПЕРВОМ создании тома pgsql_data.
set -eu

psql -v ON_ERROR_STOP=1 \
  --username "${POSTGRES_USER}" \
  --dbname "${POSTGRES_DB}" \
  -c "CREATE DATABASE <app>_test OWNER \"${POSTGRES_USER}\";"

# ---------------------------------------------------------------------------
# ВАРИАНТ 2 — backfill для УЖЕ существующего volume: docker/postgres/create-test-db.sh
# Init выше не запускался (том не пустой) — создаём базу в работающем контейнере.
# Идемпотентно: если база есть, выходит 0. Вызывается make-целью db-create-test:
#   bash docker/postgres/create-test-db.sh        # dev
#   bash docker/postgres/create-test-db.sh prod   # docker-compose.prod.yml
#
# #!/usr/bin/env bash
# set -euo pipefail
#
# mode="${1:-dev}"
# if [[ "${mode}" == "prod" ]]; then
#   COMPOSE=(docker compose -f docker-compose.prod.yml)
# else
#   COMPOSE=(docker compose)
# fi
#
# "${COMPOSE[@]}" exec -T pgsql sh -lc '
# set -eu
# if psql -U "$POSTGRES_USER" -d postgres -Atq -c "SELECT 1 FROM pg_database WHERE datname = '\''<app>_test'\''" | grep -qx 1; then
#   echo "<app>_test already exists — nothing to do."
#   exit 0
# fi
# psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 \
#   -c "CREATE DATABASE <app>_test OWNER \"${POSTGRES_USER}\";"
# echo "Created database <app>_test."
# '
