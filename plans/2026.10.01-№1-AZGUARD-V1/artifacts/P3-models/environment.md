Compose postgres/mysql/mariadb healthy, up --wait exit 0. All engine databases azguard_test.
PGSQL_PORT=25432 MYSQL_PORT=23306 MARIADB_PORT=23307 docker compose up -d --wait postgres mysql mariadb
DB_CONNECTION=<engine> APP_ENV=testing <ENGINE>_PORT=<port> php -d memory_limit=1G vendor/bin/pest --group=engines --fail-on-skipped --compact
PHPStan/type coverage: php -d phpstan.restarted=1 (known static PHP dynamic turbo incompatibility). Arch requires memory_limit=1G. Root/grok settings pre-existing owner changes preserved.
