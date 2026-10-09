# AzGuard bench: mini tier, pgsql, cache redis

Commit d43592958e5c, 2026-10-09T14:27:35Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check_hit | 5.73 ms | 8.47 ms | 14.92 ms | 2 | 269.8 | 0 | yes |
| consistency-load | disjoint:w4 | write | 9.4 ms | 14.13 ms | 18.88 ms | 8.5 | 89.9 | 0 | yes |
| consistency-load | hot:w4 | check | 10.55 ms | 15.41 ms | 17.63 ms | 5 | 232.1 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 5.7 ms | 8.73 ms | 12.57 ms | 2 | 60.5 | 0 | yes |
| consistency-load | hot:w4 | write | 9.59 ms | 12.87 ms | 14.91 ms | 8.5 | 97.5 | 0 | yes |
| consistency-load | paced50:w4 | check | 10.8 ms | 14.62 ms | 15.3 ms | 5 | 19.7 | 0 | yes |
| consistency-load | paced50:w4 | check_hit | 5.88 ms | 8.06 ms | 10.59 ms | 2 | 132.1 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.89 ms | 22.82 ms | 25.73 ms | 8.5 | 50.6 | 0 | yes |
| consistency-load | paced200:w4 | check | 10.22 ms | 12.22 ms | 14.32 ms | 5 | 65.4 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 5.58 ms | 7.1 ms | 8.08 ms | 2 | 261.5 | 0 | yes |
| consistency-load | paced200:w4 | write | 8.86 ms | 12.02 ms | 14.08 ms | 8.5 | 109 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
