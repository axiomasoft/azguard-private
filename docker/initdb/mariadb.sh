#!/bin/sh
# One database per ParaTest worker: azguard_1_test ... azguard_<N>_test next to $MARIADB_DATABASE (tests/Support/TestDatabase.php).
# Runs once on an empty data directory, so after every container (re)creation: the data lives on tmpfs.
set -eu
workers="${TEST_DATABASE_WORKERS:-16}"
base="${MARIADB_DATABASE%_test}"
i=1
while [ "$i" -le "$workers" ]; do
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS ${base}_${i}_test; GRANT ALL ON ${base}_${i}_test.* TO '$MARIADB_USER'@'%';"
    i=$((i + 1))
done
