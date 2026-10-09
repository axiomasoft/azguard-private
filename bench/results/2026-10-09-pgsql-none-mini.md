# AzGuard bench: mini tier, pgsql, cache none

Commit f956510cb270, 2026-10-09T09:27:51Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 6.89 ms | 9.11 ms | 9.59 ms | 5 | 137.8 | 0 | yes |
| checks-concurrent | check:w2 | check | 6.45 ms | 8.47 ms | 11.93 ms | 5 | 290.8 | 0 | yes |
| checks-concurrent | check:w4 | check | 6.94 ms | 11.44 ms | 15.98 ms | 5 | 496.9 | 0 | no |
| large-sets | heavy:w1 | abilities50 | 213.66 ms | 235.21 ms | 235.21 ms | 5 | 3.4 | 0 | yes |
| large-sets | heavy:w1 | hit | 21.39 ms | 25.52 ms | 25.52 ms | 5 | 3.4 | 0 | no |
| large-sets | heavy:w1 | miss | 21.5 ms | 25.74 ms | 25.74 ms | 5 | 3.4 | 0 | no |
| large-sets | heavy:w1 | permission_set | 23.52 ms | 28.69 ms | 28.69 ms | 6 | 3.4 | 0 | no |
| large-sets | heavy:w1 | same_request | 2.45 ms | 3.37 ms | 3.37 ms | 0 | 3.4 | 0 | no |
| large-sets | heavy:w4 | abilities50 | 223.14 ms | 273.72 ms | 273.72 ms | 5 | 13 | 0 | no |
| large-sets | heavy:w4 | hit | 21.1 ms | 27.14 ms | 27.14 ms | 5 | 13 | 0 | no |
| large-sets | heavy:w4 | miss | 21.77 ms | 35.06 ms | 35.06 ms | 5 | 13 | 0 | no |
| large-sets | heavy:w4 | permission_set | 22.07 ms | 45.2 ms | 45.2 ms | 6 | 13 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.38 ms | 3.62 ms | 3.62 ms | 0 | 13 | 0 | no |
| visible-at-scale | visible:w1 | count | 9.27 ms | 11.5 ms | 12.22 ms | 7 | 48.7 | 0 | no |
| visible-at-scale | visible:w1 | page | 10.42 ms | 12.67 ms | 13.76 ms | 7 | 48.7 | 0 | yes |
| visible-at-scale | visible:w2 | count | 9.57 ms | 18.57 ms | 22.69 ms | 7 | 96.1 | 0 | no |
| visible-at-scale | visible:w2 | page | 10.33 ms | 14.28 ms | 16.37 ms | 7 | 96.1 | 0 | no |
| visible-at-scale | visible:w4 | count | 9.04 ms | 10.78 ms | 14.03 ms | 7 | 203.6 | 0 | yes |
| visible-at-scale | visible:w4 | page | 9.89 ms | 12.1 ms | 13.69 ms | 7 | 203.6 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 6.31 ms | 8.66 ms | 12.37 ms | 5 | 71.8 | 0 | no |
| cache-cold-warm | coldwarm:w1 | warm_request | 609 µs | 821 µs | 1.38 ms | 0 | 71.8 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 6.34 ms | 8.27 ms | 9.36 ms | 5 | 71.8 | 0 | no |
| cache-cold-warm | warm:w4 | warm_store | 6.83 ms | 9.52 ms | 12.45 ms | 5 | 552.8 | 0 | no |
| grant-revoke-load | writes:w2 | check_inconsistent | 19.05 ms | 22.47 ms | 22.47 ms | 15 | 19.6 | 0 | no |
| grant-revoke-load | writes:w2 | check_under_writes | 6.77 ms | 13.79 ms | 20.4 ms | 5.79 | 77.5 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 4.98 ms | 6.29 ms | 8 ms | 6 | 50.3 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 6.36 ms | 7.89 ms | 8.5 ms | 7 | 50.3 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 18.3 ms | 21.51 ms | 22.79 ms | 15 | 67.1 | 0 | no |
| grant-revoke-load | writes:w4 | check_under_writes | 6.57 ms | 18.72 ms | 20.96 ms | 6.27 | 87 | 0 | yes |
| grant-revoke-load | writes:w4 | grant | 8.95 ms | 10.78 ms | 12.64 ms | 6 | 77.7 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 11.6 ms | 14.5 ms | 19.3 ms | 7 | 77.7 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 4.62 ms | 5.87 ms | 6.68 ms | 6 | 73.8 | 0 | no |
| multi-tenant | tenant:w1 | member | 4.57 ms | 6.4 ms | 9.82 ms | 6 | 73.8 | 0 | yes |
| multi-tenant | tenant:w1 | outsider | 3.91 ms | 5.35 ms | 5.79 ms | 5 | 72.7 | 0 | yes |
| multi-tenant | tenant:w2 | admin_update | 4.63 ms | 6.6 ms | 10.08 ms | 6 | 141.9 | 0 | no |
| multi-tenant | tenant:w2 | member | 4.58 ms | 6.13 ms | 7.96 ms | 6 | 141.9 | 0 | no |
| multi-tenant | tenant:w2 | outsider | 4.04 ms | 5.35 ms | 9.14 ms | 5 | 139.8 | 0 | no |
| multi-tenant | tenant:w4 | admin_update | 5.05 ms | 7.96 ms | 11.74 ms | 6 | 252.2 | 0 | no |
| multi-tenant | tenant:w4 | member | 5.09 ms | 7.44 ms | 9.96 ms | 6 | 252.2 | 0 | yes |
| multi-tenant | tenant:w4 | outsider | 4.34 ms | 6.28 ms | 9.52 ms | 5 | 248.4 | 0 | yes |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
