#!/bin/sh
# One database per ParaTest worker: azguard_1_test ... azguard_<N>_test next to $POSTGRES_DB (tests/Support/TestDatabase.php).
# Runs once on an empty data directory, so after every container (re)creation: the data lives on tmpfs.
set -eu
workers="${TEST_DATABASE_WORKERS:-16}"
base="${POSTGRES_DB%_test}"
i=1
while [ "$i" -le "$workers" ]; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE ${base}_${i}_test OWNER \"$POSTGRES_USER\""
    i=$((i + 1))
done
