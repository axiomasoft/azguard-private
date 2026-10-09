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
