# AzGuard bench: mini tier, pgsql, cache none

Commit d43592958e5c, 2026-10-09T14:27:17Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 13.32 ms | 21.89 ms | 27.3 ms | 5 | 174.7 | 0 | yes |
| consistency-load | disjoint:w4 | write | 12.87 ms | 25.32 ms | 35.69 ms | 8.5 | 58.2 | 0 | yes |
| consistency-load | hot:w4 | check | 13.19 ms | 23.78 ms | 34.08 ms | 5 | 154.3 | 0 | yes |
| consistency-load | hot:w4 | write | 14.77 ms | 41.94 ms | 90.26 ms | 8.5 | 51.4 | 0 | yes |
| consistency-load | paced50:w4 | check | 13.28 ms | 23.92 ms | 29.56 ms | 5 | 154.7 | 0 | yes |
| consistency-load | paced50:w4 | write | 16.96 ms | 26.8 ms | 35.69 ms | 8.5 | 51.6 | 0 | yes |
| consistency-load | paced200:w4 | check | 14.43 ms | 31.32 ms | 53.14 ms | 5 | 134.3 | 0 | yes |
| consistency-load | paced200:w4 | write | 16 ms | 50.52 ms | 128.25 ms | 8.5 | 44.8 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
