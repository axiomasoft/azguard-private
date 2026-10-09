# AzGuard bench: mini tier, mariadb, cache none

Commit c04529e8f288, 2026-10-09T17:22:32Z; PHP 8.4.26, Laravel 13.35.0, mariadb 10.11.19-MariaDB-ubu2204, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 7.8 ms | 10.19 ms | 13.56 ms | 5 | 118.3 | 0 | yes |
| checks-concurrent | check:w1 | probe.snapshot | 1.66 ms | 2.73 ms | 3.17 ms | 0 | 118.3 | 0 | yes |
| checks-concurrent | check:w2 | check | 7.96 ms | 12.75 ms | 18.96 ms | 5 | 223.3 | 0 | no |
| checks-concurrent | check:w2 | probe.snapshot | 1.82 ms | 2.75 ms | 5.1 ms | 0 | 223.3 | 0 | no |
| checks-concurrent | check:w4 | check | 8.09 ms | 13.34 ms | 19.09 ms | 5 | 444.2 | 0 | no |
| checks-concurrent | check:w4 | probe.snapshot | 1.87 ms | 3.56 ms | 6.48 ms | 0 | 444.2 | 0 | no |
| large-sets | heavy:w1 | abilities50 | 130.5 ms | 176.65 ms | 176.65 ms | 4 | 4.3 | 0 | no |
| large-sets | heavy:w1 | hit | 24.04 ms | 26.66 ms | 26.66 ms | 5 | 4.3 | 0 | yes |
| large-sets | heavy:w1 | miss | 23.57 ms | 28.28 ms | 28.28 ms | 5 | 4.3 | 0 | yes |
| large-sets | heavy:w1 | permission_set | 28.4 ms | 34.25 ms | 34.25 ms | 6 | 4.3 | 0 | no |
| large-sets | heavy:w1 | probe.snapshot | 3.59 ms | 5.82 ms | 5.82 ms | 0 | 17.3 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.38 ms | 3.09 ms | 3.09 ms | 0 | 4.3 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 138.86 ms | 175.46 ms | 175.46 ms | 4 | 17.1 | 0 | no |
| large-sets | heavy:w4 | hit | 23.57 ms | 34.73 ms | 34.73 ms | 5 | 17.1 | 0 | no |
| large-sets | heavy:w4 | miss | 23.32 ms | 30.51 ms | 30.51 ms | 5 | 17.1 | 0 | no |
| large-sets | heavy:w4 | permission_set | 27.13 ms | 33.43 ms | 33.43 ms | 6 | 17.1 | 0 | no |
| large-sets | heavy:w4 | probe.snapshot | 3.47 ms | 6.26 ms | 10.66 ms | 0 | 68.4 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.39 ms | 3.24 ms | 3.24 ms | 0 | 17.1 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 8.27 ms | 10.47 ms | 16.04 ms | 5 | 56.2 | 0 | no |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 1.8 ms | 2.93 ms | 5.03 ms | 0 | 112.3 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 715 µs | 940 µs | 1.57 ms | 0 | 56.2 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 8.17 ms | 11.52 ms | 23.12 ms | 5 | 56.2 | 0 | no |
| cache-cold-warm | warm:w4 | probe.snapshot | 1.92 ms | 3.36 ms | 5.93 ms | 0 | 444.8 | 0 | no |
| cache-cold-warm | warm:w4 | warm_store | 8.14 ms | 12.31 ms | 18.78 ms | 5 | 444.8 | 0 | no |
| consistency-load | disjoint:w4 | check | 7.56 ms | 9.01 ms | 9.74 ms | 5 | 386.1 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_hold | 5.36 ms | 7.24 ms | 9.46 ms | 0 | 128.7 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_wait | 330 µs | 470 µs | 730 µs | 0 | 128.7 | 0 | no |
| consistency-load | disjoint:w4 | probe.snapshot | 1.66 ms | 2.22 ms | 2.67 ms | 0 | 386.1 | 0 | no |
| consistency-load | disjoint:w4 | write | 6.45 ms | 8.59 ms | 10.66 ms | 8.5 | 128.7 | 0 | no |
| consistency-load | hot:w4 | check | 7.72 ms | 10.44 ms | 12.33 ms | 5 | 370.5 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_hold | 5.53 ms | 6.88 ms | 9.68 ms | 0 | 123.5 | 0 | no |
| consistency-load | hot:w4 | probe.lock_wait | 330 µs | 510 µs | 590 µs | 0 | 123.5 | 0 | yes |
| consistency-load | hot:w4 | probe.snapshot | 1.69 ms | 2.48 ms | 5.22 ms | 0 | 370.5 | 0 | no |
| consistency-load | hot:w4 | write | 6.58 ms | 8.24 ms | 10.8 ms | 8.5 | 123.5 | 0 | no |
| consistency-load | paced50:w4 | check | 7.61 ms | 10.1 ms | 13.04 ms | 5 | 152.1 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_hold | 6.48 ms | 8.43 ms | 11.79 ms | 0 | 50.7 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_wait | 620 µs | 970 µs | 1.09 ms | 0 | 50.7 | 0 | yes |
| consistency-load | paced50:w4 | probe.snapshot | 1.69 ms | 2.35 ms | 2.87 ms | 0 | 152.1 | 0 | yes |
| consistency-load | paced50:w4 | write | 19.86 ms | 22.26 ms | 23.51 ms | 8.5 | 50.7 | 0 | yes |
| consistency-load | paced200:w4 | check | 7.43 ms | 8.89 ms | 9.31 ms | 5 | 393.9 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_hold | 5.34 ms | 6.8 ms | 8.61 ms | 0 | 131.3 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_wait | 310 µs | 560 µs | 630 µs | 0 | 131.3 | 0 | no |
| consistency-load | paced200:w4 | probe.snapshot | 1.62 ms | 2.12 ms | 2.48 ms | 0 | 393.9 | 0 | yes |
| consistency-load | paced200:w4 | write | 6.31 ms | 7.98 ms | 9.46 ms | 8.5 | 131.3 | 0 | yes |
| decision-set | set100:w1 | probe.snapshot | 103.14 ms | 131.75 ms | 131.75 ms | 0 | 2 | 0 | yes |
| decision-set | set100:w1 | set | 481.98 ms | 585.82 ms | 585.82 ms | 302 | 2 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 264.61 ms | 299.93 ms | 299.93 ms | 0 | 0.9 | 0 | yes |
| decision-set | set250:w1 | set | 1125.23 ms | 1263.13 ms | 1263.13 ms | 754 | 0.9 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 511.04 ms | 635.06 ms | 635.06 ms | 0 | 0.4 | 0 | yes |
| decision-set | set500:w1 | set | 2263.79 ms | 2535.84 ms | 2535.84 ms | 1506 | 0.4 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 1035.77 ms | 1137.7 ms | 1137.7 ms | 0 | 0.2 | 0 | yes |
| decision-set | set1000:w1 | set | 4526.71 ms | 4746 ms | 4746 ms | 3012 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 2139.43 ms | 2224.65 ms | 2224.65 ms | 0 | 0.1 | 0 | yes |
| decision-set | set2000:w1 | set | 9118.8 ms | 9963.76 ms | 9963.76 ms | 6024 | 0.1 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 6.56 ms | 9.65 ms | 54.67 ms | 0 | 7.5 | 0 | no |
| decision-set | writer500:w2 | probe.lock_wait | 730 µs | 1.1 ms | 1.34 ms | 0 | 7.5 | 0 | no |
| decision-set | writer500:w2 | probe.snapshot | 529.18 ms | 1162.31 ms | 1162.31 ms | 0 | 0.3 | 0 | no |
| decision-set | writer500:w2 | set | 2417.01 ms | 3415.73 ms | 3415.73 ms | 1506 | 0.3 | 0 | no |
| decision-set | writer500:w2 | write_burst | 1527.66 ms | 1554.74 ms | 1554.74 ms | 192.8 | 0.3 | 0 | no |

Checks:

- PASS consistency-load/disjoint:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/hot:w4: every written subject ends in the state of its last write (0 of 1 wrong)
- PASS consistency-load/paced50:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS consistency-load/paced200:w4: every written subject ends in the state of its last write (0 of 50 wrong)
- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
