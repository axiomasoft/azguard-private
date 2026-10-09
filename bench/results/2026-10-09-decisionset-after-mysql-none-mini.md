# AzGuard bench: mini tier, mysql, cache none

Commit 18a83eaa6612, 2026-10-09T22:46:47Z; PHP 8.4.26, Laravel 13.35.0, mysql 8.4.11, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 8.25 ms | 11.55 ms | 11.55 ms | 0 | 3.5 | 0 | yes |
| decision-set | set100:w1 | set | 281.39 ms | 295.89 ms | 295.89 ms | 5 | 3.5 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 15.6 ms | 18.57 ms | 18.57 ms | 0 | 1.5 | 0 | yes |
| decision-set | set250:w1 | set | 689.88 ms | 715.3 ms | 715.3 ms | 7 | 1.5 | 0 | no |
| decision-set | set500:w1 | probe.snapshot | 27.26 ms | 32.78 ms | 32.78 ms | 0 | 0.7 | 0 | no |
| decision-set | set500:w1 | set | 1335.95 ms | 1378.44 ms | 1378.44 ms | 9 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 54.22 ms | 59.65 ms | 59.65 ms | 0 | 0.4 | 0 | yes |
| decision-set | set1000:w1 | set | 2643.84 ms | 2848.43 ms | 2848.43 ms | 17 | 0.4 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 110.84 ms | 175.79 ms | 175.79 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 5304.78 ms | 5842.91 ms | 5842.91 ms | 33 | 0.2 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 6.07 ms | 7.94 ms | 13.05 ms | 0 | 17.1 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 630 µs | 960 µs | 1.18 ms | 0 | 17.1 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 25.84 ms | 35.71 ms | 35.71 ms | 0 | 0.7 | 0 | yes |
| decision-set | writer500:w2 | set | 1334.97 ms | 1591.95 ms | 1591.95 ms | 9 | 0.7 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1515.36 ms | 1558.11 ms | 1558.11 ms | 196.6 | 0.7 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
