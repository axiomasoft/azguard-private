# Test infrastructure references

Checked on 2026-10-11 for the disposable database stand in `docker-compose.yml` and
the `test-db-matrix` job. See the [maintainer database stand](guides/testing.md#maintainer-database-stand);
the repository's `DEVELOPMENT.md` contains the commands and measurements.

- [MySQL 8.4 InnoDB variables](https://dev.mysql.com/doc/refman/8.4/en/innodb-parameters.html):
  `innodb_flush_log_at_trx_commit=0`, `innodb_doublewrite=OFF` and
  `innodb_flush_method=O_DIRECT_NO_FSYNC` are supported startup settings.
- [MySQL 8.4 binary logging](https://dev.mysql.com/doc/refman/8.4/en/replication-options-binary-log.html):
  `--disable-log-bin` disables the binary log.
- [MariaDB InnoDB variables](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables):
  the pre-11.0.6 doublewrite setting is boolean; MariaDB 10.11 accepts
  `innodb_doublewrite=0` and `innodb_flush_log_at_trx_commit=0`.
- [MariaDB binary-log variables](https://mariadb.com/docs/server/ha-and-performance/standard-replication/replication-and-binary-log-system-variables#log_bin):
  binary logging is disabled in the test stand with `--skip-log-bin`.
- [PostgreSQL 16 non-durable settings](https://www.postgresql.org/docs/16/non-durability.html):
  a memory-backed data directory and disabling `fsync`, `synchronous_commit` and
  `full_page_writes` are documented options for disposable databases.
- [GitHub Actions workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#jobsjob_idservicesservice_idimage):
  an empty service image disables that service, as used by the initial tmpfs change.
  Current documentation describes `services.command`, but the installed actionlint 1.7.12
  rejects that key. The follow-up starts the selected service with Compose to reuse all
  local settings instead of duplicating native service definitions.
- [Docker Compose startup](https://docs.docker.com/reference/cli/docker/compose/up/):
  a service argument selects the database; `--wait` checks readiness, bounded by `--wait-timeout`.
  [GitHub Ubuntu runner inventory](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)
  includes Docker Compose.
- [Actions archive cache v4](https://github.com/actions/cache/blob/v4/README.md):
  cache a selected path with manifest-derived keys and fallback prefixes. The database matrix
  applies the existing repository cache pattern to Composer's `cache-files-dir`, preserving
  dependency resolution and rebuilding `vendor` on every job.
