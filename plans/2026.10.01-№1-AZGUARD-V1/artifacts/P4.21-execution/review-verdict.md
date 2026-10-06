# Native Grok review — P4.21

Session: e747665f-b7c1-42c5-b52c-b5eaae9ffa04; observed grok-4.7-build/high; no context parameter; read-only tools.

## Full scope, round 1

I'll review the completed P4.21 scope read-only, starting with the candidate diff, summary, checks, and the canonical item.The plan and checks are in. Next I’ll map the candidate diff and read the production change plus the race, replica, Redis, and benchmark evidence.The diff is scoped to the fingerprint fix and the qualification fixtures. I’ll check those against the cache, lock, and read-session code, and against the acceptance logs.**GREEN**

P4.21 is accepted. The production change and the qualification fixtures match the owning authority, cache, and locking code, and the acceptance logs cited in `checks.json` are the successful runs.

`PanelRegistry::freeze` now publishes `IdentityCodec::digest([deployment buildId, PanelFingerprint::withCatalog(...)])` at `packages/core/src/Panels/PanelRegistry.php:210`. The same configured build keeps one admin fingerprint and a different panel stays different (`PanelRegistryTest.php:329-344`). A new `catalog.build_id` changes the state fingerprint and reloads grants. `redis-final.log` records the pre-fix failure where those two fingerprints were identical; `redis-qualified.log` is the later pass (2 tests, 26 assertions). Recipe fingerprints and catalog-cache selection are unchanged, so a live catalog and a cached catalog still digest to the same value. `api-manifest.php --check` is silent on success; the empty log with exit 0 matches that.

Independent processes, real replica, Redis, and the benchmark hold up:

- Revoke on another process denies under Primary/check and on a new Primary/request. An existing Primary/request stays warm until reset. `ReadAttempt` drops a changed attempt before `confirm` writes cache, and the third change becomes `ConsistencyError`.
- An unrecognized open repeatable-read transaction still sees the pre-revoke grant, `openReadSession` throws `InvalidConfigurationException`, and `decide` returns `SourceError`. After rollback, a fresh read is `NotGranted`.
- `Storage::perform` locks `panel_state` before the host callback. The host update commits while the other process is in a server lock wait; that process commits only afterward. PostgreSQL uses `pg_stat_activity`, MySQL uses `performance_schema.data_lock_waits`, and MariaDB uses `SHOW ENGINE INNODB STATUS` for that worker and ``azg_panel_state``. Earlier information-schema misses remain in `mysql-qualified.log`, `mariadb-accepted.log`, and the monitor logs, and they are not acceptance evidence. Acceptance is `pgsql-accepted.log`, `mysql-accepted.log`, and `mariadb-final-pass.log` (13 tests, 64 assertions each).
- The replica stand pauses with `pg_wal_replay_pause`, waits until the pause state is `paused`, and catches up by LSN. The paused Default route reads state and both grant tables on the standby PDO and allows the old state; Primary/check denies with a newer version; after catch-up, Default denies. `replica-qualified.log` is 1 test and 15 assertions. `replica-window.json` matches the summary, including the observed 7.647 ms window.
- Redis uses a per-run prefix on database 15 and deletes only those keys. Expiry at `expiresAt` misses the memo (`validUntil <= now`, and `activeAt` is `expiresAt > now`). The build change reloads grants. Restore reports a new incarnation and a lower version. Local CRM `clear()` touches storage and drops the request memo.
- `latency.json` is 200 samples and 20 warmups, nearest-rank p95/p99, on PHP 8.4.1, Laravel 13.33.0, PostgreSQL 16.14, and an i7-14700K. Warm samples are 0 state and 0 grant queries; cold samples are 400 and 400, which is two state reads and two grant reads per sample. Policy SQL stays 200 when a policy is present, including warm runs. Membership stays 0 because no membership adapter is installed. The summary figures are those values rounded to three decimals. D44 is an executable assertion, not a latency limit.

Core type coverage is 99.8% (`PanelRegistry.php` at 100%; the remaining gap is existing `DatabaseSource` and `SourceManager` coverage). PHPStan on `PanelRegistry.php` reports 0 errors after the disclosed warning that the optional turbo extension could not be loaded. Pint, `php -l`, and `git diff --check` cover the changed PHP. Failed development logs are retained and are not the logs named in `checks.json`.

Non-blocking: `tests/Engines/Support/replica-README.md:37` still says MariaDB evidence is `innodb_lock_waits`. The test reads InnoDB status in `CacheEngineWorld.php:54-71`, which is what the passing MariaDB run and the summary describe.

## Final correction, round 2

The prior full-scope review stays GREEN. I’ll check only the benchmark cleanup correction and its negative-probe evidence.**GREEN**

The earlier full-scope conclusion stands, and the bounded correction is sound. The README now matches the MariaDB monitor: `SHOW ENGINE INNODB STATUS`.

`tests/Benchmarks/AuthorizationLatency.php` boots the application only inside the top-level `try` (lines 27–29). A sample count below 100 still throws at lines 22–24, before that boot, so PHP’s own uncaught-exception status applies. After boot, a failure is recorded and `exit(1)` runs at lines 128–129. Cleanup runs only when `$app` exists (line 118). A cleanup exception is caught at lines 121–123 and also forces that nonzero status, so it no longer reaches Laravel’s exception handler.

The negative probe holds a bound, non-listening `127.0.0.1` port for the whole child run. `benchmark-unavailable-fixed.log` shows the child failing on `drop table if exists "azg_permission_grants"` to port 48475 with connection refused, then the same refusal under `Cleanup failed:`. No result matrix was written. `benchmark-unavailable-check.json` records child exit 1 and wrapper exit 0. The positive PostgreSQL run still has all 12 cold/warm rows in `latency.json`.
