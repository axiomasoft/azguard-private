# Performance

AzGuard resolves a subject's permission set once per request or job, then answers every check from memory.
This page explains what costs time, how to configure caching, and what the benchmarks measured.

## What a check costs

| Situation | SQL per check | Why |
|---|---|---|
| First check of a subject in a request, no cache store | 6 | The subject, the storage and panel state versions, permission grants, role grants, and a second read of the panel state that confirms nothing changed during the read |
| First check, with a cache store | 3 | The subject and the two state versions. The grants come from the store |
| Every following check in the same request | 1 | The set is memoized. The subject row is re-read, see below |
| A check with a policy over a list of N records | N + a few | The subject is re-read per check |

- **The subject is re-read on every check.** `ModelSubjectResolver` loads the subject row again, so a
  deactivated user or a changed attribute takes effect within the same request. That is about a third of the
  time of a warm check, and it is why checking 100 records costs about 100 queries.
- **Lists.** Do not check records one by one. `visibleTo()` adds the conditions to the SQL query instead: about
  9 queries for any number of rows. See [Filtering lists](/guides/checking-access#filtering-lists).
- **Many abilities at once.** `abilities()` resolves the set once, but evaluates every permission through the
  whole pipeline. Use it for a menu, not for thousands of permissions.

## Configure caching

```php
// config/azguard.php
'defaults' => [
    'cache' => ['store' => 'redis', 'ttl' => 3600],
],
```

Or per panel: `$panel->cache(store: 'redis')`.

- **`cache.store`.** Without a store, every request resolves sets from the database (6 queries for the first
  check). With a shared store such as Redis, it is 3. Any change through AzGuard bumps the state version, so
  cached sets never outlive a grant or a revoke.
- **`consistency.state_refresh`.** `StateRefresh::Request` (the default) reads the state version once per
  request or job. `StateRefresh::Check` reads it before every check. Use it only for long-running workers that
  must see revokes made by other processes immediately.
- **`azguard:catalog:cache`.** Compiling the catalog of 1 000 permissions and 40 roles took 57 ms. Cache it on
  deploy and set `AZGUARD_BUILD_ID` to the commit hash. See [Operations](/advanced/operations).

## Benchmarks

`composer bench` runs `tests/Benchmarks/Suite.php`. It generates a catalog of 50 permission enums × 20 cases
(1 000 permissions) and 40 roles × 50 permissions, and uses a real Eloquent subject with `DatabaseSource`. The
"light" subject holds 1 role. The "heavy" subject holds 20 roles and 200 direct grants. Raw results:
`tests/Benchmarks/results/`.

Run on 2026-10-09: PHP 8.4.26, Laravel 13.35, SQLite in memory, OPcache off, Intel Xeon (8 vCPU), 300 samples
per scenario (30 for the slow ones).

| Scenario | Median | p95 | SQL per op |
|---|---|---|---|
| Check, same request, 1 role | 0.67 ms | 0.99 ms | 1 |
| Check, same request, 20 roles + 200 direct grants | 2.53 ms | 3.65 ms | 1 |
| Check, same request, wildcard `g1.**` | 0.66 ms | 0.87 ms | 1 |
| Check, new request, no store, 1 role | 4.28 ms | 6.03 ms | 6 |
| Check, new request, no store, heavy | 18.26 ms | 24.71 ms | 6 |
| Check, new request, array store, 1 role | 3.40 ms | 4.53 ms | 3 |
| Check, new request, array store, heavy | 5.75 ms | 7.59 ms | 3 |
| `abilities()` of 20 permissions, new request, heavy | 101 ms | 136 ms | 6 |
| 100 records with a policy (one op = 100 checks) | 83.5 ms | 122 ms | 105 |
| `visibleTo()`: first page of 10 000 posts | 4.81 ms | 5.80 ms | 9 |
| `visibleTo()`: count of 10 000 posts | 4.78 ms | 5.06 ms | 9 |
| `grantRole()` to a new subject | 2.04 ms | 2.25 ms | 6.8 |
| Compile the panel registry (1 000 permissions, 40 roles) | 57.4 ms | 61.6 ms | 0 |

- **Compare runs, not thresholds.** The numbers are relative: OPcache, a real database server and the
  machine change them. Compare a change against `main` on the same machine.
- **Other databases.** `DB_CONNECTION=pgsql composer bench` uses the test databases of the suite.
