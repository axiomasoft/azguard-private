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

## Published results (2026-10-09, mini tier)

One host (8 vCPU Xeon, PHP 8.4.26, OPcache on), PostgreSQL 16.15, MySQL 8.4.11 and Redis 7 from `docker-compose.yml`.
p50 of one operation and queries per operation; the full tables are the files next to this one.

| Operation | sqlite | sqlite + array | pgsql | pgsql + redis | mysql |
|---|---|---|---|---|---|
| check, new request, 1 worker | 3.70 ms, 5 SQL | 3.79 ms, 3.7 | 6.89 ms, 5 | 4.42 ms, 2 | 5.62 ms, 5 |
| check, 4 workers, throughput | 943/s | 1 002/s | 497/s | 855/s | 675/s |
| heavy subject, missing permission | 16.9 ms | 5.6 ms | 21.5 ms | 8.2 ms | 20.9 ms |
| heavy subject, `permissionSet()` | 17.6 ms | 7.3 ms | 23.5 ms | 9.8 ms | 21.8 ms |
| heavy subject, `abilities()` of 50 | 197 ms | 177 ms | 214 ms | 185 ms | 204 ms |
| `visibleTo()` page of 10 000 posts | 5.03 ms | 4.88 ms | 10.4 ms | 9.7 ms | 8.5 ms |
| check, warm request | 0.52 ms, 0 SQL | 0.49 ms | 0.61 ms | 0.55 ms | 0.58 ms |
| tenant member check, 4 workers | 2.19 ms | 2.22 ms | 5.09 ms | 3.08 ms | 4.75 ms |
| grant under read load | 2.42 ms | 2.27 ms | 8.95 ms | 9.34 ms | 7.96 ms |
| reads denied by `consistency_error`, 1 / 2 writers | 20% / 43% | 21% / 42% | 20% / 44% | 19% / 45% | 18% / 45% |

The server versions, CPU and commit are in each result file. Check the database version printed by your run before
you compare against these numbers.

What the bench found:

- **`permissionSet()` of a heavy subject took 2.17 s.** Every pattern was matched and re-validated against every
  catalog permission. Exact patterns are now a lookup: 18 ms (`d531525f` is the baseline, compare it with
  `compare.php`).
- **A connection closed by `DB::disconnect()` made every check fail closed with `source_error`.** That happens
  before a fork, or between Octane requests. The storage now reconnects as a query would.
- **Reads that overlap writes are denied.** The state version is per panel, so any grant or revoke in the panel
  makes a concurrent read retry, and after 3 retries it denies with `consistency_error`. That is safe (fail closed)
  but visible: with one writer about 20% of concurrent reads are denied, with two about 44%, on every driver.
- **`abilities()` costs about 4 ms per permission for a heavy subject.** That is the cost of one decision over 220
  contributions, the same as a check in a warm request.

## Consistency under writes (2026-10-09, mini tier, before the snapshot read)

`consistency-load`, one rep, 4 workers (one writer, three readers), files `results/2026-10-09-consistency-before-*`.
The baseline of the plan in `audits/2026-10-09-consistency-design.md`: in `disjoint` no reader reads a subject the
writer changes, yet 14-22% of checks end in `consistency_error`. The panel-wide version is the only cause. `paced50`
writes slower than a check takes, so a check rarely spans a write.

| driver+cache | stage | checks | consistency_error | hit ratio | check p50 / p95 / p99 ms | SQL/check (miss) |
|---|---|---|---|---|---|---|
| sqlite-none | disjoint:w4 | 300 | 66 (22%) | 0% | 3.82 / 5.54 / 11.07 | 5.11 |
| sqlite-none | hot:w4 | 300 | 58 (19%) | 0% | 4.75 / 7.83 / 14.30 | 5.25 |
| sqlite-none | paced50:w4 | 300 | 0 (0%) | 0% | 4.24 / 8.74 / 14.65 | 6.02 |
| sqlite-none | paced200:w4 | 300 | 64 (21%) | 0% | 3.87 / 5.79 / 11.29 | 5.21 |
| sqlite-array | disjoint:w4 | 300 | 61 (20%) | 49% | 3.85 / 6.66 / 10.76 | 5.29 |
| sqlite-array | hot:w4 | 300 | 61 (20%) | 97% | 7.52 / 10.87 / 10.87 | 10 |
| sqlite-array | paced50:w4 | 300 | 0 (0%) | 2% | 4.27 / 8.18 / 10.36 | 5.9 |
| sqlite-array | paced200:w4 | 300 | 54 (18%) | 24% | 4.24 / 6.38 / 10.78 | 5.11 |
| pgsql-none | disjoint:w4 | 300 | 54 (18%) | 0% | 7.03 / 17.37 / 24.16 | 5.98 |
| pgsql-none | hot:w4 | 300 | 58 (19%) | 0% | 8.12 / 20.53 / 28.60 | 5.87 |
| pgsql-none | paced50:w4 | 300 | 8 (3%) | 0% | 8.34 / 25.03 / 40.66 | 6.82 |
| pgsql-none | paced200:w4 | 300 | 56 (19%) | 0% | 6.75 / 19.17 / 23.48 | 5.98 |
| pgsql-redis | disjoint:w4 | 300 | 47 (16%) | 42% | 8.65 / 28.21 / 42.28 | 7.01 |
| pgsql-redis | hot:w4 | 300 | 42 (14%) | 63% | 12.25 / 35.22 / 38.42 | 7.64 |
| pgsql-redis | paced50:w4 | 300 | 0 (0%) | 3% | 8.36 / 18.99 / 27.79 | 6.68 |
| pgsql-redis | paced200:w4 | 300 | 60 (20%) | 36% | 8.35 / 24.74 / 36.55 | 6.27 |
| mysql-none | disjoint:w4 | 300 | 42 (14%) | 0% | 7.27 / 22.15 / 29.10 | 6.67 |
| mysql-none | hot:w4 | 300 | 41 (14%) | 0% | 7.39 / 19.91 / 29.03 | 6.53 |
| mysql-none | paced50:w4 | 300 | 0 (0%) | 0% | 7.26 / 15.11 / 20.73 | 6.4 |
| mysql-none | paced200:w4 | 300 | 49 (16%) | 0% | 6.91 / 20.73 / 23.82 | 6.02 |

## Consistency under writes (2026-10-09, mini tier, after steps 1-6)

Files `results/2026-10-09-consistency-after-*`, same profile and tier. `consistency_error` is 0 in every stage on
every driver (it was 14-22% under closed-loop writers). With a cache, writes to other subjects no longer evict
entries (`disjoint`: 100% hits). The hot subject is written all the time, so its misses are correct. The earlier
97% / 63% (*) counted hits that ignored the write. SQL per check: 5 on a miss, 2 on a hit (the observed-state
statement included). The box was under heavy shared load during the after run (load average 8-20), so the latency
columns are only indicative. Snapshot duration and panel lock wait/hold are not instrumented separately. Write
latency (p50 3-17 ms, p95 4-50 ms; mysql hot p95 86 ms) bounds lock wait plus hold.

| driver | stage | consistency_error before → after | hit ratio before → after | check p95 ms before → after |
|---|---|---|---|---|
| sqlite | disjoint | 66 (22%) → 0 | 0% → 0% | 5.54 → 6.85 |
| sqlite | hot | 58 (19%) → 0 | 0% → 0% | 7.83 → 7.31 |
| sqlite | paced200 | 64 (21%) → 0 | 0% → 0% | 5.79 → 7.62 |
| sqlite+array | disjoint | 61 (20%) → 0 | 49% → 100% | 6.66 → 4.93 |
| sqlite+array | hot | 61 (20%) → 0 | 97%* → 48% | 10.87 → 8.34 |
| sqlite+array | paced50 | 0 → 0 | 2% → 77% | 8.18 → 7.75 |
| sqlite+array | paced200 | 54 (18%) → 0 | 24% → 64% | 6.38 → 6.91 |
| pgsql | disjoint | 54 (18%) → 0 | 0% → 0% | 17.37 → 21.89 |
| pgsql | paced50 | 8 (3%) → 0 | 0% → 0% | 25.03 → 23.92 |
| pgsql | paced200 | 56 (19%) → 0 | 0% → 0% | 19.17 → 31.32 |
| pgsql+redis | disjoint | 47 (16%) → 0 | 42% → 100% | 28.21 → 8.47 |
| pgsql+redis | hot | 42 (14%) → 0 | 63%* → 21% | 35.22 → 15.41 |
| pgsql+redis | paced200 | 60 (20%) → 0 | 36% → 80% | 24.74 → 12.22 |
| mysql | disjoint | 42 (14%) → 0 | 0% → 0% | 22.15 → 25.89 |
| mysql | paced200 | 49 (16%) → 0 | 0% → 0% | 20.73 → 10.57 |
| mariadb | all four stages | — → 0 | — | 9.9–18.7 |

## CI

The smoke tier runs in the test suite (`tests/Feature/Bench/LoadBenchSmokeTest.php`), so the harness cannot rot.
`.github/workflows/bench.yml` is advisory: run it by hand (tier input) or weekly, on sqlite, pgsql and pgsql + redis.
It uploads the JSON and Markdown and never gates a merge, because shared runners are too noisy for thresholds.
