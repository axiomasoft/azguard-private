# AzGuard bench: mini tier, pgsql, cache redis

Commit f956510cb270, 2026-10-09T09:29:32Z; PHP 8.4.26, Laravel 13.35.0, pgsql 16.15, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 4.42 ms | 5.76 ms | 11.49 ms | 2 | 214.2 | 0 | no |
| checks-concurrent | check:w2 | check | 4.13 ms | 5.35 ms | 6.05 ms | 2 | 454.1 | 0 | no |
| checks-concurrent | check:w4 | check | 4.3 ms | 5.76 ms | 8.55 ms | 2 | 855 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 185.13 ms | 195.82 ms | 195.82 ms | 2 | 4.6 | 0 | no |
| large-sets | heavy:w1 | hit | 7.8 ms | 9.22 ms | 9.22 ms | 2 | 4.6 | 0 | yes |
| large-sets | heavy:w1 | miss | 8.16 ms | 9.76 ms | 9.76 ms | 2 | 4.6 | 0 | no |
| large-sets | heavy:w1 | permission_set | 9.82 ms | 11.62 ms | 11.62 ms | 3 | 4.6 | 0 | yes |
| large-sets | heavy:w1 | same_request | 2.2 ms | 2.57 ms | 2.57 ms | 0 | 4.6 | 0 | yes |
| large-sets | heavy:w4 | abilities50 | 185.51 ms | 230.73 ms | 230.73 ms | 2 | 17.4 | 0 | no |
| large-sets | heavy:w4 | hit | 6.95 ms | 8.99 ms | 8.99 ms | 2 | 17.4 | 0 | no |
| large-sets | heavy:w4 | miss | 7.46 ms | 9.84 ms | 9.84 ms | 2 | 17.4 | 0 | no |
| large-sets | heavy:w4 | permission_set | 9.25 ms | 11.59 ms | 11.59 ms | 3 | 17.4 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.23 ms | 2.99 ms | 2.99 ms | 0 | 17.4 | 0 | yes |
| visible-at-scale | visible:w1 | count | 9.11 ms | 11.98 ms | 12.87 ms | 7 | 49.4 | 0 | no |
| visible-at-scale | visible:w1 | page | 9.74 ms | 13.11 ms | 18.4 ms | 7 | 49.4 | 0 | no |
| visible-at-scale | visible:w2 | count | 8.8 ms | 10.83 ms | 11.24 ms | 7 | 104.7 | 0 | yes |
| visible-at-scale | visible:w2 | page | 9.35 ms | 11.73 ms | 12.77 ms | 7 | 104.7 | 0 | yes |
| visible-at-scale | visible:w4 | count | 9.14 ms | 10.69 ms | 11.75 ms | 7 | 202.8 | 0 | yes |
| visible-at-scale | visible:w4 | page | 9.84 ms | 11.75 ms | 12.64 ms | 7 | 202.8 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 7.38 ms | 9.55 ms | 19.07 ms | 5 | 76.7 | 0 | no |
| cache-cold-warm | coldwarm:w1 | warm_request | 552 µs | 764 µs | 1.37 ms | 0 | 76.7 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 4.25 ms | 5.37 ms | 5.94 ms | 2 | 76.7 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 4.36 ms | 5.64 ms | 6.41 ms | 2 | 856 | 0 | no |
| grant-revoke-load | writes:w2 | check_inconsistent | 19.18 ms | 23.53 ms | 23.53 ms | 15 | 19.6 | 0 | no |
| grant-revoke-load | writes:w2 | check_under_writes | 6.69 ms | 16.74 ms | 26.28 ms | 4.75 | 81.2 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 5.2 ms | 6.56 ms | 9.97 ms | 6 | 52 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 6.47 ms | 8.09 ms | 8.85 ms | 7 | 52 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 19.12 ms | 24.31 ms | 31.44 ms | 15 | 65.3 | 0 | no |
| grant-revoke-load | writes:w4 | check_under_writes | 6.87 ms | 19.32 ms | 22.18 ms | 5.68 | 80.7 | 0 | no |
| grant-revoke-load | writes:w4 | grant | 9.34 ms | 12.26 ms | 24.57 ms | 6 | 73.4 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 11.52 ms | 19.17 ms | 21.99 ms | 7 | 73.4 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 2.99 ms | 3.87 ms | 5.81 ms | 3 | 116.1 | 0 | no |
| multi-tenant | tenant:w1 | member | 3.03 ms | 3.95 ms | 4.34 ms | 3 | 116.1 | 0 | no |
| multi-tenant | tenant:w1 | outsider | 2.38 ms | 3.01 ms | 3.39 ms | 2 | 114.3 | 0 | no |
| multi-tenant | tenant:w2 | admin_update | 2.93 ms | 4.2 ms | 5.13 ms | 3 | 227.1 | 0 | no |
| multi-tenant | tenant:w2 | member | 2.98 ms | 4.3 ms | 5.54 ms | 3 | 227.1 | 0 | no |
| multi-tenant | tenant:w2 | outsider | 2.35 ms | 3.18 ms | 4.45 ms | 2 | 223.7 | 0 | no |
| multi-tenant | tenant:w4 | admin_update | 3.05 ms | 3.8 ms | 4.68 ms | 3 | 449.1 | 0 | yes |
| multi-tenant | tenant:w4 | member | 3.08 ms | 3.84 ms | 4.88 ms | 3 | 449.1 | 0 | no |
| multi-tenant | tenant:w4 | outsider | 2.43 ms | 3.01 ms | 5.3 ms | 2 | 442.4 | 0 | no |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
