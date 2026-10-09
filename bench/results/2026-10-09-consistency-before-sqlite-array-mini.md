# AzGuard bench: mini tier, sqlite, cache array

Commit 86a0aa4e14d9, 2026-10-09T12:09:18Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 3.85 ms | 6.66 ms | 10.76 ms | 5.29 | 223.7 | 0 | yes |
| consistency-load | disjoint:w4 | check_hit | 2.97 ms | 3.4 ms | 3.5 ms | 2 | 218.1 | 0 | yes |
| consistency-load | disjoint:w4 | check_inconsistent | 10.84 ms | 13.62 ms | 14.87 ms | 15 | 112.8 | 0 | yes |
| consistency-load | disjoint:w4 | write | 2.4 ms | 3.45 ms | 4.2 ms | 7.5 | 184.9 | 0 | yes |
| consistency-load | hot:w4 | check | 7.52 ms | 10.87 ms | 10.87 ms | 10 | 14.9 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 3.03 ms | 4.55 ms | 6.63 ms | 2 | 429.9 | 0 | yes |
| consistency-load | hot:w4 | check_inconsistent | 11.53 ms | 15.84 ms | 31.13 ms | 15 | 113.5 | 0 | yes |
| consistency-load | hot:w4 | write | 2.52 ms | 3.54 ms | 4.95 ms | 7.5 | 186.1 | 0 | yes |
| consistency-load | paced50:w4 | check | 4.27 ms | 8.18 ms | 10.36 ms | 5.9 | 147.4 | 0 | yes |
| consistency-load | paced50:w4 | check_hit | 3.2 ms | 4.04 ms | 4.04 ms | 2 | 2.5 | 0 | yes |
| consistency-load | paced50:w4 | write | 20.01 ms | 21.06 ms | 25.37 ms | 7.5 | 50 | 0 | yes |
| consistency-load | paced200:w4 | check | 4.24 ms | 6.38 ms | 10.78 ms | 5.11 | 286.9 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 3.24 ms | 5.07 ms | 8.54 ms | 2 | 90.5 | 0 | yes |
| consistency-load | paced200:w4 | check_inconsistent | 15.44 ms | 16.45 ms | 16.73 ms | 15 | 82.9 | 0 | yes |
| consistency-load | paced200:w4 | write | 2.5 ms | 3.21 ms | 3.82 ms | 7.5 | 153.4 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
