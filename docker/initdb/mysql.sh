#!/bin/sh
# One database per ParaTest worker: azguard_1_test ... azguard_<N>_test next to $MYSQL_DATABASE (tests/Support/TestDatabase.php).
# Runs once on an empty data directory, so after every container (re)creation: the data lives on tmpfs.
set -eu
workers="${TEST_DATABASE_WORKERS:-16}"
base="${MYSQL_DATABASE%_test}"
i=1
while [ "$i" -le "$workers" ]; do
    mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS ${base}_${i}_test; GRANT ALL ON ${base}_${i}_test.* TO '$MYSQL_USER'@'%';"
    i=$((i + 1))
done
