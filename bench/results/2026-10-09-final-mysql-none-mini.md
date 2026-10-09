# AzGuard bench: mini tier, mysql, cache none

Commit c04529e8f288, 2026-10-09T17:11:16Z; PHP 8.4.26, Laravel 13.35.0, mysql 8.4.11, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 7.07 ms | 9.41 ms | 10.4 ms | 5 | 136.3 | 0 | yes |
| checks-concurrent | check:w1 | probe.snapshot | 1.44 ms | 2.32 ms | 2.77 ms | 0 | 136.3 | 0 | yes |
| checks-concurrent | check:w2 | check | 7 ms | 8.88 ms | 10.28 ms | 5 | 277.1 | 0 | yes |
| checks-concurrent | check:w2 | probe.snapshot | 1.42 ms | 2.23 ms | 2.95 ms | 0 | 277.1 | 0 | yes |
| checks-concurrent | check:w4 | check | 6.97 ms | 9.22 ms | 11.24 ms | 5 | 545.4 | 0 | no |
| checks-concurrent | check:w4 | probe.snapshot | 1.5 ms | 2.24 ms | 4.14 ms | 0 | 545.4 | 0 | no |
| large-sets | heavy:w1 | abilities50 | 122.99 ms | 138.36 ms | 138.36 ms | 4 | 4.9 | 0 | yes |
| large-sets | heavy:w1 | hit | 22.82 ms | 25.15 ms | 25.15 ms | 5 | 4.9 | 0 | yes |
| large-sets | heavy:w1 | miss | 22.48 ms | 25.57 ms | 25.57 ms | 5 | 4.9 | 0 | yes |
| large-sets | heavy:w1 | permission_set | 25.14 ms | 28.65 ms | 28.65 ms | 6 | 4.9 | 0 | yes |
| large-sets | heavy:w1 | probe.snapshot | 3.38 ms | 5.25 ms | 5.25 ms | 0 | 19.6 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.33 ms | 2.96 ms | 2.96 ms | 0 | 4.9 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 133.77 ms | 152.04 ms | 152.04 ms | 4 | 18.2 | 0 | yes |
| large-sets | heavy:w4 | hit | 23.56 ms | 27.11 ms | 27.11 ms | 5 | 18.2 | 0 | no |
| large-sets | heavy:w4 | miss | 23.79 ms | 28.47 ms | 28.47 ms | 5 | 18.2 | 0 | yes |
| large-sets | heavy:w4 | permission_set | 26.1 ms | 31.46 ms | 31.46 ms | 6 | 18.2 | 0 | yes |
| large-sets | heavy:w4 | probe.snapshot | 3.6 ms | 5.15 ms | 5.85 ms | 0 | 72.7 | 0 | yes |
| large-sets | heavy:w4 | same_request | 2.38 ms | 3.11 ms | 3.11 ms | 0 | 18.2 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 7.8 ms | 10.14 ms | 13.17 ms | 5 | 59.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 1.7 ms | 2.55 ms | 3.2 ms | 0 | 119.3 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 671 µs | 878 µs | 942 µs | 0 | 59.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 7.94 ms | 10 ms | 10.76 ms | 5 | 59.6 | 0 | yes |
| cache-cold-warm | warm:w4 | probe.snapshot | 1.54 ms | 2.21 ms | 3.62 ms | 0 | 539 | 0 | no |
| cache-cold-warm | warm:w4 | warm_store | 7.19 ms | 8.79 ms | 10.53 ms | 5 | 539 | 0 | no |
| consistency-load | disjoint:w4 | check | 7.21 ms | 9.09 ms | 10.89 ms | 5 | 386.9 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_hold | 6.07 ms | 7.75 ms | 11.12 ms | 0 | 129 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_wait | 325 µs | 535 µs | 805 µs | 0 | 129 | 0 | no |
| consistency-load | disjoint:w4 | probe.snapshot | 1.57 ms | 2.21 ms | 2.6 ms | 0 | 386.9 | 0 | yes |
| consistency-load | disjoint:w4 | write | 7.2 ms | 9.51 ms | 12.26 ms | 8.5 | 129 | 0 | yes |
| consistency-load | hot:w4 | check | 7.44 ms | 9.31 ms | 11.26 ms | 5 | 386.4 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_hold | 5.97 ms | 8.33 ms | 9.74 ms | 0 | 128.8 | 0 | no |
| consistency-load | hot:w4 | probe.lock_wait | 320 µs | 480 µs | 930 µs | 0 | 128.8 | 0 | no |
| consistency-load | hot:w4 | probe.snapshot | 1.59 ms | 2.23 ms | 3.17 ms | 0 | 386.4 | 0 | yes |
| consistency-load | hot:w4 | write | 6.96 ms | 9.54 ms | 14.1 ms | 8.5 | 128.8 | 0 | no |
| consistency-load | paced50:w4 | check | 7.26 ms | 9.09 ms | 10.05 ms | 5 | 152 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_hold | 6.86 ms | 9.01 ms | 9.73 ms | 0 | 50.7 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_wait | 550 µs | 840 µs | 980 µs | 0 | 50.7 | 0 | yes |
| consistency-load | paced50:w4 | probe.snapshot | 1.56 ms | 2.15 ms | 2.32 ms | 0 | 152 | 0 | no |
| consistency-load | paced50:w4 | write | 19.85 ms | 22.01 ms | 22.56 ms | 8.5 | 50.7 | 0 | yes |
| consistency-load | paced200:w4 | check | 7.43 ms | 8.97 ms | 11.47 ms | 5 | 391.8 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_hold | 5.7 ms | 7.41 ms | 10.25 ms | 0 | 130.6 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_wait | 310 µs | 500 µs | 730 µs | 0 | 130.6 | 0 | no |
| consistency-load | paced200:w4 | probe.snapshot | 1.57 ms | 2.12 ms | 3.13 ms | 0 | 391.8 | 0 | yes |
| consistency-load | paced200:w4 | write | 6.78 ms | 8.72 ms | 11.35 ms | 8.5 | 130.6 | 0 | yes |
| decision-set | set100:w1 | probe.snapshot | 101.08 ms | 112.96 ms | 112.96 ms | 0 | 2.2 | 0 | yes |
| decision-set | set100:w1 | set | 455.93 ms | 461.23 ms | 461.23 ms | 302 | 2.2 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 220.92 ms | 246.85 ms | 246.85 ms | 0 | 0.9 | 0 | yes |
| decision-set | set250:w1 | set | 1089.07 ms | 1122.3 ms | 1122.3 ms | 754 | 0.9 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 477.39 ms | 538.96 ms | 538.96 ms | 0 | 0.5 | 0 | yes |
| decision-set | set500:w1 | set | 2209.04 ms | 2305.75 ms | 2305.75 ms | 1506 | 0.5 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 965.94 ms | 1072.93 ms | 1072.93 ms | 0 | 0.2 | 0 | yes |
| decision-set | set1000:w1 | set | 4360.55 ms | 4533.72 ms | 4533.72 ms | 3012 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 2434.6 ms | 3860.12 ms | 3860.12 ms | 0 | 0.1 | 0 | no |
| decision-set | set2000:w1 | set | 10322.59 ms | 13312.72 ms | 13312.72 ms | 6024 | 0.1 | 0 | no |
| decision-set | writer500:w2 | probe.lock_hold | 7.86 ms | 12.36 ms | 29.38 ms | 0 | 9.6 | 0 | no |
| decision-set | writer500:w2 | probe.lock_wait | 720 µs | 1.25 ms | 1.81 ms | 0 | 9.6 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 595.92 ms | 732.53 ms | 732.53 ms | 0 | 0.4 | 0 | yes |
| decision-set | writer500:w2 | set | 2482.71 ms | 2775.88 ms | 2775.88 ms | 1506 | 0.4 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1534.7 ms | 1558.23 ms | 1558.23 ms | 193 | 0.4 | 0 | yes |

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
