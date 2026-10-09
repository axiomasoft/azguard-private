# AzGuard bench: mini tier, pgsql, cache none

Commit c04529e8f288, 2026-10-09T16:49:32Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 8.34 ms | 11.17 ms | 16.25 ms | 5 | 107.9 | 0 | yes |
| checks-concurrent | check:w1 | probe.snapshot | 2.22 ms | 3.21 ms | 3.99 ms | 0 | 107.9 | 0 | yes |
| checks-concurrent | check:w2 | check | 8.31 ms | 10.13 ms | 11.26 ms | 5 | 233.1 | 0 | yes |
| checks-concurrent | check:w2 | probe.snapshot | 2.24 ms | 3.09 ms | 3.39 ms | 0 | 233.1 | 0 | yes |
| checks-concurrent | check:w4 | check | 8.04 ms | 9.99 ms | 11.55 ms | 5 | 482.4 | 0 | yes |
| checks-concurrent | check:w4 | probe.snapshot | 2.18 ms | 2.88 ms | 3.86 ms | 0 | 482.4 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 128.58 ms | 151.34 ms | 151.34 ms | 4 | 4.5 | 0 | yes |
| large-sets | heavy:w1 | hit | 24.94 ms | 27.17 ms | 27.17 ms | 5 | 4.5 | 0 | yes |
| large-sets | heavy:w1 | miss | 25.66 ms | 27.53 ms | 27.53 ms | 5 | 4.5 | 0 | yes |
| large-sets | heavy:w1 | permission_set | 28.49 ms | 30.51 ms | 30.51 ms | 6 | 4.5 | 0 | yes |
| large-sets | heavy:w1 | probe.snapshot | 4.49 ms | 6.97 ms | 6.97 ms | 0 | 18.1 | 0 | yes |
| large-sets | heavy:w1 | same_request | 2.47 ms | 3.06 ms | 3.06 ms | 0 | 4.5 | 0 | yes |
| large-sets | heavy:w4 | abilities50 | 130.58 ms | 167.86 ms | 167.86 ms | 4 | 18 | 0 | yes |
| large-sets | heavy:w4 | hit | 24.42 ms | 29.91 ms | 29.91 ms | 5 | 18 | 0 | yes |
| large-sets | heavy:w4 | miss | 23.78 ms | 29.04 ms | 29.04 ms | 5 | 18 | 0 | yes |
| large-sets | heavy:w4 | permission_set | 28.21 ms | 38.18 ms | 38.18 ms | 6 | 18 | 0 | no |
| large-sets | heavy:w4 | probe.snapshot | 4.27 ms | 6.05 ms | 7.95 ms | 0 | 71.9 | 0 | yes |
| large-sets | heavy:w4 | same_request | 2.39 ms | 3.5 ms | 3.5 ms | 0 | 18 | 0 | no |
| cache-cold-warm | coldwarm:w1 | cold | 8.24 ms | 11.49 ms | 19.38 ms | 5 | 55.5 | 0 | no |
| cache-cold-warm | coldwarm:w1 | probe.snapshot | 2.18 ms | 3.18 ms | 4.12 ms | 0 | 111.1 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 640 µs | 984 µs | 1.93 ms | 0 | 55.5 | 0 | no |
| cache-cold-warm | coldwarm:w1 | warm_store | 8.3 ms | 10.84 ms | 27.62 ms | 5 | 55.5 | 0 | no |
| cache-cold-warm | warm:w4 | probe.snapshot | 2.19 ms | 3.16 ms | 5.21 ms | 0 | 447.1 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 8.33 ms | 10.52 ms | 16.15 ms | 5 | 447.1 | 0 | yes |
| consistency-load | disjoint:w4 | check | 8.86 ms | 10.59 ms | 13.01 ms | 5 | 331.6 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_hold | 7.09 ms | 9.36 ms | 11.5 ms | 0 | 110.5 | 0 | no |
| consistency-load | disjoint:w4 | probe.lock_wait | 400 µs | 740 µs | 1.86 ms | 0 | 110.5 | 0 | no |
| consistency-load | disjoint:w4 | probe.snapshot | 2.33 ms | 3.3 ms | 5.49 ms | 0 | 331.6 | 0 | no |
| consistency-load | disjoint:w4 | write | 8.39 ms | 10.81 ms | 13.71 ms | 8.5 | 110.5 | 0 | no |
| consistency-load | hot:w4 | check | 8.96 ms | 13.11 ms | 17.5 ms | 5 | 310.2 | 0 | no |
| consistency-load | hot:w4 | probe.lock_hold | 7.14 ms | 9.74 ms | 12.9 ms | 0 | 103.4 | 0 | no |
| consistency-load | hot:w4 | probe.lock_wait | 390 µs | 640 µs | 1.07 ms | 0 | 103.4 | 0 | no |
| consistency-load | hot:w4 | probe.snapshot | 2.31 ms | 4.06 ms | 5.7 ms | 0 | 310.2 | 0 | no |
| consistency-load | hot:w4 | write | 8.25 ms | 11.16 ms | 14.81 ms | 8.5 | 103.4 | 0 | no |
| consistency-load | paced50:w4 | check | 8.4 ms | 10.23 ms | 14.99 ms | 5 | 152 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_hold | 7.72 ms | 10.24 ms | 13.69 ms | 0 | 50.7 | 0 | yes |
| consistency-load | paced50:w4 | probe.lock_wait | 550 µs | 950 µs | 1.17 ms | 0 | 50.7 | 0 | no |
| consistency-load | paced50:w4 | probe.snapshot | 2.25 ms | 2.83 ms | 4.78 ms | 0 | 152 | 0 | no |
| consistency-load | paced50:w4 | write | 19.93 ms | 22.78 ms | 25.61 ms | 8.5 | 50.7 | 0 | yes |
| consistency-load | paced200:w4 | check | 8.41 ms | 10.98 ms | 13.47 ms | 5 | 328.9 | 0 | no |
| consistency-load | paced200:w4 | probe.lock_hold | 6.86 ms | 9.56 ms | 12.29 ms | 0 | 109.6 | 0 | no |
| consistency-load | paced200:w4 | probe.lock_wait | 380 µs | 700 µs | 860 µs | 0 | 109.6 | 0 | no |
| consistency-load | paced200:w4 | probe.snapshot | 2.28 ms | 3.33 ms | 5.37 ms | 0 | 328.9 | 0 | no |
| consistency-load | paced200:w4 | write | 8.06 ms | 11.11 ms | 14.23 ms | 8.5 | 109.6 | 0 | no |
| decision-set | set100:w1 | probe.snapshot | 167.36 ms | 185.83 ms | 185.83 ms | 0 | 1.8 | 0 | no |
| decision-set | set100:w1 | set | 539.69 ms | 624.94 ms | 624.94 ms | 302 | 1.8 | 0 | no |
| decision-set | set250:w1 | probe.snapshot | 449.61 ms | 487.16 ms | 487.16 ms | 0 | 0.7 | 0 | no |
| decision-set | set250:w1 | set | 1342.11 ms | 1392.96 ms | 1392.96 ms | 754 | 0.7 | 0 | no |
| decision-set | set500:w1 | probe.snapshot | 812.87 ms | 915.54 ms | 915.54 ms | 0 | 0.4 | 0 | yes |
| decision-set | set500:w1 | set | 2708.61 ms | 2772.98 ms | 2772.98 ms | 1506 | 0.4 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 1603.98 ms | 1725.87 ms | 1725.87 ms | 0 | 0.2 | 0 | yes |
| decision-set | set1000:w1 | set | 5306.01 ms | 5439.82 ms | 5439.82 ms | 3012 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 3251.65 ms | 3683.02 ms | 3683.02 ms | 0 | 0.1 | 0 | no |
| decision-set | set2000:w1 | set | 10885.39 ms | 11163.97 ms | 11163.97 ms | 6024 | 0.1 | 0 | no |
| decision-set | writer500:w2 | probe.lock_hold | 7.76 ms | 10.53 ms | 30.68 ms | 0 | 8.8 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 740 µs | 1.18 ms | 1.35 ms | 0 | 8.8 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 818.39 ms | 1023.74 ms | 1023.74 ms | 0 | 0.4 | 0 | no |
| decision-set | writer500:w2 | set | 2655.63 ms | 3200.36 ms | 3200.36 ms | 1506 | 0.4 | 0 | no |
| decision-set | writer500:w2 | write_burst | 1542.77 ms | 1558.86 ms | 1558.86 ms | 192.7 | 0.4 | 0 | no |

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
