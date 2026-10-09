# AzGuard bench: mini tier, pgsql, cache none

Commit aad41106db18, 2026-10-09T21:56:00Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 8.94 ms | 12.15 ms | 12.15 ms | 0 | 3.5 | 0 | yes |
| decision-set | set100:w1 | set | 287.41 ms | 299.44 ms | 299.44 ms | 5 | 3.5 | 0 | no |
| decision-set | set250:w1 | probe.snapshot | 14.49 ms | 16.66 ms | 16.66 ms | 0 | 1.5 | 0 | yes |
| decision-set | set250:w1 | set | 676.94 ms | 710.43 ms | 710.43 ms | 7 | 1.5 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 28.36 ms | 35.82 ms | 35.82 ms | 0 | 0.7 | 0 | yes |
| decision-set | set500:w1 | set | 1338.65 ms | 1396.77 ms | 1396.77 ms | 9 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 63.29 ms | 64.48 ms | 64.48 ms | 0 | 0.3 | 0 | yes |
| decision-set | set1000:w1 | set | 2847.78 ms | 3012.21 ms | 3012.21 ms | 17 | 0.3 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 125.92 ms | 178.85 ms | 178.85 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 5595.22 ms | 5853.33 ms | 5853.33 ms | 33 | 0.2 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 6.9 ms | 9.6 ms | 10.52 ms | 0 | 16.8 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 730 µs | 1.09 ms | 1.34 ms | 0 | 16.8 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 27.53 ms | 31.82 ms | 31.82 ms | 0 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | set | 1395.92 ms | 1457.58 ms | 1457.58 ms | 9 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1534.53 ms | 1547.57 ms | 1547.57 ms | 195.2 | 0.6 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
