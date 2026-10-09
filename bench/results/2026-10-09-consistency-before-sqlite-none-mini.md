# AzGuard bench: mini tier, sqlite, cache none

Commit 86a0aa4e14d9, 2026-10-09T12:09:08Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 3.82 ms | 5.54 ms | 11.07 ms | 5.11 | 395.7 | 0 | yes |
| consistency-load | disjoint:w4 | check_inconsistent | 10.59 ms | 13.38 ms | 14.86 ms | 15 | 111.6 | 0 | yes |
| consistency-load | disjoint:w4 | write | 2.43 ms | 3.27 ms | 4.31 ms | 7.5 | 169.1 | 0 | yes |
| consistency-load | hot:w4 | check | 4.75 ms | 7.83 ms | 14.3 ms | 5.25 | 330 | 0 | yes |
| consistency-load | hot:w4 | check_inconsistent | 11.97 ms | 17.1 ms | 17.82 ms | 15 | 79.1 | 0 | yes |
| consistency-load | hot:w4 | write | 2.54 ms | 3.9 ms | 5.4 ms | 7.5 | 136.4 | 0 | yes |
| consistency-load | paced50:w4 | check | 4.24 ms | 8.74 ms | 14.65 ms | 6.02 | 136.6 | 0 | yes |
| consistency-load | paced50:w4 | write | 20.03 ms | 21.09 ms | 43.51 ms | 7.5 | 45.5 | 0 | yes |
| consistency-load | paced200:w4 | check | 3.87 ms | 5.79 ms | 11.29 ms | 5.21 | 389.9 | 0 | yes |
| consistency-load | paced200:w4 | check_inconsistent | 11.02 ms | 13.34 ms | 15.48 ms | 15 | 105.7 | 0 | yes |
| consistency-load | paced200:w4 | write | 2.47 ms | 3.44 ms | 4.3 ms | 7.5 | 165.2 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
