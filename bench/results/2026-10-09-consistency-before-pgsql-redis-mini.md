# AzGuard bench: mini tier, pgsql, cache redis

Commit 86a0aa4e14d9, 2026-10-09T12:09:50Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 8.65 ms | 28.21 ms | 42.28 ms | 7.01 | 113.1 | 0 | yes |
| consistency-load | disjoint:w4 | check_hit | 4.73 ms | 8.72 ms | 14.18 ms | 2 | 80.2 | 0 | yes |
| consistency-load | disjoint:w4 | check_inconsistent | 22.78 ms | 127.4 ms | 128.9 ms | 15 | 35.9 | 0 | yes |
| consistency-load | disjoint:w4 | write | 7.68 ms | 20.31 ms | 36.76 ms | 6.5 | 76.4 | 0 | yes |
| consistency-load | hot:w4 | check | 12.25 ms | 35.22 ms | 38.42 ms | 7.64 | 91.3 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 4.31 ms | 6.42 ms | 10.56 ms | 2 | 154 | 0 | yes |
| consistency-load | hot:w4 | check_inconsistent | 21.47 ms | 31.74 ms | 35.65 ms | 15 | 39.9 | 0 | yes |
| consistency-load | hot:w4 | write | 7.28 ms | 13.88 ms | 21.7 ms | 6.5 | 95.1 | 0 | yes |
| consistency-load | paced50:w4 | check | 8.36 ms | 18.99 ms | 27.79 ms | 6.68 | 148.3 | 0 | yes |
| consistency-load | paced50:w4 | check_hit | 4.79 ms | 7.31 ms | 7.31 ms | 2 | 4.1 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.84 ms | 23.45 ms | 24.33 ms | 6.5 | 50.8 | 0 | yes |
| consistency-load | paced200:w4 | check | 8.35 ms | 24.74 ms | 36.55 ms | 6.27 | 126.4 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 4.58 ms | 6.45 ms | 35.9 ms | 2 | 71.9 | 0 | yes |
| consistency-load | paced200:w4 | check_inconsistent | 21.62 ms | 31.42 ms | 52.54 ms | 15 | 49.6 | 0 | yes |
| consistency-load | paced200:w4 | write | 7.01 ms | 14.5 ms | 19.11 ms | 6.5 | 82.6 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
