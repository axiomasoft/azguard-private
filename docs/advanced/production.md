# Production

A checklist for running AzGuard in production. Each item names the command or setting that makes it true and the page
that explains it. The deployment steps themselves are on [Operations](/advanced/operations).

## Build id

- Set `AZGUARD_BUILD_ID` (`catalog.build_id`) to an immutable id of each deployment, such as the commit SHA or the image
  digest. Cached catalogs and cached permission sets of another build are then never used.
- Without it the id follows only the panel provider files. `azguard:doctor --production` reports the gap as an error when
  the catalog cache file exists or a panel uses a persistent cache store, and as a warning otherwise.

## Caches

- Run `php artisan azguard:catalog:cache` on every deploy, after the build id is set. Run it again, or
  `azguard:catalog:clear`, after a code rollback.
- A panel with `cache.store` in a persistent store also needs `cache.ttl`; the doctor reports a missing ttl as an error
  (`cache.store`). A grant or revoke raises the revision of its subject, so cached sets never outlive it.
- A persistent cache store is owned by the host. Monitor it: an unreachable store is not a case AzGuard guarantees
  (see [Failure semantics](/advanced/consistency#failure-semantics)).

## Reads and freshness

- Keep the default `consistency(reads: Reads::Primary)` unless you have a measured reason. A replica can lag behind a
  committed revoke; see the [decision tree](/advanced/consistency#choosing-primary-or-replica-reads).
- Use `StateRefresh::Check` only in long-running workers that must see revokes made by other processes immediately.

## Deploy gate

- Run `php artisan azguard:doctor --production` after `migrate` and `azguard:catalog:cache`. It exits `1` on errors.
- Run `php artisan migrate --force` before the new code serves traffic. The core adds migrations only; a published
  migration is never edited (see [Compatibility](/reference/compatibility#extension-authors-and-persisted-formats)).

## Data

- Write grants through AzGuard (the change pipeline, the commands, the grant manager), not with raw SQL. A write outside
  AzGuard does not raise the state version, so caches keep the old answer. `azguard:doctor` checks direct writes.
- Back up the AzGuard tables with the rest of the database before an upgrade. Roll a schema change back from the backup
  when `UPGRADING.md` lists the step as irreversible.

## Observability

Deny and error rates, change duration, lock waits and cache invalidation are available from existing surfaces; see
[Operations](/advanced/operations#observability).
