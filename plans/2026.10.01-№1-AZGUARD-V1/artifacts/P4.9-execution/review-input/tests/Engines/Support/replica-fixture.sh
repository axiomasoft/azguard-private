#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
export APP_ENV=testing DB_CONNECTION=pgsql AUTHORITY_REPLICA_TEST=1
php -d memory_limit=1G vendor/bin/pest tests/Engines/ReplicaLagTest.php --ci --fail-on-skipped
