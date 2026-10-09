# AzGuard bench: mini tier, sqlite, cache array

Commit c04529e8f288, 2026-10-09T16:36:01Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 4.44 ms | 6.18 ms | 6.83 ms | 3.67 | 224.1 | 0 | yes |
| checks-concurrent | check:w1 | probe.snapshot | 450 µs | 675 µs | 806 µs | 0 | 124.4 | 0 | yes |
| checks-concurrent | check:w2 | check | 4.87 ms | 6.57 ms | 7.09 ms | 3.67 | 389.5 | 0 | yes |
| checks-concurrent | check:w2 | probe.snapshot | 543 µs | 646 µs | 898 µs | 0 | 216.2 | 0 | yes |
| checks-concurrent | check:w4 | check | 4.44 ms | 6.1 ms | 6.76 ms | 3.67 | 879.1 | 0 | no |
| checks-concurrent | check:w4 | probe.snapshot | 448 µs | 626 µs | 782 µs | 0 | 490.1 | 0 | no |
| large-sets | heavy:w1 | abilities50 | 101.81 ms | 109.31 ms | 109.31 ms | 2 | 7.8 | 0 | yes |
| large-sets | heavy:w1 | hit | 6.25 ms | 7.53 ms | 7.53 ms | 2 | 7.8 | 0 | no |
| large-sets | heavy:w1 | miss | 6.06 ms | 9.27 ms | 9.27 ms | 2 | 7.8 | 0 | no |
| large-sets | heavy:w1 | permission_set | 7.79 ms | 10.69 ms | 10.69 ms | 3 | 7.8 | 0 | no |
| large-sets | heavy:w1 | probe.snapshot | 90 µs | 120 µs | 120 µs | 0 | 7.8 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.24 ms | 2.99 ms | 2.99 ms | 0 | 7.8 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 97.55 ms | 115.82 ms | 115.82 ms | 2 | 30.6 | 0 | yes |
| large-sets | heavy:w4 | hit | 5.45 ms | 7.81 ms | 7.81 ms | 2 | 30.6 | 0 | yes |
| large-sets | heavy:w4 | miss | 5.89 ms | 8.62 ms | 8.62 ms | 2 | 30.6 | 0 | yes |
| large-sets | heavy:w4 | permission_set | 7.36 ms | 9.19 ms | 9.19 ms | 3 | 30.6 | 0 | yes |
| large-sets | heavy:w4 | probe.snapshot | 70 µs | 110 µs | 110 µs | 0 | 30.6 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.17 ms | 3.41 ms | 3.41 ms | 0 | 30.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 4.52 ms | 5.46 ms | 6.15 ms | 5 | 114.7 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 422 µs | 568 µs | 603 µs | 0 | 114.7 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 531 µs | 677 µs | 746 µs | 0 | 114.7 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 3.29 ms | 4.19 ms | 5.22 ms | 2 | 114.7 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 3.32 ms | 4.7 ms | 5.25 ms | 2 | 1069.9 | 0 | no |
| consistency-load | disjoint:w4 | check_hit | 3.48 ms | 4.63 ms | 4.93 ms | 2 | 806.3 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_hold | 2.32 ms | 3.33 ms | 3.9 ms | 0 | 268.8 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_wait | 30 µs | 50 µs | 100 µs | 0 | 268.8 | 0 | yes |
| consistency-load | disjoint:w4 | write | 2.81 ms | 4.06 ms | 4.58 ms | 9.5 | 268.8 | 0 | yes |
| consistency-load | hot:w4 | check | 4.98 ms | 6.83 ms | 7.06 ms | 5 | 335.5 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 3.41 ms | 4.67 ms | 4.98 ms | 2 | 261.8 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_hold | 2.32 ms | 3.32 ms | 4.24 ms | 0 | 198.4 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_wait | 30 µs | 50 µs | 70 µs | 0 | 198.4 | 0 | yes |
| consistency-load | hot:w4 | probe.snapshot | 459 µs | 698 µs | 801 µs | 0 | 335.5 | 0 | yes |
| consistency-load | hot:w4 | write | 2.8 ms | 3.96 ms | 5.21 ms | 9.5 | 198.4 | 0 | yes |
| consistency-load | paced50:w4 | check | 4.96 ms | 6.39 ms | 7.12 ms | 5 | 33 | 0 | yes |
| consistency-load | paced50:w4 | check_hit | 3.38 ms | 4.4 ms | 4.88 ms | 2 | 119.4 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_hold | 3.16 ms | 4.26 ms | 4.86 ms | 0 | 50.8 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_wait | 100 µs | 130 µs | 140 µs | 0 | 50.8 | 0 | yes |
| consistency-load | paced50:w4 | probe.snapshot | 471 µs | 698 µs | 839 µs | 0 | 33 | 0 | yes |
| consistency-load | paced50:w4 | write | 20 ms | 21.1 ms | 21.87 ms | 9.5 | 50.8 | 0 | yes |
| consistency-load | paced200:w4 | check | 5.13 ms | 6.97 ms | 7.22 ms | 5 | 224.4 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 3.76 ms | 4.98 ms | 5.32 ms | 2 | 382 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_hold | 2.5 ms | 3.28 ms | 3.56 ms | 0 | 202.1 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_wait | 30 µs | 50 µs | 90 µs | 0 | 202.1 | 0 | yes |
| consistency-load | paced200:w4 | probe.snapshot | 481 µs | 665 µs | 746 µs | 0 | 224.4 | 0 | yes |
| consistency-load | paced200:w4 | write | 3.06 ms | 4.03 ms | 4.32 ms | 9.5 | 202.1 | 0 | yes |
| decision-set | set100:w1 | probe.snapshot | 500 µs | 640 µs | 640 µs | 0 | 3.5 | 0 | no |
| decision-set | set100:w1 | set | 281.99 ms | 311.36 ms | 311.36 ms | 102 | 3.5 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 950 µs | 1.41 ms | 1.41 ms | 0 | 1.4 | 0 | no |
| decision-set | set250:w1 | set | 703.04 ms | 731.88 ms | 731.88 ms | 254 | 1.4 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 1.81 ms | 3.44 ms | 3.44 ms | 0 | 0.7 | 0 | no |
| decision-set | set500:w1 | set | 1434.18 ms | 1518.89 ms | 1518.89 ms | 506 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 13.86 ms | 15.77 ms | 15.77 ms | 0 | 0.4 | 0 | no |
| decision-set | set1000:w1 | set | 2775.08 ms | 2888.22 ms | 2888.22 ms | 1012 | 0.4 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 9.68 ms | 11.93 ms | 11.93 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 6115.79 ms | 6381.56 ms | 6381.56 ms | 2024 | 0.2 | 0 | no |
| decision-set | writer500:w2 | probe.lock_hold | 3.11 ms | 4.66 ms | 9.67 ms | 0 | 18.3 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 110 µs | 160 µs | 260 µs | 0 | 18.3 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 1.93 ms | 2.97 ms | 2.97 ms | 0 | 0.7 | 0 | no |
| decision-set | writer500:w2 | set | 1422.07 ms | 1577.78 ms | 1577.78 ms | 506 | 0.7 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1519.9 ms | 1533.57 ms | 1533.57 ms | 229.6 | 0.7 | 0 | yes |

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
