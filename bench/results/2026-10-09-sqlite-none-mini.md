# AzGuard bench: mini tier, sqlite, cache none

Commit f956510cb270, 2026-10-09T09:24:51Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 3.7 ms | 5.14 ms | 7.41 ms | 5 | 239.9 | 0 | yes |
| checks-concurrent | check:w2 | check | 3.85 ms | 5.15 ms | 5.97 ms | 5 | 484 | 0 | yes |
| checks-concurrent | check:w4 | check | 3.78 ms | 5.41 ms | 6.1 ms | 5 | 942.6 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 196.7 ms | 210.21 ms | 210.21 ms | 5 | 3.9 | 0 | no |
| large-sets | heavy:w1 | hit | 16.33 ms | 17.88 ms | 17.88 ms | 5 | 3.9 | 0 | yes |
| large-sets | heavy:w1 | miss | 16.89 ms | 19.75 ms | 19.75 ms | 5 | 3.9 | 0 | no |
| large-sets | heavy:w1 | permission_set | 17.6 ms | 19.11 ms | 19.11 ms | 6 | 3.9 | 0 | yes |
| large-sets | heavy:w1 | same_request | 2.2 ms | 2.31 ms | 2.31 ms | 0 | 3.9 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 200.01 ms | 224.25 ms | 224.25 ms | 5 | 14.6 | 0 | no |
| large-sets | heavy:w4 | hit | 16.73 ms | 20.97 ms | 20.97 ms | 5 | 14.6 | 0 | no |
| large-sets | heavy:w4 | miss | 16.24 ms | 22.61 ms | 22.61 ms | 5 | 14.6 | 0 | yes |
| large-sets | heavy:w4 | permission_set | 17.62 ms | 24.26 ms | 24.26 ms | 6 | 14.6 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.11 ms | 2.96 ms | 2.96 ms | 0 | 14.6 | 0 | no |
| visible-at-scale | visible:w1 | count | 4.74 ms | 5.38 ms | 5.63 ms | 8 | 98.3 | 0 | yes |
| visible-at-scale | visible:w1 | page | 5.03 ms | 6.32 ms | 7.3 ms | 8 | 98.3 | 0 | yes |
| visible-at-scale | visible:w2 | count | 4.68 ms | 6.08 ms | 6.67 ms | 8 | 188.6 | 0 | yes |
| visible-at-scale | visible:w2 | page | 4.98 ms | 6.85 ms | 8.57 ms | 8 | 188.6 | 0 | yes |
| visible-at-scale | visible:w4 | count | 4.72 ms | 6.83 ms | 7.54 ms | 8 | 351.7 | 0 | yes |
| visible-at-scale | visible:w4 | page | 5 ms | 7.2 ms | 8.71 ms | 8 | 351.7 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 3.85 ms | 5.47 ms | 5.77 ms | 5 | 105.9 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 524 µs | 703 µs | 789 µs | 0 | 105.9 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 3.93 ms | 5.42 ms | 5.71 ms | 5 | 105.9 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 3.94 ms | 5.74 ms | 5.84 ms | 5 | 704.3 | 0 | yes |
| grant-revoke-load | writes:w2 | check_inconsistent | 10.84 ms | 14.45 ms | 15.76 ms | 15 | 30.8 | 0 | yes |
| grant-revoke-load | writes:w2 | check_under_writes | 4.28 ms | 6.25 ms | 15 ms | 5.18 | 123.6 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 2.28 ms | 3.41 ms | 5.91 ms | 7 | 77.2 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 2.57 ms | 3.35 ms | 6.72 ms | 8 | 77.2 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 10.54 ms | 14.43 ms | 16.01 ms | 15 | 107.9 | 0 | yes |
| grant-revoke-load | writes:w4 | check_under_writes | 3.98 ms | 10.38 ms | 11.11 ms | 5.76 | 144.3 | 0 | yes |
| grant-revoke-load | writes:w4 | grant | 2.42 ms | 6.25 ms | 36.99 ms | 7 | 125.5 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 2.72 ms | 3.63 ms | 6.3 ms | 8 | 125.5 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 2.12 ms | 2.84 ms | 4.18 ms | 6 | 151 | 0 | yes |
| multi-tenant | tenant:w1 | member | 2.15 ms | 2.87 ms | 9.32 ms | 6 | 151 | 0 | no |
| multi-tenant | tenant:w1 | outsider | 1.91 ms | 2.59 ms | 3.51 ms | 5 | 148.7 | 0 | yes |
| multi-tenant | tenant:w2 | admin_update | 2.23 ms | 3.17 ms | 3.91 ms | 6 | 276.8 | 0 | no |
| multi-tenant | tenant:w2 | member | 2.24 ms | 3.07 ms | 8.97 ms | 6 | 276.8 | 0 | no |
| multi-tenant | tenant:w2 | outsider | 1.97 ms | 2.91 ms | 3.53 ms | 5 | 272.7 | 0 | no |
| multi-tenant | tenant:w4 | admin_update | 2.22 ms | 3.12 ms | 3.74 ms | 6 | 569.6 | 0 | yes |
| multi-tenant | tenant:w4 | member | 2.19 ms | 2.97 ms | 3.66 ms | 6 | 569.6 | 0 | yes |
| multi-tenant | tenant:w4 | outsider | 1.97 ms | 2.69 ms | 3.15 ms | 5 | 561.1 | 0 | yes |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
