# AzGuard bench: mini tier, mysql, cache none

Commit 86a0aa4e14d9, 2026-10-09T12:10:06Z; PHP 8.4.26, Laravel 13.35.0, mysql 8.4.11, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 7.27 ms | 22.15 ms | 29.1 ms | 6.67 | 218.8 | 0 | yes |
| consistency-load | disjoint:w4 | check_inconsistent | 20.36 ms | 33.34 ms | 40.69 ms | 15 | 35.6 | 0 | yes |
| consistency-load | disjoint:w4 | write | 7.72 ms | 18.1 ms | 20.93 ms | 6.5 | 84.8 | 0 | yes |
| consistency-load | hot:w4 | check | 7.39 ms | 19.91 ms | 29.03 ms | 6.53 | 222.8 | 0 | yes |
| consistency-load | hot:w4 | check_inconsistent | 19.81 ms | 26.42 ms | 33.61 ms | 15 | 35.3 | 0 | yes |
| consistency-load | hot:w4 | write | 6.96 ms | 19.05 ms | 36.56 ms | 6.5 | 86 | 0 | yes |
| consistency-load | paced50:w4 | check | 7.26 ms | 15.11 ms | 20.73 ms | 6.4 | 151.7 | 0 | yes |
| consistency-load | paced50:w4 | write | 20 ms | 23.1 ms | 27.26 ms | 6.5 | 50.6 | 0 | yes |
| consistency-load | paced200:w4 | check | 6.91 ms | 20.73 ms | 23.82 ms | 6.02 | 229.2 | 0 | yes |
| consistency-load | paced200:w4 | check_inconsistent | 19.86 ms | 31.36 ms | 47.59 ms | 15 | 44.8 | 0 | yes |
| consistency-load | paced200:w4 | write | 6.44 ms | 12.29 ms | 15.7 ms | 6.5 | 91.3 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
