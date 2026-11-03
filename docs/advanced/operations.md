# Operations

## Deployment checklist

The host deployment owner records the application SHA, dependency lock hash, database/storage/panel inventory,
cache namespace and generation, key IDs (never key bytes), backup ID, monitoring owner and rollback pair before
starting. These steps require the host's normal deployment authorization. They do not provision Redis or alerts.
Fence ingress, scheduled writers and queue/Octane workers at the host before migrations or recovery. Laravel
maintenance mode alone does not stop queue workers, bypass requests or another application writing the same database.

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
  Keep traffic fenced until compatible configuration, signing keys, primary smoke checks and production Doctor pass.
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

Doctor validates the configured models and aliases; it does not scan stored identities, derived keys or historical
actor types. A clean `morph.identity` result after changing the map is not evidence that stored rows were migrated.

As a read-only starting point for the inventory below, run a grouped count for each existing identity column in
each affected table, for example `SELECT subject_type, COUNT(*) FROM role_grants GROUP BY subject_type`. Repeat it
for the applicable `tenant_type`, `context_type` and `actor_type` columns, including host tables and audit tables;
record null values too. Use the actual table names and connection for each storage. These counts identify values to
investigate but do not replace the sorted row exports and hashes needed to detect changes with unchanged counts.

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

   For storage schema 2, rebuild `tenant_key` with `AzGuard\Kernel\Identity\TenantRef::of($newType, $id)->key()`
   and `context_key` with `AzGuard\Kernel\Identity\AssignmentScopeRef::of($newType, $id)->key()`. Use each
   reference's `global()->key()` when its type and ID are both null. These factories preserve canonical string IDs
   and validate aliases. The current keys are `type:id` or `global`; they are not the versioned JSON composites
   produced by `IdentityCodec::compose()` (`IdentityCodec::VERSION` is currently 1). Use the installed factories,
   not SQL string replacement, and verify keys in permission definitions and grants against their type/ID columns.
4. Deploy the map and code together, reset state for every affected panel with
   `php artisan azguard:state:reset <panel> --force` in production, invalidate external caches, restart workers, run Doctor in production mode and verify representative
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
  record who made them. A string actor is trusted backend attribution, not proof that a user is authorized.
  Authenticate/reload user actors and authorize actor, target, role, tenant and scope in a host service before a
  change. Retried jobs must revalidate that policy; never trust actor or panel IDs supplied by an HTTP caller.

The repository's disposable archive consumer exercises one Octane/RoadRunner worker and one queue worker:
`bash bin/worker-smoke.sh smoke` sends 1,000 requests and 1,000 jobs. `memory` runs 200 warmup requests and jobs followed
by 10,000 measured requests and jobs; `soak` runs 100,000 of each. The nightly and release lanes run `memory`.
Each run writes its versions, archive hash, logs and per-request PID, RSS and memo counts under
`build/worker-smoke/`. The check fails if the worker changes, lifecycle counts do not return to zero, or peak RSS
growth from the warmup baseline or over the final 5,000 samples exceeds the larger of 8 MiB and 10% of warmed RSS.
HTTP and queue series are checked separately. Investigate a failure
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
AzGuard raise them (see [Consistency](/advanced/consistency)). Restoring a database can restore an old token while
external cache entries and source state remain newer. A later write can even recreate a previously used token.
After a restore or manual grant-table change, **reset every affected panel before reopening traffic**:

```bash
php artisan azguard:state:reset admin --force  # repeat for each affected panel in production
```

Use this order; a failure at any step leaves the host maintenance fence closed:

1. Stop ingress, all writers and workers, and verify that none can bypass the fence. Record the incident owner,
   affected panels/connections/external sources and the recovery point. Take a forensic copy if needed. Restore-test
   a consistent backup on a disposable `*_test` database before changing production. Use the database vendor's
   consistent snapshot/restore tools; copying an open SQLite file can omit WAL data. The drill uses `VACUUM INTO`
   for its snapshot and closes all connections before replacing its disposable SQLite file.
2. Restore the complete compatible snapshot, code, lock, schema, identity map and source configuration. Recover
   external authority sources under their own contracts. Verify backup hashes and sorted authority/audit inventories.
   Account for lost post-backup writes; do not merge grants from an unrelated snapshot. A major downgrade restores
   a compatible code/lock/database pair, rather than running a reverse 2.0-to-1.x migration.
3. Confirm each configured writer connection targets the restored **primary** and run the reset above for **every**
   affected panel. Record exit 0 and the new incarnation; verify the epoch advanced relative to restored state.
   Unknown panel, wrong connection, open writer transaction or reset failure stops recovery. Reset starts its own
   root transaction; a wrapper on another connection does not make recovery atomic. Retain the new `panel.touched`
   audit entry attributed to `azguard:state:reset` separately from the restored audit inventory.
4. Rotate the affected external namespace or raise each panel's `cache.generation` (`azguard.defaults.cache.generation`
   unless overridden by the provider). Restart/recycle all workers with the compatible configuration, rebuild the
   catalog, and warm through the host's read-only primary authorization smoke path. Warm raw permission sets, never
   store final decisions. A cache clear alone cannot replace the state reset.
5. Run `php artisan azguard:doctor --production --json`, save output and exit status, then check explicit expected
   allow and deny identities: ordinary user, revoked user, outsider, foreign tenant, foreign assignment scope and a
   failed source. Load sensitive host records from primary, use primary authority and `StateRefresh::Check`. Check
   the affected panels and restored audit counts/hashes, plus monitoring delivery. Doctor exit 0 can contain warnings;
   record their disposition, especially `cache.redis`. Doctor does not test network controls or alert delivery.
6. Only the host recovery owner opens ingress and starts ordinary workers after all previous evidence passes.
   If configuration, reset, warmup, smoke, Doctor or inventory checks fail, keep maintenance, preserve logs and repair
   or restore the rehearsed rollback pair. Do not automatically run `artisan up` in a failed command's cleanup.

To drop every cached permission set at once, raise every affected panel's `cache.generation`. During key rotation, coordinate signing keys
across instances and follow the bounded previous-key verification procedure in [cache configuration](../reference/configuration.md).
Invalidate the affected cache namespace, restart workers and verify decisions before traffic resumes; rehearse the
compatible signing configuration and generation for the rollback pair without exposing signing material.

## Redis isolation and signing rollout

The platform owner supplies a dedicated Laravel Redis cache store and credentials for authority. Use a distinct
physical cache prefix and `AZGUARD_CACHE_NAMESPACE` for each application/environment. Restrict the credential to
that physical prefix, the necessary commands and the private service network; use TLS with certificate/hostname
verification outside a local isolated drill. Set a finite panel TTL (at most 3600 seconds), an immutable build ID,
`AZGUARD_CACHE_KEY_ID` and a secret of at least 32 decoded bytes in `AZGUARD_CACHE_SIGNING_KEY`. Store key bytes in
the host secret manager, separate from Redis credentials and Redis contents. Without a dedicated signing key the
runtime can derive from `APP_KEY`; production should deliver an explicit key so rotation is independent.

Disable PhpRedis serialization and compression on this connection. File and other unsupported stores bypass shared
permission-set caching. The host must configure its store, connection and prefix: there is no package-provisioned
Redis, ACL, TLS certificate or automatic alert. The production `cache.redis` warning requires deployment evidence.
Verify the allowed credential can GET/SET/expire its own disposable canary key and cannot access a different
application prefix; retain only redacted results. Verify TLS failure on an untrusted certificate and denied access
from an unauthorized network location. Invalid-envelope compare-and-delete uses `EVAL`; if ACL denies it, cleanup
is best effort and source reload still handles the miss. Never grant administrative Redis commands for that reason.

For a planned, uncompromised rotation, choose a fixed UTC deadline, for example
`2026-12-01T12:30:00.000000Z`, covering the rollout plus the maximum entry TTL and clock skew budget:

1. Deliver the new secret to **all** readers first. Keep the old active ID/key and add the new ID/key to
   `azguard.cache.previous_keys` with a finite `valid_until`. Despite its name, this map is the additional
   verification-key set. No writer switches yet. Rebuild host cached configuration and restart/recycle every
   instance; verify the deployment inventory, not just one pod.
2. Switch active `key_id`/`signing_key` together to the new pair. Remove the new ID from `previous_keys`, and put
   the old pair there with the agreed finite UTC deadline. Never list the active ID twice. Keep namespace/generation
   stable for this overlap; verify new writes carry the new ID, old entries work only before the deadline, and
   malformed/unsigned entries miss and reload from primary. A source reload failure must deny.
3. Retire all old writers before the deadline. At the exact `valid_until` instant the old signature is rejected,
   even if its entry TTL has not expired. Remove the old key from all configs, restart workers and verify a cold
   authoritative reload. Raise generation or change namespace when immediate invalidation is required. Do not enable
   an unsigned fallback, extend a deadline indefinitely or log secrets while investigating misses.
4. For rollback within a non-compromise window, rehearse compatible code with the new active key, or pre-deliver
   both verification pairs before switching writers back. Never restore a retired compromised key or lower a
   generation to recover an old cache. See [compromise response](./security.md#operational-key-compromise).

### Retiring persistent entries

Old persistent entries may have no expiry. Changing generation/namespace stops using them but does not reclaim
memory. A namespace is hashed into permission-set keys; it is **not** a selectable physical Redis prefix. Inventory
the full client prefix plus store prefix from the old configuration. Move active writers to a different dedicated
physical prefix before retiring one. If a prefix is shared with other workloads, do not guess which keys are yours.

On the separately authorized host Redis endpoint, first perform a read-only `SCAN MATCH <retired-prefix>* COUNT 100`
inventory, retaining cursor checkpoints, counts and a protected key-list digest. A cursor is not a snapshot: deduplicate
results and repeat the inventory after all old writers stop. Confirm every returned key begins with the exact retired
prefix and inspect `PTTL` (`-1` means persistent, `-2` means already gone). Review the dry run before mutation.
Then process at most 100 reviewed keys per batch, applying `EXPIRE <exact-key> 300` only when its current TTL is `-1`;
alternatively delete exact reviewed keys in a separately approved batch. Recheck counts and TTLs after each batch;
stop on an unexpected prefix, growing inventory, Redis error or new writer. Keep a rollback/cold-load capacity budget.
Never use global `FLUSHDB`/`FLUSHALL`, `KEYS *`, or Laravel `cache:clear` against a shared store. The isolated Redis
fixture exercises SCAN, persistent-entry expiry and an untouched foreign-prefix canary.

## Disposable production runbook drill

From the repository with locked development dependencies, PHP SQLite/PhpRedis and Docker available:

```bash
bash bin/production-runbook-drill.sh --fixture=disposable --report=build/runbooks/drill.json
```

The command creates a Testbench reference application, file-backed `authority_test.sqlite` snapshot, random signed
cache namespace and its own Redis 7.4 container with `--network none`, port 0 and a private Unix socket. It accepts
no production DSN, host, port or credentials and clears inherited service environment. Container removal runs on
failure too; removal failure is nonzero. It retains source SHA, working diff, versions/image ID, command exits,
Doctor output, fixture results and Redis logs under `build/production-runbook-drill/run.*`. Review a working diff
alongside the SHA when qualifying an uncommitted tree. Secrets and payloads are never evidence.

`--fixture=disposable` explicitly selects the only permitted fixture. The previous no-argument and `redis` forms
remain valid. `--report` accepts a project-relative JSON path below `build/`; traversal and symlink escapes are
rejected. Without it, the JSON is saved as `result.json` in the run's evidence directory. The report's
`schema_version: 1` records `fixture`, `mode` (`redis` or `array`), `status` (`passed` or `failed`), final numeric
`exit`, `source_sha`, `evidence_dir` and existing `logs`. It is written after cleanup, including on failures;
setup failure before report initialization may leave no report. Automation must require both exit 0 and
`status: passed`, and retain the referenced logs. An array-mode report does not qualify Redis transport.

`bash bin/production-runbook-drill.sh --self-test` runs the same local fixtures with an array store and no Docker.
It does not qualify Redis transport. The fixture executes the catalog cache/clear, production Doctor and forced
state-reset commands above; tests restored-token collision before reset, failure keeping the maintenance fence
closed, key overlap/expiry/removal, unsigned rejection, representative denials and monitoring-response rejection.
The maintenance flag models the host fence; it does not test a load balancer or a real production worker shutdown.
Vendor restore commands, morph migrations, the 1,000-job worker smoke and release publication have their own
rehearsals; the local drill does not qualify them. Actual TLS/ACL/network isolation, primary topology, backups and
alert receipt require the named deployment owners' evidence in the repository's `RELEASING.md`, under
“Production operations acceptance”.

## Dedicated connection

Give AzGuard its own connection (`AZGUARD_DB_CONNECTION`) to the same database when checks run inside
application transactions (see [Decisions](/concepts/decisions#checks-inside-database-transactions)). A read
replica can be used with `consistency(reads: Reads::Default)` if replica lag is acceptable for authorization.

## Logging

Denials caused by errors (`source_error`, `policy_error`, `hook_error`, …) are logged with the component and
a stable `code`. Alert on them: they mean a broken source or policy, not a user without access.
`azguard:explain` can reproduce a single decision with an explicit host identity and scope. Its output is protected
diagnostic data, even with secret redaction; follow the [observability runbook](./observability.md).

## Observability

Use the existing after hook and host metrics exporter (see [Decision observability](./observability.md)) to count
bounded failure kinds. Event tracing needs separate privacy controls.

| Signal | Where to read it |
|---|---|
| Deny and error rates | The bounded after-hook counter. `access.decided` tracing is optional and includes identity/context; do not log its payload as is |
| Grants per operation and change duration | A change participant (the Changes SPI, [Hooks and plugins](/advanced/hooks-and-plugins)) that measures around the transaction |
| Panel-state lock wait and deadlocks | Database metrics: `pg_stat_activity` and `pg_locks` on PostgreSQL, `performance_schema.data_lock_waits` and the InnoDB deadlock counter on MySQL and MariaDB. The retry policy surfaces the exceptions it gives up on |
| Cache invalidation latency | The state token comparison and the metrics of the cache store |
| Consistency and read mode | The `consistency` keys of the configuration and the `consistency.reads` finding of `azguard:doctor` |

Measure panel lock waits before changing the host's concurrency policy. A captured visibility Builder, cursor or
pagination result can outlive a revoke. Reload a concrete sensitive record from primary and check again; strict
check/use atomicity requires the host effect and revoke paths to share a transaction/lock/fence protocol on
coordinated connections. An ordinary transaction on a separate connection provides no such guarantee.
