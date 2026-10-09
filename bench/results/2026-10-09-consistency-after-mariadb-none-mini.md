# AzGuard bench: mini tier, mariadb, cache none

Commit d43592958e5c, 2026-10-09T14:28:42Z; PHP 8.4.26, Laravel 13.35.0, mariadb 10.11.19-MariaDB-ubu2204, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 8.69 ms | 18.72 ms | 21.57 ms | 5 | 298.3 | 0 | yes |
| consistency-load | disjoint:w4 | write | 7.79 ms | 13.9 ms | 27.15 ms | 8.5 | 99.4 | 0 | yes |
| consistency-load | hot:w4 | check | 8.29 ms | 9.92 ms | 12.53 ms | 5 | 348.6 | 0 | yes |
| consistency-load | hot:w4 | write | 6.96 ms | 9.06 ms | 9.86 ms | 8.5 | 116.2 | 0 | yes |
| consistency-load | paced50:w4 | check | 8.38 ms | 10.15 ms | 11.01 ms | 5 | 152 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.87 ms | 22.5 ms | 24.63 ms | 8.5 | 50.7 | 0 | yes |
| consistency-load | paced200:w4 | check | 8.54 ms | 10.61 ms | 12.37 ms | 5 | 342.8 | 0 | yes |
| consistency-load | paced200:w4 | write | 7.62 ms | 10.18 ms | 11.31 ms | 8.5 | 114.3 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
