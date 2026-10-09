# AzGuard bench: mini tier, mysql, cache none

Commit d43592958e5c, 2026-10-09T14:28:25Z; PHP 8.4.26, Laravel 13.35.0, mysql 8.4.11, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| consistency-load | disjoint:w4 | check | 9.38 ms | 25.89 ms | 36.45 ms | 5 | 172.3 | 0 | yes |
| consistency-load | disjoint:w4 | write | 8.77 ms | 14.05 ms | 17.53 ms | 8.5 | 57.4 | 0 | yes |
| consistency-load | hot:w4 | check | 10.37 ms | 22.59 ms | 41.87 ms | 5 | 10.4 | 0 | yes |
| consistency-load | hot:w4 | write | 38.18 ms | 85.71 ms | 158.39 ms | 8.5 | 3.5 | 0 | yes |
| consistency-load | paced50:w4 | check | 10.22 ms | 25.42 ms | 34.99 ms | 5 | 151.7 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.47 ms | 32.48 ms | 41.66 ms | 8.5 | 50.6 | 0 | yes |
| consistency-load | paced200:w4 | check | 8.78 ms | 10.57 ms | 12.48 ms | 5 | 334.5 | 0 | yes |
| consistency-load | paced200:w4 | write | 8.49 ms | 10.64 ms | 11.42 ms | 8.5 | 111.5 | 0 | yes |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
