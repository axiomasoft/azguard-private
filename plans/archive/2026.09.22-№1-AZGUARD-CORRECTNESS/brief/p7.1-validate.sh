#!/usr/bin/env bash
# P7.1 qualification gate — run from repo root.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

echo "== composer validate =="
composer validate --strict
for pkg in packages/core packages/filament packages/context; do
  composer validate --strict --no-check-lock --working-dir="$pkg"
done

echo "== isolated SQL targets =="
export PGSQL_HOST="${PGSQL_HOST:-127.0.0.1}" PGSQL_PORT="${PGSQL_PORT:-25432}"
export PGSQL_DATABASE="${PGSQL_DATABASE:-azguard_test}" PGSQL_USERNAME="${PGSQL_USERNAME:-azguard}" PGSQL_PASSWORD="${PGSQL_PASSWORD:-azguard}"
export MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}" MYSQL_PORT="${MYSQL_PORT:-23306}"
export MYSQL_DATABASE="${MYSQL_DATABASE:-azguard_test}" MYSQL_USERNAME="${MYSQL_USERNAME:-azguard}" MYSQL_PASSWORD="${MYSQL_PASSWORD:-azguard}"
php <<'PHP'
<?php
foreach (['pgsql' => 'PGSQL', 'mysql' => 'MYSQL'] as $driver => $prefix) {
    $database = getenv($prefix.'_DATABASE');
    if (! preg_match('/^[a-z0-9_]+_test$/', $database)) {
        fwrite(STDERR, "Unsafe {$driver} test database: {$database}\n");
        exit(1);
    }

    try {
        $dsn = $driver.':host='.getenv($prefix.'_HOST').';port='.getenv($prefix.'_PORT').';dbname='.$database;
        $pdo = new PDO($dsn, getenv($prefix.'_USERNAME'), getenv($prefix.'_PASSWORD'));
        $actual = $pdo->query($driver === 'pgsql' ? 'SELECT current_database()' : 'SELECT DATABASE()')->fetchColumn();
        if ($actual !== $database) {
            throw new RuntimeException("Connected to {$actual}, expected {$database}");
        }
        echo "{$driver}: isolated {$actual} is reachable\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "{$driver} preflight failed: {$error->getMessage()}\n");
        exit(1);
    }
}
PHP

echo "== sqlite pest =="
php -d memory_limit=1G vendor/bin/pest --ci

echo "== pgsql/mysql =="
composer test:pgsql
composer test:mysql

echo "== redis lane =="
REDIS_HOST="${REDIS_HOST:-127.0.0.1}" REDIS_PORT="${REDIS_PORT:-26379}" \
  composer test:redis

echo "== static / API =="
composer test:api-snapshot
composer analyse
composer lint:check
composer test:types
composer refactor:check
composer check:coverage
composer mutate:all

git diff --check

echo "== Local gates complete; declared PHP/Laravel/Testbench lowest/stable matrix still requires clean CI evidence =="
