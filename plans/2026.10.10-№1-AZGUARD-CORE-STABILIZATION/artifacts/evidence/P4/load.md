# Load bench evidence: the 1.0 reference profile (P4.6)

Candidate commit `b981f2facc802bd1abba36b288ac09d47e2b1795`, clean `git worktree` of it, PHP 8.4.26 with `pcntl`, `posix` and `redis`,
`php -d memory_limit=2G -d opcache.enable_cli=1 -d opcache.jit_buffer_size=0 bench/bin/run.php --tier=mini`, one pair at a
time, 2026-10-14. Host: Intel Xeon E5-2683 v4 (32 logical CPUs), shared with other work (the load average is below).
Services: PostgreSQL 16.15, MySQL 8.4.11, MariaDB 10.11.19, Redis 7.4.11 (port 26379, `REDIS_PORT=26379`), SQLite 3.45.1.

## The reference files

`bench/results/2026-10-14-1.0-<driver>-<cache>-mini.{json,md}` for sqlite/none, sqlite/array, pgsql/none, pgsql/redis,
mysql/none, mariadb/none: six pairs, committed; the 0.x-era `bench/results/2026-10-09-*` files are deleted. The `dirty`
flag of four of the JSON files is `true` only because the result files of earlier pairs were untracked in the worktree;
the commit is the same for all six.

| Pair | Run start (UTC) | Load average before (1/5/15 min) | Operations | Not stable | Errors (`*_error`) | Correctness checks |
|---|---|---|---:|---:|---:|---|
| sqlite/none | 14:30:41 | 3.66 4.75 6.00 | 92 | 25 | 0 | 18 of 18 passed |
| sqlite/array | 14:40:52 | 4.29 5.47 5.84 | 95 | 44 | 0 | 18 of 18 passed |
| pgsql/none | 14:50:00 | 6.09 7.00 6.55 | 92 | 3 | 0 | 18 of 18 passed |
| pgsql/redis | 15:24:36 | 4.60 4.43 5.09 | 95 | 18 | 0 | 18 of 18 passed |
| mysql/none | 15:02:24 | 8.09 6.30 6.40 | 92 | 8 | 0 | 18 of 18 passed |
| mariadb/none | 15:13:31 | 3.78 4.75 5.85 | 92 | 10 | 0 | 18 of 18 passed |

All six runs exit 0. `check_inconsistent` does not occur in any stage: every read ends in a decision. The numbers of
each pair are in the Markdown file next to the JSON and, as a table, in `bench/README.md` ("Published results").

## What went wrong, and what was done

1. **First `pgsql/redis` run failed (not a defect).** I did not pass `REDIS_PORT`, so the bench looked for Redis on the
   default port 6379 while the service listens on 26379. The stage `cache-cold-warm` stopped with
   `Check of g1.p1 failed: source_error`. Log: `logs/P4.6-bench-pgsql-redis-failed-port.log`. The run was repeated with
   `REDIS_PORT=26379` (log `logs/P4.6-bench-pgsql-redis-rerun.log`) and is the reference. The shell wrapper of the first
   pass printed a wrong exit code for that run (the `$?` was read after a command substitution); the exit codes in this
   file come from the result files and the reruns.
2. **Unstable stages.** The host is shared, so some operations vary by more than 10% p95 between repetitions
   (the bench already repeats a stage up to 5 times). The rule asks for one rerun of an unstable stage. A second pass
   over all pairs was started for it; it did not make the numbers more stable (unstable operations of the second pass:
   sqlite/none 35 against 25, sqlite/array 41 against 44, pgsql/none 7 against 3), so the cause is the host and not a
   fluke. The second pass was stopped after three pairs on the owner's request, with `pgsql/redis`, `mysql/none`
   and `mariadb/none` not repeated. Its files are in `logs/bench-run2/` (not a reference). The first pass stays the
   reference: it has no error, no failed check and the same commit for all six; the p50 values published in
   `bench/README.md` are medians of 3-5 repetitions.

## Reference for later releases

RELEASING.md step 1 now carries the same-host comparison: run the same six pairs and
`php bench/bin/compare.php bench/results/2026-10-14-1.0-<driver>-<cache>-mini.json <new>.json`. The reference is valid
only on a host like this one; after a host change a new reference must be recorded first. P4.8 re-runs the affected
profiles when production code changes after the last P4.6 commit.
