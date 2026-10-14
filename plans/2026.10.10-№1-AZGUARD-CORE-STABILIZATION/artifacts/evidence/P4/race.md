# Race and consistency evidence (P4.6)

Candidate commit: `b981f2facc802bd1abba36b288ac09d47e2b1795`, run on a clean `git worktree` of that commit (the main checkout
carries the owner's uncommitted work), PHP 8.4.26, vendor of the main checkout (Laravel 13.35.0), services of
`docker-compose.yml`: PostgreSQL 25432, MySQL 23306, MariaDB 23307, Redis 26379, replica stand
(`authority-replica` profile, started for the run and removed after it). `PGSQL_PASSWORD`, `MYSQL_PASSWORD` and
`MARIADB_PASSWORD` were `azguard`. One run at a time, 2026-10-14.

| Run | Command | Exit | Tests | Assertions | Duration | Log |
|---|---|---:|---:|---:|---|---|
| engines-pgsql | `composer test:db -- pgsql --engines` | 0 | 71 | 2670 | 3 min 31 s | logs/P4.6-engines-pgsql.log |
| engines-mysql | `composer test:db -- mysql --engines` | 0 | 71 | 2670 | 9 min 12 s | logs/P4.6-engines-mysql.log |
| engines-mariadb | `composer test:db -- mariadb --engines` | 0 | 71 | 2728 | 2 min 48 s | logs/P4.6-engines-mariadb.log |
| replica | `composer test:replica` | 0 | 1 | 15 | 0 min 2 s | logs/P4.6-replica.log |
| redis | `composer test:redis` | 0 | 2 | 26 | 0 min 3 s | logs/P4.6-redis.log |
| extended | `composer test:extended` | 0 | 2 | 20029 | 1 min 35 s | logs/P4.6-extended.log |
| full-pgsql | `composer test:db -- pgsql --parallel --full` | 0 | 762 | 4854 | 2 min 51 s | logs/P4.6-full-pgsql.log |
| full-mysql | `composer test:db -- mysql --parallel --full` | 0 | 762 | 4854 | 24 min 31 s | logs/P4.6-full-mysql.log |
| full-mariadb | `composer test:db -- mariadb --parallel --full` | 0 | 762 | 4854 | 13 min 18 s | logs/P4.6-full-mariadb.log |

Every run is green with `--fail-on-skipped`: no skipped test. The replica run (`ReplicaLagTest`) and the Redis run
passed against real services, not skipped. The three `--full` lanes are the nightly depth of RELEASING.md step 1.
