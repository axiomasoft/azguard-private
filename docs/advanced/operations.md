# Operations

## Deployment checklist

```bash
php artisan migrate --force
php artisan azguard:catalog:cache          # static catalogs of all panels → bootstrap/cache/azguard.php
php artisan azguard:doctor --production    # also checks the catalog cache and the build id; exit 1 on errors
```

- **Build id.** Set `AZGUARD_BUILD_ID` to the commit hash. Cached catalogs and cached permission sets of
  another build are then never used. Without it, the id is derived from the panel provider files and does not
  follow changes elsewhere, for example in a role class.
- **Rollback.** After a code rollback, run `azguard:catalog:cache` again or `azguard:catalog:clear`.
- **CI.** Run `php artisan azguard:doctor` in CI. It exits with `1` on errors and checks the configuration,
  panels, storages, sources, plugins and morph aliases.

## SQLite

SQLite storages are supported. A decision reads its grants in one `BEGIN DEFERRED` read transaction. Without WAL,
that read and a write block each other. `azguard:doctor` warns (`storage.sqlite`) when a file database is not in WAL
mode or has `busy_timeout = 0`. AzGuard never changes the journal mode itself, because WAL changes the database
files. Turn it on in the connection config (`'journal_mode' => 'wal'`, `'busy_timeout' => 5000`) when the database
is used concurrently.

## Scheduled maintenance

| Task | How |
|---|---|
| Remove expired grants | Registered automatically: `azguard:grants:prune` runs `daily` (`schedule.prune_expired`). Expired grants already deny before pruning. Pruning publishes `GrantExpired` |
| Audit journal retention | `azguard:audit:prune`, if `AuditPlugin` is used. Schedule it yourself |

## Long-running workers (Octane, queues)

- **Request state.** The authorizer, the permission-set memo, the current panel and the scope are Laravel
  *scoped* instances. Octane and the queue worker reset them between requests and jobs.
- **Tenants in jobs.** A job does not inherit the tenant or scope of the request that dispatched it. Pass them
  to the job and use `$user->inTenant($tenant)` or `on:`.
- **Actor in jobs.** Wrap changes in `AzGuard::actingAs($user or 'reason', …)` so events and the audit journal
  record who made them.

## Manual database changes and restores

Cached permission sets are keyed by the panel's incarnation, epoch and each subject's revision. Writes through
AzGuard raise them (see [Consistency](/advanced/consistency)). After you edit the grant tables by hand or restore a
backup, start a new state (new incarnation, new epoch) so that no cache serves old answers:

```bash
php artisan azguard:state:reset admin
```

To drop every cached permission set at once, raise `cache.generation`.

## Dedicated connection

Give AzGuard its own connection (`AZGUARD_DB_CONNECTION`) to the same database when checks run inside
application transactions (see [Decisions](/concepts/decisions#checks-inside-database-transactions)). A read
replica can be used with `consistency(reads: Reads::Default)` if replica lag is acceptable for authorization.

## Logging

Denials caused by errors (`source_error`, `policy_error`, `hook_error`, …) are logged with the component and
a stable `code`. Alert on them: they mean a broken source or policy, not a user without access.
`azguard:explain user:42 posts.update` reproduces a single decision with secrets redacted.
