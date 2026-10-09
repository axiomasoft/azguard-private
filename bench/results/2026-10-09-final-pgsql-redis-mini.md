# AzGuard bench: mini tier, pgsql, cache redis

Commit c04529e8f288, 2026-10-09T16:56:32Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 5.82 ms | 7.34 ms | 8.02 ms | 2 | 171.4 | 0 | no |
| checks-concurrent | check:w1 | probe.snapshot | 2.64 ms | 3.41 ms | 3.74 ms | 0 | 64.6 | 0 | yes |
| checks-concurrent | check:w2 | check | 5.2 ms | 6.83 ms | 7.73 ms | 2 | 360.3 | 0 | no |
| checks-concurrent | check:w2 | probe.snapshot | 2.04 ms | 2.85 ms | 3.77 ms | 0 | 40.9 | 0 | yes |
| checks-concurrent | check:w4 | check | 5.27 ms | 6.89 ms | 9.46 ms | 2 | 697.3 | 0 | no |
| checks-concurrent | check:w4 | probe.snapshot | 2.6 ms | 7.49 ms | 7.49 ms | 0 | 11.3 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 103.67 ms | 112.84 ms | 112.84 ms | 2 | 7.1 | 0 | no |
| large-sets | heavy:w1 | hit | 8.4 ms | 10.31 ms | 10.31 ms | 2 | 7.1 | 0 | yes |
| large-sets | heavy:w1 | miss | 8.84 ms | 11.2 ms | 11.2 ms | 2 | 7.1 | 0 | no |
| large-sets | heavy:w1 | permission_set | 11.72 ms | 14.12 ms | 14.12 ms | 3 | 7.1 | 0 | no |
| large-sets | heavy:w1 | probe.snapshot | 800 µs | 1.25 ms | 1.25 ms | 0 | 7.1 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.31 ms | 3.12 ms | 3.12 ms | 0 | 7.1 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 107.07 ms | 131.44 ms | 131.44 ms | 2 | 28.2 | 0 | yes |
| large-sets | heavy:w4 | hit | 8.05 ms | 10.14 ms | 10.14 ms | 2 | 28.2 | 0 | yes |
| large-sets | heavy:w4 | miss | 8.4 ms | 10.48 ms | 10.48 ms | 2 | 28.2 | 0 | no |
| large-sets | heavy:w4 | permission_set | 10.92 ms | 14.32 ms | 14.32 ms | 3 | 28.2 | 0 | yes |
| large-sets | heavy:w4 | probe.snapshot | 650 µs | 980 µs | 980 µs | 0 | 28.2 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.34 ms | 3.03 ms | 3.03 ms | 0 | 28.2 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 9.31 ms | 11.95 ms | 13.3 ms | 5 | 64.4 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 2.14 ms | 3.13 ms | 3.77 ms | 0 | 64.4 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 614 µs | 850 µs | 1.15 ms | 0 | 64.4 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 5.05 ms | 6.79 ms | 7.49 ms | 2 | 64.4 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 5.11 ms | 6.41 ms | 7.06 ms | 2 | 737 | 0 | no |
| consistency-load | disjoint:w4 | check_hit | 5.81 ms | 6.81 ms | 7.72 ms | 2 | 333.4 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_hold | 7.46 ms | 9.55 ms | 10.27 ms | 0 | 111.1 | 0 | yes |
| consistency-load | disjoint:w4 | probe.lock_wait | 420 µs | 720 µs | 960 µs | 0 | 111.1 | 0 | yes |
| consistency-load | disjoint:w4 | write | 8.83 ms | 11.06 ms | 11.61 ms | 8.5 | 111.1 | 0 | yes |
| consistency-load | hot:w4 | check | 10.24 ms | 13.25 ms | 14.15 ms | 5 | 238.9 | 0 | yes |
| consistency-load | hot:w4 | check_hit | 6.16 ms | 7.34 ms | 8.27 ms | 2 | 50.3 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_hold | 7.99 ms | 11.38 ms | 13.96 ms | 0 | 99.9 | 0 | yes |
| consistency-load | hot:w4 | probe.lock_wait | 460 µs | 910 µs | 1.59 ms | 0 | 99.9 | 0 | yes |
| consistency-load | hot:w4 | probe.snapshot | 2.6 ms | 3.72 ms | 4.19 ms | 0 | 238.9 | 0 | yes |
| consistency-load | hot:w4 | write | 9.28 ms | 13.5 ms | 15.54 ms | 8.5 | 99.9 | 0 | yes |
| consistency-load | paced50:w4 | check | 9.77 ms | 12.61 ms | 14.77 ms | 5 | 19.8 | 0 | no |
| consistency-load | paced50:w4 | check_hit | 5.31 ms | 6.57 ms | 8.26 ms | 2 | 132.1 | 0 | no |
| consistency-load | paced50:w4 | probe.lock_hold | 8.12 ms | 11.83 ms | 14.07 ms | 0 | 50.6 | 0 | no |
| consistency-load | paced50:w4 | probe.lock_wait | 630 µs | 1.05 ms | 1.24 ms | 0 | 50.6 | 0 | yes |
| consistency-load | paced50:w4 | probe.snapshot | 2.47 ms | 3.37 ms | 5.74 ms | 0 | 19.8 | 0 | no |
| consistency-load | paced50:w4 | write | 19.85 ms | 22.84 ms | 26.27 ms | 8.5 | 50.6 | 0 | yes |
| consistency-load | paced200:w4 | check | 9.66 ms | 11.35 ms | 12.28 ms | 5 | 71.8 | 0 | yes |
| consistency-load | paced200:w4 | check_hit | 5.46 ms | 6.49 ms | 6.91 ms | 2 | 293.3 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_hold | 6.94 ms | 8.99 ms | 9.55 ms | 0 | 121.7 | 0 | yes |
| consistency-load | paced200:w4 | probe.lock_wait | 400 µs | 640 µs | 810 µs | 0 | 121.7 | 0 | yes |
| consistency-load | paced200:w4 | probe.snapshot | 2.41 ms | 3.01 ms | 3.94 ms | 0 | 71.8 | 0 | yes |
| consistency-load | paced200:w4 | write | 8.04 ms | 10.3 ms | 10.88 ms | 8.5 | 121.7 | 0 | yes |
| decision-set | set100:w1 | probe.snapshot | 1.9 ms | 2.48 ms | 2.48 ms | 0 | 2.8 | 0 | no |
| decision-set | set100:w1 | set | 342.61 ms | 382.82 ms | 382.82 ms | 102 | 2.8 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 2.76 ms | 2.86 ms | 2.86 ms | 0 | 1.2 | 0 | no |
| decision-set | set250:w1 | set | 830.05 ms | 914.49 ms | 914.49 ms | 254 | 1.2 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 3.95 ms | 4.5 ms | 4.5 ms | 0 | 0.6 | 0 | no |
| decision-set | set500:w1 | set | 1708.26 ms | 1760.85 ms | 1760.85 ms | 506 | 0.6 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 10.23 ms | 11.75 ms | 11.75 ms | 0 | 0.3 | 0 | yes |
| decision-set | set1000:w1 | set | 3431.81 ms | 3830.39 ms | 3830.39 ms | 1012 | 0.3 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 21.56 ms | 33.49 ms | 33.49 ms | 0 | 0.1 | 0 | no |
| decision-set | set2000:w1 | set | 6854.91 ms | 7140.91 ms | 7140.91 ms | 2024 | 0.1 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 7.7 ms | 11.94 ms | 56.17 ms | 0 | 13.3 | 0 | no |
| decision-set | writer500:w2 | probe.lock_wait | 770 µs | 1.16 ms | 1.53 ms | 0 | 13.3 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 4.15 ms | 5.25 ms | 5.25 ms | 0 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | set | 1750.04 ms | 1900.93 ms | 1900.93 ms | 506 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1521.99 ms | 1556.96 ms | 1556.96 ms | 189.3 | 0.6 | 0 | yes |

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
