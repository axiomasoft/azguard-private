# AzGuard bench: mini tier, sqlite, cache array

Commit f956510cb270, 2026-10-09T09:25:57Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 3.79 ms | 5.01 ms | 6.19 ms | 3.67 | 264.7 | 0 | no |
| checks-concurrent | check:w2 | check | 3.82 ms | 5.04 ms | 6.09 ms | 3.67 | 512.1 | 0 | yes |
| checks-concurrent | check:w4 | check | 3.76 ms | 5.13 ms | 6.15 ms | 3.67 | 1001.8 | 0 | no |
| large-sets | heavy:w1 | abilities50 | 176.67 ms | 190.43 ms | 190.43 ms | 2 | 4.8 | 0 | no |
| large-sets | heavy:w1 | hit | 5.24 ms | 7.13 ms | 7.13 ms | 2 | 4.8 | 0 | no |
| large-sets | heavy:w1 | miss | 5.58 ms | 7.91 ms | 7.91 ms | 2 | 4.8 | 0 | no |
| large-sets | heavy:w1 | permission_set | 7.3 ms | 8.46 ms | 8.46 ms | 3 | 4.8 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.17 ms | 2.29 ms | 2.29 ms | 0 | 4.8 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 180.54 ms | 210.17 ms | 210.17 ms | 2 | 19 | 0 | no |
| large-sets | heavy:w4 | hit | 5.33 ms | 8.18 ms | 8.18 ms | 2 | 19 | 0 | no |
| large-sets | heavy:w4 | miss | 5.48 ms | 7.74 ms | 7.74 ms | 2 | 19 | 0 | no |
| large-sets | heavy:w4 | permission_set | 6.78 ms | 8.03 ms | 8.03 ms | 3 | 19 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.13 ms | 2.78 ms | 2.78 ms | 0 | 19 | 0 | no |
| visible-at-scale | visible:w1 | count | 4.64 ms | 5.5 ms | 6.39 ms | 8 | 99.9 | 0 | yes |
| visible-at-scale | visible:w1 | page | 4.88 ms | 6.39 ms | 6.98 ms | 8 | 99.9 | 0 | yes |
| visible-at-scale | visible:w2 | count | 4.72 ms | 5.59 ms | 6.93 ms | 8 | 190.8 | 0 | yes |
| visible-at-scale | visible:w2 | page | 5 ms | 6.38 ms | 6.82 ms | 8 | 190.8 | 0 | yes |
| visible-at-scale | visible:w4 | count | 4.69 ms | 6.24 ms | 6.81 ms | 8 | 366.6 | 0 | yes |
| visible-at-scale | visible:w4 | page | 5.01 ms | 6.56 ms | 7.3 ms | 8 | 366.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 3.87 ms | 5.31 ms | 7.03 ms | 5 | 127.3 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 491 µs | 675 µs | 751 µs | 0 | 127.3 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 2.98 ms | 4.23 ms | 4.57 ms | 2 | 127.3 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 3.03 ms | 3.97 ms | 4.53 ms | 2 | 1225.1 | 0 | yes |
| grant-revoke-load | writes:w2 | check_inconsistent | 11.04 ms | 12.54 ms | 14.77 ms | 15 | 37 | 0 | yes |
| grant-revoke-load | writes:w2 | check_under_writes | 3.76 ms | 5.24 ms | 11.92 ms | 3.81 | 141.5 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 2.28 ms | 2.85 ms | 5.57 ms | 7 | 88.4 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 2.56 ms | 3.48 ms | 7.28 ms | 8 | 88.4 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 10.74 ms | 16.44 ms | 16.86 ms | 15 | 110.9 | 0 | no |
| grant-revoke-load | writes:w4 | check_under_writes | 3.88 ms | 7 ms | 12.95 ms | 4.75 | 150.8 | 0 | no |
| grant-revoke-load | writes:w4 | grant | 2.27 ms | 6.61 ms | 56.8 ms | 7 | 130.4 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 2.61 ms | 6.32 ms | 36.82 ms | 8 | 130.4 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 1.62 ms | 3.15 ms | 4.05 ms | 3.58 | 154.8 | 0 | yes |
| multi-tenant | tenant:w1 | member | 2.28 ms | 3.32 ms | 3.94 ms | 5.51 | 154.8 | 0 | yes |
| multi-tenant | tenant:w1 | outsider | 2.06 ms | 2.84 ms | 3.64 ms | 5 | 152.5 | 0 | yes |
| multi-tenant | tenant:w2 | admin_update | 1.51 ms | 2.52 ms | 3.36 ms | 3.56 | 323.7 | 0 | yes |
| multi-tenant | tenant:w2 | member | 2.25 ms | 2.82 ms | 3.28 ms | 5.4 | 323.7 | 0 | yes |
| multi-tenant | tenant:w2 | outsider | 2.03 ms | 2.55 ms | 2.98 ms | 4.95 | 318.9 | 0 | yes |
| multi-tenant | tenant:w4 | admin_update | 1.48 ms | 2.68 ms | 3.88 ms | 3.5 | 638.8 | 0 | yes |
| multi-tenant | tenant:w4 | member | 2.22 ms | 3.08 ms | 4.3 ms | 5.31 | 638.8 | 0 | yes |
| multi-tenant | tenant:w4 | outsider | 2.02 ms | 2.73 ms | 3.16 ms | 4.98 | 629.3 | 0 | yes |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
