# AzGuard bench: mini tier, mariadb, cache none

Commit aad41106db18, 2026-10-09T21:47:14Z; PHP 8.4.26, Laravel 13.35.0, mariadb 10.11.19-MariaDB-ubu2204, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| decision-set | set100:w1 | probe.snapshot | 10.83 ms | 15.42 ms | 15.42 ms | 0 | 3.2 | 0 | no |
| decision-set | set100:w1 | set | 311.4 ms | 330.76 ms | 330.76 ms | 5 | 3.2 | 0 | no |
| decision-set | set250:w1 | probe.snapshot | 17.7 ms | 18.92 ms | 18.92 ms | 0 | 1.3 | 0 | yes |
| decision-set | set250:w1 | set | 743.23 ms | 785.25 ms | 785.25 ms | 7 | 1.3 | 0 | yes |
| decision-set | set500:w1 | probe.snapshot | 30.6 ms | 38.4 ms | 38.4 ms | 0 | 0.7 | 0 | no |
| decision-set | set500:w1 | set | 1518.02 ms | 1584.94 ms | 1584.94 ms | 9 | 0.7 | 0 | yes |
| decision-set | set1000:w1 | probe.snapshot | 65.09 ms | 70.88 ms | 70.88 ms | 0 | 0.3 | 0 | yes |
| decision-set | set1000:w1 | set | 3084.2 ms | 3233.19 ms | 3233.19 ms | 17 | 0.3 | 0 | yes |
| decision-set | set2000:w1 | probe.snapshot | 132.19 ms | 185.19 ms | 185.19 ms | 0 | 0.2 | 0 | yes |
| decision-set | set2000:w1 | set | 5879.42 ms | 6076.2 ms | 6076.2 ms | 33 | 0.2 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_hold | 7.5 ms | 9.98 ms | 54.37 ms | 0 | 15.7 | 0 | yes |
| decision-set | writer500:w2 | probe.lock_wait | 910 µs | 1.21 ms | 1.43 ms | 0 | 15.7 | 0 | yes |
| decision-set | writer500:w2 | probe.snapshot | 30.7 ms | 35.29 ms | 35.29 ms | 0 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | set | 1593.53 ms | 1708.63 ms | 1708.63 ms | 9 | 0.6 | 0 | yes |
| decision-set | writer500:w2 | write_burst | 1526.53 ms | 1557.27 ms | 1557.27 ms | 192.2 | 0.6 | 0 | yes |

Checks:

- PASS decision-set/set100:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set250:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set500:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set1000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/set2000:w1: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
- PASS decision-set/writer500:w2: a set over decision_sets.max_subjects is refused, not split (5001 subjects)
