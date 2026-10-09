# AzGuard bench: mini tier, sqlite, cache none

Commit 1f5aae1ec4f2, 2026-10-09T09:13:56Z; PHP 8.4.26, Laravel 13.35.0, sqlite 3.46.1, Intel(R) Xeon(R) Processor (8 CPUs), OPcache on

| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |
|---|---|---|---:|---:|---:|---:|---:|---:|:-:|
| checks-concurrent | check:w1 | check | 3.81 ms | 5.25 ms | 5.58 ms | 5 | 244.7 | 0 | yes |
| checks-concurrent | check:w2 | check | 3.9 ms | 5.56 ms | 6.24 ms | 5 | 448.8 | 0 | no |
| checks-concurrent | check:w4 | check | 3.79 ms | 5.65 ms | 6.13 ms | 5 | 828.6 | 0 | yes |
| large-sets | heavy:w1 | abilities50 | 197.08 ms | 236.03 ms | 236.03 ms | 5 | 0.4 | 0 | yes |
| large-sets | heavy:w1 | hit | 15.97 ms | 19.96 ms | 19.96 ms | 5 | 0.4 | 0 | yes |
| large-sets | heavy:w1 | miss | 17.01 ms | 22.48 ms | 22.48 ms | 5 | 0.4 | 0 | yes |
| large-sets | heavy:w1 | permission_set | 2169.06 ms | 2238.46 ms | 2238.46 ms | 6 | 0.4 | 0 | yes |
| large-sets | heavy:w1 | same_request | 2.64 ms | 4.06 ms | 4.06 ms | 0 | 0.4 | 0 | yes |
| large-sets | heavy:w4 | abilities50 | 214.7 ms | 274.22 ms | 274.22 ms | 5 | 1.6 | 0 | no |
| large-sets | heavy:w4 | hit | 16.48 ms | 28.03 ms | 28.03 ms | 5 | 1.6 | 0 | no |
| large-sets | heavy:w4 | miss | 17.32 ms | 28.74 ms | 28.74 ms | 5 | 1.6 | 0 | no |
| large-sets | heavy:w4 | permission_set | 2148.08 ms | 2276.09 ms | 2276.09 ms | 6 | 1.6 | 0 | no |
| large-sets | heavy:w4 | same_request | 2.75 ms | 3.52 ms | 3.52 ms | 0 | 1.6 | 0 | yes |
| visible-at-scale | visible:w1 | count | 4.75 ms | 5.74 ms | 5.93 ms | 8 | 98.1 | 0 | yes |
| visible-at-scale | visible:w1 | page | 5.04 ms | 6.2 ms | 8.89 ms | 8 | 98.1 | 0 | yes |
| visible-at-scale | visible:w2 | count | 4.84 ms | 6.71 ms | 7.3 ms | 8 | 175.7 | 0 | yes |
| visible-at-scale | visible:w2 | page | 5.15 ms | 7.2 ms | 15.74 ms | 8 | 175.7 | 0 | yes |
| visible-at-scale | visible:w4 | count | 4.96 ms | 6.46 ms | 6.94 ms | 8 | 341 | 0 | yes |
| visible-at-scale | visible:w4 | page | 5.22 ms | 7.41 ms | 7.64 ms | 8 | 341 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | cold | 3.91 ms | 5.37 ms | 7.57 ms | 5 | 110.2 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_request | 543 µs | 712 µs | 753 µs | 0 | 110.2 | 0 | yes |
| cache-cold-warm | coldwarm:w1 | warm_store | 3.84 ms | 5.38 ms | 6.42 ms | 5 | 110.2 | 0 | yes |
| cache-cold-warm | warm:w4 | warm_store | 3.85 ms | 5.87 ms | 9.84 ms | 5 | 800.6 | 0 | yes |
| grant-revoke-load | writes:w2 | check_inconsistent | 10.76 ms | 13.38 ms | 15.16 ms | 15 | 38.2 | 0 | yes |
| grant-revoke-load | writes:w2 | check_under_writes | 3.82 ms | 4.96 ms | 7.25 ms | 5.06 | 139.7 | 0 | no |
| grant-revoke-load | writes:w2 | grant | 2.28 ms | 2.99 ms | 3.43 ms | 7 | 88.4 | 0 | no |
| grant-revoke-load | writes:w2 | revoke | 2.55 ms | 3.52 ms | 9.33 ms | 8 | 88.4 | 0 | no |
| grant-revoke-load | writes:w4 | check_inconsistent | 10.8 ms | 14.8 ms | 16.22 ms | 15 | 103.2 | 0 | no |
| grant-revoke-load | writes:w4 | check_under_writes | 4.04 ms | 7.86 ms | 10.66 ms | 5.47 | 155.2 | 0 | no |
| grant-revoke-load | writes:w4 | grant | 2.28 ms | 6.25 ms | 81.75 ms | 7 | 127.2 | 0 | no |
| grant-revoke-load | writes:w4 | revoke | 2.59 ms | 3.5 ms | 57.21 ms | 8 | 127.2 | 0 | no |
| multi-tenant | tenant:w1 | admin_update | 2.11 ms | 2.76 ms | 14.35 ms | 6 | 148 | 0 | yes |
| multi-tenant | tenant:w1 | member | 2.15 ms | 2.65 ms | 11.46 ms | 6 | 148 | 0 | yes |
| multi-tenant | tenant:w1 | outsider | 1.9 ms | 2.38 ms | 2.7 ms | 5 | 145.7 | 0 | yes |
| multi-tenant | tenant:w2 | admin_update | 2.2 ms | 3.33 ms | 10.22 ms | 6 | 275.6 | 0 | no |
| multi-tenant | tenant:w2 | member | 2.2 ms | 3.07 ms | 5.64 ms | 6 | 275.6 | 0 | yes |
| multi-tenant | tenant:w2 | outsider | 1.94 ms | 2.75 ms | 5.6 ms | 5 | 271.5 | 0 | yes |
| multi-tenant | tenant:w4 | admin_update | 2.2 ms | 3.36 ms | 4.02 ms | 6 | 547.9 | 0 | yes |
| multi-tenant | tenant:w4 | member | 2.2 ms | 3.1 ms | 3.63 ms | 6 | 547.9 | 0 | yes |
| multi-tenant | tenant:w4 | outsider | 1.94 ms | 2.75 ms | 3.27 ms | 5 | 539.8 | 0 | yes |

Checks:

- PASS visible-at-scale/visible:w1: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w2: user 10 sees exactly its own posts (50 of 50)
- PASS visible-at-scale/visible:w4: user 10 sees exactly its own posts (50 of 50)
- PASS grant-revoke-load/writes:w2: every subject ends in the state of its last write (0 of 50 wrong)
- PASS grant-revoke-load/writes:w4: every subject ends in the state of its last write (0 of 100 wrong)
- PASS multi-tenant/tenant:w1: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w2: members, admins and outsiders get their answers (0 wrong)
- PASS multi-tenant/tenant:w4: members, admins and outsiders get their answers (0 wrong)
