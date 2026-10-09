# AzGuard bench: mini tier, sqlite, cache none

Commit c04529e8f288, 2026-10-09T16:29:19Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 4.55 ms | 5.87 ms | 6.43 ms | 5 | 207.9 | 0 | yes |
| checks-concurrent | check:w1 | probe.snapshot | 435 µs | 559 µs | 688 µs | 0 | 207.9 | 0 | yes |
| checks-concurrent | check:w2 | check | 4.7 ms | 6.42 ms | 6.66 ms | 5 | 398.3 | 0 | yes |
| checks-concurrent | check:w2 | probe.snapshot | 440 µs | 615 µs | 714 µs | 0 | 398.3 | 0 | yes |
| checks-concurrent | check:w4 | check | 4.48 ms | 5.91 ms | 6.46 ms | 5 | 827.7 | 0 | yes |
| checks-concurrent | check:w4 | probe.snapshot | 415 µs | 604 µs | 728 µs | 0 | 827.7 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 125.98 ms | 129.54 ms | 129.54 ms | 4 | 5.2 | 0 | yes |
| large-sets | heavy:w1 | hit | 19 ms | 22.95 ms | 22.95 ms | 5 | 5.2 | 0 | no |
| large-sets | heavy:w1 | miss | 19.39 ms | 23.23 ms | 23.23 ms | 5 | 5.2 | 0 | no |
| large-sets | heavy:w1 | permission_set | 21.2 ms | 26.88 ms | 26.88 ms | 6 | 5.2 | 0 | no |
| large-sets | heavy:w1 | probe.snapshot | 1.68 ms | 3.56 ms | 3.56 ms | 0 | 20.8 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.31 ms | 2.45 ms | 2.45 ms | 0 | 5.2 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 123.27 ms | 139.48 ms | 139.48 ms | 4 | 20.7 | 0 | no |
| large-sets | heavy:w4 | hit | 18.87 ms | 24.19 ms | 24.19 ms | 5 | 20.7 | 0 | no |
| large-sets | heavy:w4 | miss | 18.86 ms | 24.28 ms | 24.28 ms | 5 | 20.7 | 0 | no |
| large-sets | heavy:w4 | permission_set | 21.42 ms | 29.96 ms | 29.96 ms | 6 | 20.7 | 0 | no |
| large-sets | heavy:w4 | probe.snapshot | 1.83 ms | 3.22 ms | 3.63 ms | 0 | 82.8 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.21 ms | 2.89 ms | 2.89 ms | 0 | 20.7 | 0 | no |
| cache-cold-warm | coldwarm:w1 | cold | 5.11 ms | 6.35 ms | 7.1 ms | 5 | 90.8 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 490 µs | 649 µs | 707 µs | 0 | 181.5 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 581 µs | 754 µs | 780 µs | 0 | 90.8 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 5.19 ms | 6.23 ms | 6.63 ms | 5 | 90.8 | 0 | yes |
| cache-cold-warm | warm:w4 | probe.snapshot | 423 µs | 600 µs | 757 µs | 0 | 759.7 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 4.57 ms | 6.11 ms | 6.7 ms | 5 | 759.7 | 0 | yes |
| consistency-load | disjoint:w4 | check | 4.79 ms | 6.39 ms | 7.14 ms | 5 | 568.1 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_hold | 2.51 ms | 3.66 ms | 7.23 ms | 0 | 189.4 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_wait | 40 µs | 50 µs | 90 µs | 0 | 189.4 | 0 | no |
| consistency-load | disjoint:w4 | probe.snapshot | 465 µs | 659 µs | 910 µs | 0 | 568.1 | 0 | no |
| consistency-load | disjoint:w4 | write | 3.03 ms | 4.43 ms | 8.01 ms | 9.5 | 189.4 | 0 | no |
| consistency-load | hot:w4 | check | 4.73 ms | 6.6 ms | 7.62 ms | 5 | 551 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_hold | 2.42 ms | 3.32 ms | 4.8 ms | 0 | 183.7 | 0 | no |
| consistency-load | hot:w4 | probe.lock_wait | 30 µs | 50 µs | 90 µs | 0 | 183.7 | 0 | yes |
| consistency-load | hot:w4 | probe.snapshot | 454 µs | 698 µs | 946 µs | 0 | 551 | 0 | yes |
| consistency-load | hot:w4 | write | 2.97 ms | 3.95 ms | 5.39 ms | 9.5 | 183.7 | 0 | no |
| consistency-load | paced50:w4 | check | 4.74 ms | 6.18 ms | 6.71 ms | 5 | 152.4 | 0 | no |
| consistency-load | paced50:w4 | probe.lock_hold | 3.09 ms | 4.11 ms | 4.82 ms | 0 | 50.8 | 0 | no |
| consistency-load | paced50:w4 | probe.lock_wait | 100 µs | 130 µs | 150 µs | 0 | 50.8 | 0 | yes |
| consistency-load | paced50:w4 | probe.snapshot | 451 µs | 658 µs | 712 µs | 0 | 152.4 | 0 | no |
| consistency-load | paced50:w4 | write | 19.94 ms | 21.04 ms | 21.73 ms | 9.5 | 50.8 | 0 | yes |
| consistency-load | paced200:w4 | check | 4.67 ms | 6.15 ms | 6.63 ms | 5 | 591.1 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_hold | 2.26 ms | 3.33 ms | 3.7 ms | 0 | 197.1 | 0 | no |
| consistency-load | paced200:w4 | probe.lock_wait | 30 µs | 50 µs | 70 µs | 0 | 197.1 | 0 | yes |
| consistency-load | paced200:w4 | probe.snapshot | 442 µs | 631 µs | 781 µs | 0 | 591.1 | 0 | yes |
| consistency-load | paced200:w4 | write | 2.76 ms | 3.99 ms | 4.49 ms | 9.5 | 197.1 | 0 | yes |
| decision-set | set100:w1 | probe.snapshot | 32.74 ms | 37.57 ms | 37.57 ms | 0 | 2.9 | 0 | no |
| decision-set | set100:w1 | set | 345.36 ms | 399.69 ms | 399.69 ms | 302 | 2.9 | 0 | no |
| decision-set | set250:w1 | probe.snapshot | 87.78 ms | 102.89 ms | 102.89 ms | 0 | 1.1 | 0 | no |
| decision-set | set250:w1 | set | 868.68 ms | 927.16 ms | 927.16 ms | 754 | 1.1 | 0 | no |
| decision-set | set500:w1 | probe.snapshot | 166.81 ms | 190.73 ms | 190.73 ms | 0 | 0.6 | 0 | no |
| decision-set | set500:w1 | set | 1708.08 ms | 1787.35 ms | 1787.35 ms | 1506 | 0.6 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 343.59 ms | 358.25 ms | 358.25 ms | 0 | 0.3 | 0 | yes |
| decision-set | set1000:w1 | set | 3386.68 ms | 3661.8 ms | 3661.8 ms | 3012 | 0.3 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 714.43 ms | 796.94 ms | 796.94 ms | 0 | 0.1 | 0 | no |
| decision-set | set2000:w1 | set | 7047.92 ms | 7236.81 ms | 7236.81 ms | 6024 | 0.1 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 3.13 ms | 4.31 ms | 5.41 ms | 0 | 15.4 | 0 | no |
| decision-set | writer500:w2 | probe.lock_wait | 110 µs | 160 µs | 210 µs | 0 | 15.4 | 0 | no |
| decision-set | writer500:w2 | probe.snapshot | 178.17 ms | 237.98 ms | 237.98 ms | 0 | 0.5 | 0 | no |
| decision-set | writer500:w2 | set | 1781.29 ms | 1971.83 ms | 1971.83 ms | 1506 | 0.5 | 0 | no |
| decision-set | writer500:w2 | write_burst | 1521.26 ms | 1531.17 ms | 1531.17 ms | 230.6 | 0.5 | 0 | no |

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
