# AzGuard bench: mini tier, sqlite, cache none

Commit d43592958e5c, 2026-10-09T14:26:43Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 5.46 ms | 6.85 ms | 8.56 ms | 5 | 518.4 | 0 | yes |
| consistency-load | disjoint:w4 | write | 3.1 ms | 4.34 ms | 4.66 ms | 9.5 | 172.8 | 0 | yes |
| consistency-load | hot:w4 | check | 5.42 ms | 7.31 ms | 9.4 ms | 5 | 509.6 | 0 | yes |
| consistency-load | hot:w4 | write | 3.13 ms | 7.11 ms | 7.95 ms | 9.5 | 169.9 | 0 | yes |
| consistency-load | paced50:w4 | check | 6.08 ms | 10.04 ms | 11.99 ms | 5 | 152.4 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.94 ms | 23.44 ms | 26.84 ms | 9.5 | 50.8 | 0 | yes |
| consistency-load | paced200:w4 | check | 5.77 ms | 7.62 ms | 12.74 ms | 5 | 475.6 | 0 | yes |
| consistency-load | paced200:w4 | write | 3.43 ms | 4.24 ms | 7.92 ms | 9.5 | 158.5 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
