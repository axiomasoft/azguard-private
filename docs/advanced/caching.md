# Permission-set caching

Permission sets and source states are memoized for a request or job. Each LRU map defaults to 1024 entries; set
`azguard.cache.max_memo_entries` and `azguard.cache.max_state_entries` to integers from 1 to 1024 to reduce the limits.
An evicted set is loaded again, and an evicted state is read again before its revision-keyed set is selected. Volatile
sources are never cached. Without a shared store the memo still works for the request or job.

Shared entries require a stable source, a read fence, signing configuration and a store. Their lifetime is the least of
the requested TTL (300 seconds by default), 3600 seconds, and the nearest active grant or role expiry. A null TTL for a
shared store remains a configuration/doctor error; the runtime fallback is finite. Grant expiry is checked at the exact
boundary. Cache misses and eviction cause authoritative reload, not a change in authorization rules.

Older versions may have left permanent Redis keys. To clean them, first identify the intended Redis connection,
application-specific prefix and `azguard:sets:v2:` keys; inspect their TTL and delete only matching keys with TTL -1,
using incremental SCAN and targeted deletion under the deployment's operational procedure. Account for Laravel's
configured cache prefix and validate the selection before deleting. Never use FLUSHDB or delete another application's keys.
