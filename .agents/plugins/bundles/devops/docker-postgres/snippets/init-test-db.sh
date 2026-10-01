#!/bin/sh
# Source: anonymized production Laravel project
#
# OPTION 1 - init-script: docker/postgres/init/01-test-db.sh
# Mounted in /docker-entrypoint-initdb.d (:ro) and creates a second database <app>_test —
# test isolation foundation (phpunit.xml: DB_DATABASE=<app>_test).
# IMPORTANT: only executed the FIRST time the volume is created pgsql_data.
set -eu

psql -v ON_ERROR_STOP=1 \
  --username "${POSTGRES_USER}" \
  --dbname "${POSTGRES_DB}" \
  -c "CREATE DATABASE <app>_test OWNER \"${POSTGRES_USER}\";"

# ---------------------------------------------------------------------------
# OPTION 2 - backfill for ALREADY existing volume: docker/postgres/create-test-db.sh
# Init above did not run (volume is not empty) — create a database in a running container.
# Idempotent: if there is a base, 0 is output. Called make-target db-create-test:
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
