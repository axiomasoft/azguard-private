# AzGuard bench: mini tier, pgsql, cache none

Commit 86a0aa4e14d9, 2026-10-09T12:09:34Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 7.03 ms | 17.37 ms | 24.16 ms | 5.98 | 224.8 | 0 | yes |
| consistency-load | disjoint:w4 | check_inconsistent | 19.99 ms | 23.95 ms | 25.9 ms | 15 | 49.3 | 0 | yes |
| consistency-load | disjoint:w4 | write | 6.64 ms | 10.54 ms | 14.14 ms | 6.5 | 91.4 | 0 | yes |
| consistency-load | hot:w4 | check | 8.12 ms | 20.53 ms | 28.6 ms | 5.87 | 186.5 | 0 | yes |
| consistency-load | hot:w4 | check_inconsistent | 22.63 ms | 28.27 ms | 33.39 ms | 15 | 44.7 | 0 | yes |
| consistency-load | hot:w4 | write | 7.58 ms | 13.72 ms | 20.5 ms | 6.5 | 77.1 | 0 | yes |
| consistency-load | paced50:w4 | check | 8.34 ms | 25.03 ms | 40.66 ms | 6.82 | 148.2 | 0 | yes |
| consistency-load | paced50:w4 | check_inconsistent | 39.47 ms | 58.81 ms | 58.81 ms | 15 | 4.1 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.65 ms | 35.42 ms | 53.21 ms | 6.5 | 50.8 | 0 | yes |
| consistency-load | paced200:w4 | check | 6.75 ms | 19.17 ms | 23.48 ms | 5.98 | 226.5 | 0 | yes |
| consistency-load | paced200:w4 | check_inconsistent | 19.7 ms | 23.25 ms | 24.1 ms | 15 | 52 | 0 | yes |
| consistency-load | paced200:w4 | write | 6.26 ms | 9.61 ms | 15.45 ms | 6.5 | 92.8 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
