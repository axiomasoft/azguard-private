# AzGuard bench: mini tier, pgsql, cache redis

Commit aad41106db18, 2026-10-09T21:42:11Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 2.21 ms | 2.53 ms | 2.53 ms | 0 | 3.5 | 0 | no |
| decision-set | set100:w1 | set | 286.79 ms | 322.31 ms | 322.31 ms | 3 | 3.5 | 0 | yes |
| decision-set | set250:w1 | probe.snapshot | 3 ms | 3.68 ms | 3.68 ms | 0 | 1.4 | 0 | yes |
| decision-set | set250:w1 | set | 688.92 ms | 787.84 ms | 787.84 ms | 5 | 1.4 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 4.4 ms | 5.38 ms | 5.38 ms | 0 | 0.7 | 0 | yes |
| decision-set | set500:w1 | set | 1337.42 ms | 1425.01 ms | 1425.01 ms | 7 | 0.7 | 0 | no |
| decision-set | set1000:w1 | probe.snapshot | 9.65 ms | 11.05 ms | 11.05 ms | 0 | 0.4 | 0 | yes |
| decision-set | set1000:w1 | set | 2710.11 ms | 2870.41 ms | 2870.41 ms | 13 | 0.4 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 20.87 ms | 22.29 ms | 22.29 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 5359.65 ms | 5605.26 ms | 5605.26 ms | 25 | 0.2 | 0 | no |
| decision-set | writer500:w2 | probe.lock_hold | 7.35 ms | 9.88 ms | 11.17 ms | 0 | 16.6 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 760 µs | 1.13 ms | 1.49 ms | 0 | 16.6 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 4.39 ms | 5.21 ms | 5.21 ms | 0 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | set | 1330.87 ms | 1459.77 ms | 1459.77 ms | 7 | 0.6 | 0 | no |
| decision-set | writer500:w2 | write_burst | 1539.2 ms | 1557.4 ms | 1557.4 ms | 194.7 | 0.6 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
