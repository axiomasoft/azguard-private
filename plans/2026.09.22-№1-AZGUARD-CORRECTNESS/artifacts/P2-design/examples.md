# P2 implementation examples

## Synchronizer state machine

```text
VALIDATE -> BEGIN -> LOCK ROLE -> READ CURRENT -> CHECK FINGERPRINT
         -> DIFF -> WRITE REMOVALS/ADDITIONS -> BUMP REVISION IF CHANGED -> COMMIT

fingerprint mismatch -> ROLLBACK/no writes -> Conflict
validation failure   -> no transaction/no writes
write/revision error -> ROLLBACK all rows and revision
no diff              -> COMMIT/return no-op without revision bump
```

## Revision/cache interleaving

| Time | Reader | Writer |
|:--|:--|:--|
| t1 | reads R=7 | begins transaction |
| t2 | resolves old rows | writes revoke + R=8 |
| t3 | stores only key `...:r7` | commits |
| t4 | next check reads R=8 and misses r7 | — |

## Same-connection rollback collision (D13)

| Time | Transaction-local checker | Other reader |
|:--|:--|:--|
| t1 | committed R=7; begin outer transaction | warmed reusable r7 entry is irrelevant |
| t2 | grant A + uncommitted R=8; fresh check of A, no cache read/write | — |
| t3 | nested commit then outer rollback restores R=7 | — |
| t4 | unrelated grant B commits R=8 | fresh check may cache only B state under r8 |
| t5 | — | shared cache cannot contain rolled-back A allow under r8 |

## Narrow failure boundary

```php
$revision = $state->current();

try {
    $cached = $cache->get($keyFor($revision));
} catch (CacheTransportException $e) {
    $requestCache->forget($keyFor($revision));
    return $resolver->resolveAuthoritatively();
}

// Exceptions thrown by resolveAuthoritatively() are not caught as cache failures.
```
