# Operations

## Deployment checklist

```bash
php artisan migrate --force
php artisan azguard:catalog:cache          # static catalogs of all panels → bootstrap/cache/azguard.php
php artisan azguard:doctor --production    # also checks the catalog cache and the build id; exit 1 on errors
```

- **Build id.** Set `AZGUARD_BUILD_ID` to the commit hash. Cached catalogs and cached permission sets of
  another build are then never used. Without it, the id is derived from the panel provider files and does not
  follow changes elsewhere, for example in a role class. In production `azguard:doctor` reports a missing build id as
  an error when the catalog cache file exists or a panel uses a persistent cache store, and as a warning otherwise:
  use an immutable id such as the commit SHA or the image digest.
- **Rollback.** After a code rollback, run `azguard:catalog:cache` again or `azguard:catalog:clear`.
- **CI.** Run `php artisan azguard:doctor` in CI. It exits with `1` on errors and checks the configuration,
  panels, storages, sources, plugins and morph aliases.

## Morph identity and alias changes

Register stable aliases with `Relation::morphMap(['user' => User::class, /* tenant and context models */])`
in the host before panels are built. AzGuard does not enable Laravel's process-wide `requireMorphMap` flag.
Doctor checks declared subject, tenant and context models, including custom `getMorphClass()` values and reverse
resolution. An unmapped class produces `morph.identity` warning in production; set
`config(['azguard_doctor.strict_morph_identity' => true])` before running Doctor to make that warning an error.
Existing identity validation can independently reject an FQCN. Alias collisions and aliases that do not resolve to
the expected model are errors. Explicit tenant and context types also need matching map entries.

Changing an alias changes identity, even if the numeric IDs match. The host owns this migration; Doctor never
performs it. Rehearse the following on a restored disposable copy using the same database engine and application
version, and retain the inventory, scripts and verification output with the deployment record:

1. Inventory every configured storage, connection and prefix, host polymorphic table, queued payload, cache and
   integration that stores a type. For each `subject_type`, `tenant_type`, `context_type` and `actor_type` column,
   group by exact type and count rows, including grants, permissions, subject revisions and audit history. Inventory
   derived `tenant_key` and `context_key`, uniqueness constraints and both old and new aliases. Export sorted
   primary keys and relevant identity columns and hash the export per table. Record the map from each old FQCN or
   alias to exactly one model and new alias; resolve duplicate aliases and prospective unique-key collisions first.
2. Decide a separate policy for audit history and actor identities. Preserve historical audit identities by default;
   do not rewrite them without an approved policy. Include host relations and old queued jobs in the compatibility
   plan. Take and restore-test a consistent backup of every affected database; record its location and hashes.
3. Quiesce writers and workers, repeat the inventory and compare counts and hashes with the approved dry run. Abort
   on drift, ambiguity, missing rows or collisions. In a transaction per database, run a host-written migration
   against exact old values, covering every applicable type column and subject revision. Recompute derived keys
   using the same AzGuard identity encoding as the installed version; never replace only the visible type when a
   derived key also contains it. Verify affected-row counts, uniqueness and sorted identity exports against the
   predicted transformed inventory before committing. For multiple databases, plan a coordinated recovery: there
   is no cross-database transaction.
4. Deploy the map and code together, reset state for every affected panel with `php artisan azguard:state:reset
   <panel>`, invalidate external caches, restart workers, run Doctor in production mode and verify representative
   subject, tenant and context access. Recount old and new values and compare hashes to the expected exports before
   resuming traffic. Retain the unchanged audit inventory and verify it separately.
5. For rollback, quiesce writers again. Restore the consistent backup and matching old code/map, or use a rehearsed
   inverse migration only if all post-migration writes can be accounted for without data loss. Verify counts, hashes,
   uniqueness and audit history against the rollback inventory, reset each panel's state again, invalidate caches,
   restart workers and check Doctor and representative access before reopening traffic. Stop on any mismatch.

## Storage schema

Each storage records its schema in `storage_state` (schema 2: the authority tables, `subject_revisions` and the
`epoch` column of `panel_state`, see [Consistency](/advanced/consistency)). The core migration
`2026_10_01_000000_create_azguard_storage` creates the six authority tables of the default storage; for a named
storage run `php artisan azguard:storage:migration <name>`. `azguard:doctor` reports `storage.schema` when the stored
schema does not match the configuration.

The audit journal belongs to `AuditPlugin` and has its own migration. Publish it once and migrate:
`php artisan vendor:publish --tag=azguard-audit-migrations && php artisan migrate` (default storage), or
`php artisan azguard:audit:migration <name>` for a named storage. Until then `azguard:doctor` reports
`audit.table` for every panel with `AuditPlugin`.

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
  to the job explicitly. Request-only tenant and assignment scope resolvers receive an empty `Request` outside HTTP;
  absent values resolve to global when optional and deny when required. A carried panel id is only a hint.
- **Actor in jobs.** Wrap changes in `AzGuard::actingAs($user or 'reason', …)` so events and the audit journal
  record who made them.

The repository's disposable archive consumer exercises one Octane/RoadRunner worker and one queue worker:
`bash bin/worker-smoke.sh smoke` sends 1,000 requests and 1,000 jobs. `memory` runs 200 warmup requests followed
by 10,000 measured requests; `soak` runs 100,000 measured requests. The nightly and release lanes run `memory`.
Each run writes its versions, archive hash, logs and per-request PID, RSS and memo counts under
`build/worker-smoke/`. The check fails if the worker changes, lifecycle counts do not return to zero, or peak RSS
growth over the final 5,000 requests exceeds the larger of 8 MiB and 10% of warmed RSS. Investigate a failure
using the retained series; do not retry it as a substitute for investigation. The workload uses a temporary SQLite
`worker_test.sqlite` database and does not require Redis.

A queue handler can keep all authorization inputs explicit:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

// $panelId, $subjectId, $tenantId and $projectId are job payload fields.
$scope = AccessScope::in(TenantRef::of('organization', $tenantId), AssignmentScopeRef::of('project', $projectId));
$request = AccessRequest::for(SubjectRef::of('user', $subjectId), PermissionKey::of($panelId, 'posts.update'))
    ->inScope($scope);
$decision = AzGuard::panel($panelId)->decide($request);
if (! $decision->allowed()) {
    return; // Handle the denial according to the job's policy.
}
```

Choose aliases and permissions declared by the application. The subject being checked is separate from the actor
recorded for changes; set the actor explicitly when needed.

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

## Observability

AzGuard has no metrics API in 1.0. Each signal is available from a surface that already exists; a first-class metrics hook is
a 1.1 candidate (see `ROADMAP.md`).

| Signal | Where to read it |
|---|---|
| Deny and error rates | A listener on the `access.decided` event; its payload keys are in [Machine formats](/reference/formats). The payload carries no grant or meta data, so it can be logged as is |
| Grants per operation and change duration | A change participant (the Changes SPI, [Hooks and plugins](/advanced/hooks-and-plugins)) that measures around the transaction |
| Panel-state lock wait and deadlocks | Database metrics: `pg_stat_activity` and `pg_locks` on PostgreSQL, `performance_schema.data_lock_waits` and the InnoDB deadlock counter on MySQL and MariaDB. The retry policy surfaces the exceptions it gives up on |
| Cache invalidation latency | The state token comparison and the metrics of the cache store |
| Consistency and read mode | The `consistency` keys of the configuration and the `consistency.reads` finding of `azguard:doctor` |

Panel-level locking is unchanged before 1.0: measure the lock wait first, then decide whether finer locking is needed.
