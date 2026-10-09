# AzGuard bench: mini tier, sqlite, cache none

Commit aad41106db18, 2026-10-09T21:31:41Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 5.99 ms | 8.31 ms | 8.31 ms | 0 | 3.2 | 0 | yes |
| decision-set | set100:w1 | set | 309.59 ms | 330.48 ms | 330.48 ms | 5 | 3.2 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 10.66 ms | 14.22 ms | 14.22 ms | 0 | 1.4 | 0 | yes |
| decision-set | set250:w1 | set | 736.38 ms | 750.51 ms | 750.51 ms | 7 | 1.4 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 22.61 ms | 30.09 ms | 30.09 ms | 0 | 0.7 | 0 | yes |
| decision-set | set500:w1 | set | 1440.8 ms | 1578.85 ms | 1578.85 ms | 9 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 46.42 ms | 50.4 ms | 50.4 ms | 0 | 0.3 | 0 | yes |
| decision-set | set1000:w1 | set | 2952.74 ms | 3065.18 ms | 3065.18 ms | 17 | 0.3 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 105.34 ms | 195.68 ms | 195.68 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 6173.15 ms | 7357.39 ms | 7357.39 ms | 33 | 0.2 | 0 | no |
| decision-set | writer500:w2 | probe.lock_hold | 3.29 ms | 4.58 ms | 5.74 ms | 0 | 17.9 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 120 µs | 170 µs | 230 µs | 0 | 17.9 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 23.48 ms | 28.74 ms | 28.74 ms | 0 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | set | 1522.42 ms | 1693.81 ms | 1693.81 ms | 9 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1525.29 ms | 1538.4 ms | 1538.4 ms | 230.6 | 0.6 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
