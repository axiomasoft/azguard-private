# AzGuard bench: mini tier, mysql, cache none

Commit f956510cb270, 2026-10-09T09:31:00Z; PHP 8.4.26, Laravel 13.35.0, mysql 8.4.11, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 5.62 ms | 7.47 ms | 8.41 ms | 5 | 170.6 | 0 | yes |
| checks-concurrent | check:w2 | check | 5.68 ms | 7.51 ms | 8.9 ms | 5 | 334.7 | 0 | yes |
| checks-concurrent | check:w4 | check | 5.63 ms | 7.25 ms | 8.7 ms | 5 | 674.6 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 204.2 ms | 245.37 ms | 245.37 ms | 5 | 3.5 | 0 | no |
| large-sets | heavy:w1 | hit | 20.79 ms | 24.31 ms | 24.31 ms | 5 | 3.5 | 0 | yes |
| large-sets | heavy:w1 | miss | 20.85 ms | 34.82 ms | 34.82 ms | 5 | 3.5 | 0 | no |
| large-sets | heavy:w1 | permission_set | 21.8 ms | 26.53 ms | 26.53 ms | 6 | 3.5 | 0 | yes |
| large-sets | heavy:w1 | same_request | 2.29 ms | 3.21 ms | 3.21 ms | 0 | 3.5 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 217.75 ms | 266.66 ms | 266.66 ms | 5 | 13.4 | 0 | yes |
| large-sets | heavy:w4 | hit | 20.77 ms | 25.41 ms | 25.41 ms | 5 | 13.4 | 0 | yes |
| large-sets | heavy:w4 | miss | 19.77 ms | 26.6 ms | 26.6 ms | 5 | 13.4 | 0 | yes |
| large-sets | heavy:w4 | permission_set | 22.18 ms | 27.64 ms | 27.64 ms | 6 | 13.4 | 0 | yes |
| large-sets | heavy:w4 | same_request | 2.24 ms | 3.3 ms | 3.3 ms | 0 | 13.4 | 0 | yes |
| visible-at-scale | visible:w1 | count | 7.77 ms | 9.68 ms | 10.11 ms | 7 | 60.2 | 0 | yes |
| visible-at-scale | visible:w1 | page | 8.47 ms | 10.02 ms | 12.11 ms | 7 | 60.2 | 0 | yes |
| visible-at-scale | visible:w2 | count | 6.94 ms | 8.43 ms | 8.51 ms | 7 | 132.1 | 0 | yes |
| visible-at-scale | visible:w2 | page | 7.72 ms | 9.28 ms | 9.97 ms | 7 | 132.1 | 0 | yes |
| visible-at-scale | visible:w4 | count | 7.09 ms | 8.41 ms | 9.55 ms | 7 | 257.9 | 0 | yes |
| visible-at-scale | visible:w4 | page | 7.73 ms | 9.26 ms | 9.91 ms | 7 | 257.9 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 5.54 ms | 7.38 ms | 8.38 ms | 5 | 82.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 584 µs | 753 µs | 845 µs | 0 | 82.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 5.6 ms | 7.13 ms | 8.26 ms | 5 | 82.6 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 5.94 ms | 8.29 ms | 12.3 ms | 5 | 625.4 | 0 | no |
| grant-revoke-load | writes:w2 | check_inconsistent | 17.29 ms | 21.35 ms | 22.47 ms | 15 | 19.6 | 0 | no |
| grant-revoke-load | writes:w2 | check_under_writes | 5.86 ms | 15.69 ms | 19.74 ms | 5.68 | 88 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 4.74 ms | 6.1 ms | 8.34 ms | 6 | 53 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 5.6 ms | 8.03 ms | 22.51 ms | 7 | 53 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 17.22 ms | 21.73 ms | 31.4 ms | 15 | 75.4 | 0 | yes |
| grant-revoke-load | writes:w4 | check_under_writes | 6.25 ms | 16.14 ms | 17.76 ms | 5.93 | 90.8 | 0 | yes |
| grant-revoke-load | writes:w4 | grant | 7.96 ms | 11.99 ms | 23 ms | 6 | 81.1 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 9.62 ms | 13.18 ms | 17.84 ms | 7 | 81.1 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 4.05 ms | 5.34 ms | 9.25 ms | 6 | 78.1 | 0 | no |
| multi-tenant | tenant:w1 | member | 4.06 ms | 5.72 ms | 8.77 ms | 6 | 78.1 | 0 | yes |
| multi-tenant | tenant:w1 | outsider | 3.35 ms | 5.53 ms | 9.46 ms | 5 | 76.9 | 0 | no |
| multi-tenant | tenant:w2 | admin_update | 4.14 ms | 5.39 ms | 8.79 ms | 6 | 159 | 0 | no |
| multi-tenant | tenant:w2 | member | 4.24 ms | 5.56 ms | 9.92 ms | 6 | 159 | 0 | no |
| multi-tenant | tenant:w2 | outsider | 3.63 ms | 4.75 ms | 6.21 ms | 5 | 156.7 | 0 | no |
| multi-tenant | tenant:w4 | admin_update | 4.57 ms | 7.64 ms | 12.94 ms | 6 | 273.4 | 0 | yes |
| multi-tenant | tenant:w4 | member | 4.75 ms | 7.28 ms | 10.19 ms | 6 | 273.4 | 0 | yes |
| multi-tenant | tenant:w4 | outsider | 4 ms | 6.02 ms | 9.99 ms | 5 | 269.3 | 0 | yes |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
