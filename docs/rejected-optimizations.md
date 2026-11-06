# Optimizations awaiting measurement

Replacing the panel lock with per-subject locks is not justified by the existing measurements. A subject lock also
needs a demonstrated lock order and correctness under panel-wide mutations. Use the `lock-contention` benchmark on
the target workload before proposing an experiment; its wait probe includes a database round trip and its hold probe
ends at commit or rollback. No scalability conclusion follows from smoke results.
