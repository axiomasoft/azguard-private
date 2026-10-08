# Authority cache qualification

Only `azguard_test` and the separate `azguard_authority_test` databases are used. The replica
profile has independent named test volumes and credentials; it never clones the ordinary
PostgreSQL cluster. Ports bind loopback. Test-only credentials must not be used for deployment.

```bash
PGSQL_PORT=25432 MYSQL_PORT=23306 MARIADB_PORT=23307 REDIS_PORT=26379 docker compose up -d --wait postgres mysql mariadb redis
PGSQL_PORT=25432 MYSQL_PORT=23306 MARIADB_PORT=23307 REDIS_PORT=26379 docker compose --profile authority-replica up -d --wait authority-primary authority-replica
bash tests/Engines/Support/replica-fixture.sh
REDIS_PORT=26379 composer test:redis
DB_CONNECTION=pgsql PGSQL_PORT=25432 php tests/Benchmarks/AuthorizationLatency.php
```

Adjust ordinary service ports to the local Compose mappings. The authority profile defaults to
25433/25434 (`AUTHORITY_PRIMARY_PORT`/`AUTHORITY_REPLICA_PORT`). Run serially per database;
these tests recreate AzGuard tables and `users` in their isolated database. Never run concurrently
against the same test database. `replica-fixture.sh` sets the exact isolated authority connection;
ordinary engine runners do not include the `replica` group.

The standby is bootstrapped using `pg_basebackup -R -Xs`, then receives physical streaming WAL.
The test waits for replay to reach the primary flush LSN before reading. It calls
`pg_wal_replay_pause()` and waits until `pg_get_wal_replay_pause_state()` is `paused`, commits a
revoke, proves the standby still has the old row/token and the primary no longer has it, then
resumes replay and polls until `pg_last_wal_replay_lsn() >= commit_lsn`. Polls have explicit
failure deadlines; no fixed sleep substitutes for acknowledgement or LSN readiness. Replay is
resumed in `finally` on failure. After qualification the isolated services may be stopped with
`docker compose --profile authority-replica stop authority-primary authority-replica`.

A paused standby intentionally allows old authority with `Default`, even with check refresh.
Primary/check denies after commit. Primary/request intentionally retains a warm token until
request reset unless a local touch invalidates it. These windows are not strict freshness claims.
The JSON `controlled_window_ms` is an observed test pause, not a replication SLA or latency budget.

The host-lock test polls a real server lock wait. PostgreSQL observes `pg_stat_activity` through
a separate normal test connection. MySQL/MariaDB use a separate loopback root **read-only query**
to `performance_schema.data_lock_waits` (MySQL) or the joined `INNODB_LOCK_WAITS`,
`INNODB_TRX` and `INNODB_LOCKS` tables (MariaDB),
restricted to the exact worker connection on the configured `_test` database;
`MYSQL_ROOT_PASSWORD`/`MARIADB_ROOT_PASSWORD` override the local test defaults. No privilege is
granted to the application account. The independent writer remains the normal application user.
MariaDB polling waits 120 ms between reads so its shared information-schema snapshot can refresh.

Redis uses database 15 with a random qualification prefix and deletes only its own keys; it
never flushes a database. The benchmark reports PHP/framework/server/hardware, sample/warmup
counts and nearest-rank p95/p99. SQL policy cost is separate; the benchmark has no membership
adapter (`membership=0`). Real CRM membership freshness is checked by CRM acceptance tests.
No latency pass threshold is invented; D44 SQL counts are executable assertions.

Primary sources for the fixture:
- [PostgreSQL 16 pg_basebackup](https://www.postgresql.org/docs/16/app-pgbasebackup.html)
- [PostgreSQL 16 recovery control and WAL LSN functions](https://www.postgresql.org/docs/16/functions-admin.html)
