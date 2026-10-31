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
