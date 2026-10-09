| Scenario | Median | p95 | SQL / op |
|---|---:|---:|---:|
| check, same request, 1 role | 595 µs | 678 µs | 0 |
| check, same request, 20 roles + 200 direct | 2.03 ms | 2.76 ms | 0 |
| check, same request, wildcard g1.** | 437 µs | 607 µs | 0 |
| check, new request, no cache store, 1 role | 4.82 ms | 5.85 ms | 5 |
| check, new request, no cache store, heavy | 21.55 ms | 25.13 ms | 5 |
| check, new request, array cache store, 1 role | 3.58 ms | 4.62 ms | 2 |
| check, new request, array cache store, heavy | 6.03 ms | 14.95 ms | 2 |
| abilities(20), new request, heavy | 68.75 ms | 86.48 ms | 4 |
| 100 records with a policy, one request (per op = 100 checks) | 77.59 ms | 79.88 ms | 5 |
| visibleTo(): first page of 10000 posts, new request | 6.93 ms | 7.05 ms | 7 |
| visibleTo(): count of 10000 posts, new request | 6.91 ms | 7.08 ms | 7 |
| grantRole() of a new subject | 3.19 ms | 3.25 ms | 8.67 |
| compile panel registry (1 000 permissions, 40 roles) | 63.61 ms | 80.88 ms | 0 |

PHP 8.4.26, Laravel 13.35.0, sqlite, Intel(R) Xeon(R) Processor, OPcache off.
