# AzGuard bench: smoke tier, sqlite, cache none

Commit 617c371ba978, 2026-11-01T19:59:17Z; PHP 8.4.1, Laravel 13.39.0, sqlite 3.43.2, Intel(R) Core(TM) i7-14700K (28 CPUs), OPcache off

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| automatic-roles | automatic-1-100 | check | 918 µs | 1.44 ms | 1.78 ms | 0 | 940.4 | 0 | yes |
| automatic-roles | automatic-1-500 | check | 950 µs | 1.44 ms | 1.89 ms | 0 | 786.7 | 0 | yes |
| automatic-roles | automatic-10-100 | check | 985 µs | 1.05 ms | 1.26 ms | 0 | 843.1 | 0 | yes |
| automatic-roles | automatic-10-500 | check | 1.24 ms | 1.97 ms | 2.19 ms | 0 | 647.5 | 0 | yes |
| automatic-roles | automatic-100-100 | check | 1.7 ms | 1.98 ms | 3.1 ms | 0 | 282 | 0 | yes |
| automatic-roles | automatic-100-500 | check | 1.66 ms | 2.05 ms | 2.07 ms | 0 | 492.8 | 0 | yes |
| lock-contention | disjoint:writers1:readers1 | check | 1.73 ms | 2.97 ms | 3.4 ms | 5 | 456.9 | 0 | yes |
| lock-contention | disjoint:writers1:readers1 | probe.lock_hold | 947 µs | 2.52 ms | 2.54 ms | 0 | 456.9 | 0 | yes |
| lock-contention | disjoint:writers1:readers1 | probe.lock_wait | 10 µs | 20 µs | 30 µs | 0 | 456.9 | 0 | yes |
| lock-contention | disjoint:writers1:readers1 | probe.snapshot | 166 µs | 197 µs | 287 µs | 0 | 456.9 | 0 | yes |
| lock-contention | disjoint:writers1:readers1 | write | 1.12 ms | 2.66 ms | 2.77 ms | 9.5 | 456.9 | 0 | yes |
| lock-contention | hot-subject:writers1:readers1 | check | 1.72 ms | 3.21 ms | 3.7 ms | 5 | 451.1 | 0 | yes |
| lock-contention | hot-subject:writers1:readers1 | probe.lock_hold | 930 µs | 2.29 ms | 2.44 ms | 0 | 451.1 | 0 | yes |
| lock-contention | hot-subject:writers1:readers1 | probe.lock_wait | 10 µs | 20 µs | 50 µs | 0 | 451.1 | 0 | yes |
| lock-contention | hot-subject:writers1:readers1 | probe.snapshot | 163 µs | 276 µs | 305 µs | 0 | 451.1 | 0 | yes |
| lock-contention | hot-subject:writers1:readers1 | write | 1.08 ms | 2.44 ms | 2.61 ms | 9.5 | 451.1 | 0 | yes |
| lock-contention | hot-panel:writers1:readers1 | check | 2.58 ms | 2.98 ms | 5.18 ms | 5 | 365.8 | 0 | yes |
| lock-contention | hot-panel:writers1:readers1 | probe.lock_hold | 1.52 ms | 2.74 ms | 3.56 ms | 0 | 365.8 | 0 | yes |
| lock-contention | hot-panel:writers1:readers1 | probe.lock_wait | 20 µs | 30 µs | 60 µs | 0 | 365.8 | 0 | yes |
| lock-contention | hot-panel:writers1:readers1 | probe.snapshot | 262 µs | 321 µs | 346 µs | 0 | 365.8 | 0 | yes |
| lock-contention | hot-panel:writers1:readers1 | write | 1.81 ms | 3.01 ms | 3.81 ms | 9.5 | 365.8 | 0 | yes |

Checks:

- PASS automatic-roles/automatic-1-100: automatic grants and denials (first permission granted, last denied)
- PASS automatic-roles/automatic-1-500: automatic grants and denials (first permission granted, last denied)
- PASS automatic-roles/automatic-10-100: automatic grants and denials (first permission granted, last denied)
- PASS automatic-roles/automatic-10-500: automatic grants and denials (first permission granted, last denied)
- PASS automatic-roles/automatic-100-100: automatic grants and denials (first permission granted, last denied)
- PASS automatic-roles/automatic-100-500: automatic grants and denials (first permission granted, last denied)
- PASS lock-contention/disjoint:writers1:readers1: all writers end in their last state (0 of 1 incorrect)
- MEASURE lock-contention/disjoint:writers1:readers1 rep 1: [{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0},{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0}]
- PASS lock-contention/hot-subject:writers1:readers1: all writers end in their last state (0 of 1 incorrect)
- MEASURE lock-contention/hot-subject:writers1:readers1 rep 1: [{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0},{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0}]
- PASS lock-contention/hot-panel:writers1:readers1: all writers end in their last state (0 of 1 incorrect)
- MEASURE lock-contention/hot-panel:writers1:readers1 rep 1: [{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0},{"retries":0,"deadlocks":0,"timeouts":0,"lock_conflicts":0,"serialization_failures":0,"other_failures":0,"transaction_rollbacks":0,"uncaught_deadlocks":0,"uncaught_timeouts":0}]
