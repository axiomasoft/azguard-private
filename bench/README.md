# Load bench

`tests/Benchmarks/Suite.php` (`composer bench`) measures single API calls in one process. This bench measures the
package under load: forked workers hit one database at the same time, with a dataset sized like a real application.
It answers "how many checks per second, at what latency, with how many queries, and are the answers still right".

## Run

```bash
composer bench:load                       # mini tier, sqlite file, no cache, every profile
php bench/bin/run.php --tier=smoke        # seconds; the test suite runs this as a harness check
cd bench && make up && make matrix        # every published driver/cache pair (docker compose of the repository)
php bench/bin/run.php --tier=mini --driver=pgsql --cache=redis --profile=checks-concurrent,multi-tenant
php bench/bin/compare.php BASE.json HEAD.json     # p50 regressions, exit 1 when found
```

Requirements: PHP with `pcntl` and `posix`, `pdo_sqlite`; `pdo_pgsql`, `pdo_mysql` and `phpredis` for the server
drivers. The server drivers use the test databases of `docker-compose.yml` (`azguard_test`, user `azguard`); ports come
from `PGSQL_PORT`, `MYSQL_PORT`, `REDIS_PORT` as in the suite. The bench refuses a database whose name does not end in
`_bench` or `_test`, a non-local host, and a sqlite file other than its own `bench/.runs/<pid>/azguard_bench.sqlite`:
it runs `migrate:fresh`.

Use `-d opcache.enable_cli=1` (the Makefile and the composer script do) so that numbers are comparable to FPM.

## Tiers

| Tier | Users | Posts | Tenants × members | Heavy subject | Iterations / warm-up per worker | Workers |
|---|---:|---:|---|---|---|---|
| smoke | 40 | 500 | 4 × 5 | 5 roles + 50 direct | 20 / 5 | 1, 2 |
| mini | 200 | 10 000 | 20 × 10 | 20 roles + 200 direct | 200 / 20 | 1, 2, 4 |
| ci | 2 000 | 100 000 | 200 × 20 | 40 roles + 500 direct | 1 000 / 50 | 1, 4, 8 |
| ref | 20 000 | 1 000 000 | 1 000 × 40 | 40 roles + 900 direct | 3 000 / 100 | 1, 4, 8, 16 |

The catalog is fixed: 1 000 permissions (50 enums × 20 cases), 40 roles of 50 permissions each, a `Post` resource
filtered by owner and a tenant panel over teams. Seeding is deterministic, workers seed `mt_rand` with their number.

## Profiles (`profiles.json`)

| Profile | What runs | Correctness check |
|---|---|---|
| checks-concurrent | one check of a random user and permission per request, ladder of workers | — |
| large-sets | the heavy subject: missing and held permission, `abilities()` of 50, `permissionSet()`, repeat in one request | — |
| visible-at-scale | `visibleTo` page of 25 and count over all posts | user 10 sees exactly its posts |
| cache-cold-warm | the same check with a flushed store, a warm store and a warm request; then a shared warm store | — |
| grant-revoke-load | 1–2 writers toggle grants while readers check the same subjects | final state equals the last write |
| consistency-load | one writer; readers on other subjects (`disjoint`), on one hot subject (`hot`), under an open-loop writer at 50 and 200 writes/s (`paced*`); hits vs misses | final state equals the last write |
| multi-tenant | member, outsider and tenant admin checks on the tenant panel | every answer is right |

A check that ends in an engine error (`*_error` reason) counts as an error, not as a fast denial: the package fails
closed and would otherwise hide it. Under writes, a check that exhausts its retries because the state version moved is
recorded as `check_inconsistent`: that is the designed outcome, measured separately.

## Results

`bench/results/<date>-<driver>-<cache>-<tier>.json` (schema `schema/result.v1.json`) and a `.md` table with p50, p95,
p99, queries per operation, throughput, errors and stability. Each stage runs 3 times, up to 5 while the p95 of an
operation varies more than 10%; the medians are published and `stable` marks a CV of at most 10%. Numbers of one
machine are comparable only with each other: compare a change with `compare.php` on the same host.

## What comes from chatom bench

Reused as ideas (no code shared, chatom is an HTTP application): one profile catalog, named tiers with a deterministic
seed, the guard against non-disposable databases, nearest-rank percentiles from raw samples, 3 → 5 repetitions with the
10% p95 CV rule and a stable flag, a versioned result schema with JSON and Markdown output, the forked closed-model
runner with a shared start instant and completion markers, `compare.php` with ratio plus absolute delta, and
correctness checks next to the timings.

Not taken: k6 and the HTTP layer, host profiles, paired-commit qualification and the PostgreSQL statistics collector.
AzGuard is a library, so the workers call the public API in-process.

## Published results (1.0 reference, 2026-10-14, mini tier)

These six files are the recorded profile of the 1.0 code (commit `b981f2fa`) and the reference for every later
release: `results/2026-10-14-1.0-<driver>-<cache>-mini.{json,md}`. Host: one Intel Xeon E5-2683 v4 (32 logical CPUs,
shared with other work), Linux 7.0, PHP 8.4.26 with OPcache on and JIT off, Laravel 13.35.0, PostgreSQL 16.15, MySQL 8.4.11,
MariaDB 10.11.19, Redis 7.4.11 and SQLite 3.45.1 (the Docker services of `docker-compose.yml`). p50 of one operation and
SQL statements per operation; the full tables are the files next to this one.

| Operation | sqlite | sqlite + array | pgsql | pgsql + redis | mysql | mariadb |
|---|---|---|---|---|---|---|
| check, new request, 1 worker | 7.31 ms, 5 SQL | 7.25 ms, 3.67 SQL | 17.7 ms, 5 SQL | 8.47 ms, 2 SQL | 12.8 ms, 5 SQL | 13.5 ms, 5 SQL |
| check, 4 workers, throughput | 512/s | 558/s | 236/s | 441/s | 310/s | 304/s |
| heavy subject, missing permission | 33.1 ms | 9.13 ms | 42.0 ms | 11.9 ms | 39.0 ms | 38.0 ms |
| heavy subject, `permissionSet()` | 35.5 ms | 11.2 ms | 45.3 ms | 15.3 ms | 42.2 ms | 40.9 ms |
| heavy subject, `abilities()` of 50 | 208 ms | 174 ms | 224 ms | 178 ms | 222 ms | 218 ms |
| `visibleTo()` page of 10 000 posts | 8.81 ms | 8.86 ms | 23.9 ms | 25.6 ms | 15.8 ms | 18.7 ms |
| check, warm request | 0.86 ms, 0 SQL | 0.86 ms, 0 SQL | 1.03 ms, 0 SQL | 1.07 ms, 0 SQL | 0.95 ms, 0 SQL | 1.03 ms, 0 SQL |
| tenant member check, 4 workers | 4.53 ms | 4.72 ms | 10.9 ms | 7.02 ms | 10.9 ms | 12.1 ms |
| grant under read load, 2 writers | 4.17 ms | 4.82 ms | 14.9 ms | 12.9 ms | 18.0 ms | 12.5 ms |

Every run exits 0. In all six, every correctness check passes and no operation reports an error; the engines never
return a fast denial for an error. The host was not idle (other processes ran during the runs), so some operations
vary by more than 10% between repetitions and are marked `stable: no` in the files; the table uses p50 only.

| Pair | Operations | Not stable | Errors | Correctness checks (all passed) |
|---|---:|---:|---:|---:|
| sqlite | 92 | 25 | 0 | 18 |
| sqlite + array | 95 | 44 | 0 | 18 |
| pgsql | 92 | 3 | 0 | 18 |
| pgsql + redis | 95 | 18 | 0 | 18 |
| mysql | 92 | 8 | 0 | 18 |
| mariadb | 92 | 10 | 0 | 18 |

The numbers of one host are comparable only with each other. Absolute values depend on the host and its load at the
time: do not compare them with another machine. Before a release, run the same pairs on the same host and compare:
`php bench/bin/compare.php results/2026-10-14-1.0-<driver>-<cache>-mini.json <new>.json` (see `RELEASING.md`).

## CI

The smoke tier runs in the test suite (`tests/Feature/Bench/LoadBenchSmokeTest.php`), so the harness cannot rot.
`.github/workflows/bench.yml` is advisory: run it by hand (tier input) or weekly, on sqlite, pgsql and pgsql + redis.
It uploads the JSON and Markdown and never gates a merge, because shared runners are too noisy for thresholds.
