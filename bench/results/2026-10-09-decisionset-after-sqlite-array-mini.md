# AzGuard bench: mini tier, sqlite, cache array

Commit aad41106db18, 2026-10-09T21:35:53Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 570 µs | 720 µs | 720 µs | 0 | 3.9 | 0 | no |
| decision-set | set100:w1 | set | 255.59 ms | 265.73 ms | 265.73 ms | 3 | 3.9 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 1.07 ms | 1.36 ms | 1.36 ms | 0 | 1.5 | 0 | yes |
| decision-set | set250:w1 | set | 661.9 ms | 707.87 ms | 707.87 ms | 5 | 1.5 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 1.75 ms | 2.17 ms | 2.17 ms | 0 | 0.7 | 0 | no |
| decision-set | set500:w1 | set | 1348.76 ms | 1401.6 ms | 1401.6 ms | 7 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 4.8 ms | 35.83 ms | 35.83 ms | 0 | 0.4 | 0 | yes |
| decision-set | set1000:w1 | set | 2689.22 ms | 2884.6 ms | 2884.6 ms | 13 | 0.4 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 9.22 ms | 12.1 ms | 12.1 ms | 0 | 0.2 | 0 | no |
| decision-set | set2000:w1 | set | 5377.61 ms | 5740.32 ms | 5740.32 ms | 25 | 0.2 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 2.96 ms | 4.09 ms | 6.73 ms | 0 | 18.4 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 90 µs | 130 µs | 160 µs | 0 | 18.4 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 1.83 ms | 2.69 ms | 2.69 ms | 0 | 0.7 | 0 | yes |
| decision-set | writer500:w2 | set | 1309.84 ms | 1541.87 ms | 1541.87 ms | 7 | 0.7 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1516.19 ms | 1528.64 ms | 1528.64 ms | 230.6 | 0.7 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
