# Performance

AzGuard resolves a subject's permission set once per request or job, then answers every check from memory.
This page explains what costs time, how to configure caching, and what the benchmarks measured.

## What a check costs

| Situation | SQL per check | Why |
|---|---|---|
| First check of a subject in a request, no cache store | 5 | The storage and panel state versions, permission grants, role grants, and a second read of the panel state that confirms nothing changed during the read |
| First check, with a cache store | 2 | The two state versions. The grants come from the store |
| Every following check in the same request | 0 | The set is memoized |
| A check with a policy over a list of N records | 0 per record | The same set and the same user instance |

- **The subject is the model you hold.** `$user->hasPermission()`, `$user->can()`, middleware, Filament and
  `visibleTo($user)` pass the model instance to policies, restrictions and automatic roles, as Laravel's Gate
  does. It is not read again. A change made elsewhere is visible after `$user->refresh()`, exactly as with
  `Auth::user()`.
- **A bare reference is read.** A check by `SubjectRef` (the console, `azguard:explain`, your own code) loads
  the row on every check, because there is no instance to trust.
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

- **`cache.store`.** Without a store, every request resolves sets from the database (5 queries for the first
  check). With a shared store such as Redis, it is 2. Any change through AzGuard bumps the state version, so
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
| Check, same request, 1 role | 0.42 ms | 0.58 ms | 0 |
| Check, same request, 20 roles + 200 direct grants | 2.02 ms | 2.21 ms | 0 |
| Check, same request, wildcard `g1.**` | 0.39 ms | 0.58 ms | 0 |
| Check, new request, no store, 1 role | 3.87 ms | 5.65 ms | 5 |
| Check, new request, no store, heavy | 17.36 ms | 22.93 ms | 5 |
| Check, new request, array store, 1 role | 3.16 ms | 3.91 ms | 2 |
| Check, new request, array store, heavy | 5.41 ms | 7.27 ms | 2 |
| `abilities()` of 20 permissions, new request, heavy | 97.48 ms | 116 ms | 5 |
| 100 records with a policy (one op = 100 checks) | 63.93 ms | 76.57 ms | 5 |
| `visibleTo()`: first page of 10 000 posts | 4.59 ms | 5.16 ms | 8 |
| `visibleTo()`: count of 10 000 posts | 4.76 ms | 6.79 ms | 8 |
| `grantRole()` to a new subject | 2.13 ms | 3.35 ms | 6.8 |
| Compile the panel registry (1 000 permissions, 40 roles) | 62.65 ms | 66.58 ms | 0 |

- **Compare runs, not thresholds.** The numbers are relative: OPcache, a real database server and the
  machine change them. Compare a change against `main` on the same machine.
- **Other databases.** `DB_CONNECTION=pgsql composer bench` uses the test databases of the suite.

## Load bench

`composer bench:load` runs `bench/`: forked workers call the API at the same time against one database, with tiers
from seconds (`smoke`) to a million posts (`ref`). Profiles cover concurrent checks, large role and permission sets,
`visibleTo()` at scale, a cold and a warm cache, grants and revokes under read load, and tenant checks. Each run writes
JSON and Markdown to `bench/results/`. Drivers, tiers, the result format and how to compare two runs:
[`bench/README.md`](https://github.com/axiomasoft/azguard-private/blob/main/bench/README.md).
