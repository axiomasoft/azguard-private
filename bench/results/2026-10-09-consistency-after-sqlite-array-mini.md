# AzGuard bench: mini tier, sqlite, cache array

Commit d43592958e5c, 2026-10-09T14:26:53Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check_hit | 3.75 ms | 4.93 ms | 7.56 ms | 2 | 728.9 | 0 | yes |
| consistency-load | disjoint:w4 | write | 2.8 ms | 4.1 ms | 5.89 ms | 9.5 | 243 | 0 | yes |
| consistency-load | hot:w4 | check | 5.65 ms | 8.34 ms | 13.48 ms | 5 | 289.2 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 3.75 ms | 4.99 ms | 6.79 ms | 2 | 263.4 | 0 | yes |
| consistency-load | hot:w4 | write | 3.08 ms | 3.79 ms | 5.22 ms | 9.5 | 184.2 | 0 | yes |
| consistency-load | paced50:w4 | check | 5.35 ms | 7.75 ms | 10.27 ms | 5 | 35 | 0 | yes |
| consistency-load | paced50:w4 | check_hit | 3.71 ms | 5.34 ms | 7.67 ms | 2 | 117.2 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.91 ms | 23.12 ms | 26.45 ms | 9.5 | 50.7 | 0 | yes |
| consistency-load | paced200:w4 | check | 5.28 ms | 6.91 ms | 7.88 ms | 5 | 232.7 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 3.75 ms | 5.16 ms | 6.03 ms | 2 | 413.7 | 0 | yes |
| consistency-load | paced200:w4 | write | 3.01 ms | 4.32 ms | 5.27 ms | 9.5 | 215.5 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
