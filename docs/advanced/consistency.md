# Consistency

This page lists what a decision guarantees about the authority it read, and what it does not. The design and its
sources are in `audits/2026-10-09-consistency-design.md`.

## What a decision reflects

A decision reflects the grant sources **at the moment it read them**. Host inputs (subject model, resource,
tenant, before hooks) are prepared once. Sources are read once, then the pipeline evaluates once. A write that
commits while the policy is being evaluated does not make AzGuard retry the check, and hooks, model events and
policies never run twice because of a source read.

The database source reads the panel state and the grant rows in **one read-only transaction** on its pinned
connection:

| Driver | Statement |
|---|---|
| PostgreSQL | `START TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` |
| MySQL / MariaDB | `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, then `START TRANSACTION READ ONLY` |
| SQLite | `BEGIN DEFERRED` |

Rows are turned into grants after `COMMIT`. If anything fails after the snapshot started, it is rolled back.
If the rollback fails too, the connection is dropped, so no transaction stays open. A read inside a tentative
authority transaction (see [Decisions](/concepts/decisions)) or a test baseline uses that transaction and a
bounded check of the state before and after the read instead.

## The state token

`$decision->state` is the **observed state**: storage, panel, incarnation, panel version, epoch and, for a
subject's grants, the subject's revision.

- Grant changes made through AzGuard raise the panel version and the revision of the affected subject.
- A change whose subjects are not known raises the epoch: `touch`, `azguard:state:reset`, dynamic permission
  definitions, or a generic write through a mutation. A reset raises it in the same transaction as the new
  incarnation.
- Revision rows are never deleted. A missing row means revision 0.

The token covers the **grant source state only**. It does not cover host data that policies, scopes or
conditions read (the subject model, the resource, membership tables).

## Cache keys

Cached permission sets are keyed by storage, incarnation, epoch, subject revision, registry fingerprint, cache
generation and query scope. The **panel version is not part of the key**, so a write to another subject does
not evict anybody else's entry. On a cache hit, the decision's state comes from one statement that reads
`panel_state` LEFT JOIN `subject_revisions`, never from the cache entry, so it still shows the current panel
version. Reads inside an authority transaction are never published to the shared cache.

## Primary freshness and replicas

With `consistency(reads: Reads::Primary)` (the default) and `StateRefresh::Check`, a check that starts after a
revoke has committed never uses the revoked grant. `StateRefresh::Request` reads the observed state once per
request or job. Revokes committed by other processes become visible on the next request.

`Reads::Default` may read from a replica. A replica can lag behind the primary, so **replica mode is not strict
freshness**: a decision may reflect a state from before a committed revoke. `azguard:doctor` warns about it
(`consistency.reads`). A freshness receipt that would make replica reads wait for a known commit is planned for
1.x.

## DecisionSet

`decideMany()` starts every request first (admission, before hooks). Then it reads the database authority of
every subject in **one snapshot per storage connection**: each subject's observed state, the cache lookups, and
the raw rows of subjects with a cache miss. No host code runs inside that transaction. All decisions of one
storage panel therefore match one panel state, and `DecisionSet::states()` has exactly one token for it.

The guarantee is weaker in these cases:

- Panels on different connections or with different read modes are read in separate snapshots.
- Requests inside an authority transaction keep their own read.
- If the caller splits a large batch into several `decideMany()` calls, each chunk is consistent on its own,
  but the chunks are not consistent with each other.

## Failures

A decision that could not be computed is denied with a failure reason, never with a policy outcome
(`Decision::failure()`):

| Kind | Reasons | Reference HTTP status |
|---|---|---|
| none (outcome) | `not_granted`, `restricted`, … | 403 |
| `Transient` | `source_error`, `consistency_error` | 503 with `Retry-After` |
| `Contract` | `policy_error`, `hook_error`, `restriction_error`, `condition_error`, `context_filter_error` | 500 |

Any failed source read fails the whole decision: there are no partial grants, and a failed read never grants
super admin. In a `DecisionSet`, each decision keeps its own outcome. `failures()` lists the ones that failed,
and `failure()` returns the most severe kind. `visibleTo()` throws `VisibilityNotSupportedException` with
`source_error` or `consistency_error`; it never returns an empty list instead.

`authorize()`, `azguard.can` and the Gate answer 403 for every denial, and this stays the default. To answer
differently, use `DecisionResponder::authorize($decision)`, the opt-in reference adapter (one line at the call
site: `DecisionResponder::authorize($access->decide($permission, $model))`). It throws `AuthorizationException` (403)
for a denial and `DecisionFailedException` (503 or 500, generic message, no reason or component) for a
failure. The final HTTP policy belongs to the host:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->report(function (DecisionFailedException $e): void {
        Log::warning('authorization failed', ['reason' => $e->reason->value, 'kind' => $e->failure->value]);
    });
})
```

## visibleTo and time of check

`visibleTo()` builds a query from the grants it read. The rows are loaded later, when the query runs. A grant
revoked between building the query and loading the rows can still show a row once. For writes, check the
single record again with `check()` or `authorize()` (time of check vs. time of use).

## Writing outside AzGuard

The guarantees hold only for writes made through AzGuard (the change pipeline or `Storage::mutate()` with the
right touch). Editing `azg_*` tables by hand or restoring a backup bypasses revisions and epochs. Afterwards,
run `azguard:state:reset {panel}`, which starts a new incarnation and raises the epoch.

## Lock ordering

Every write of a panel first locks its `panel_state` row (`SELECT … FOR UPDATE`). It then writes grants, and
raises the version, epoch and subject revisions at the root commit. Subject revisions are written in a fixed
order. A mutation of several panels locks them in sorted panel order. Host transactions that also lock
`azg_panel_state` must use the same order, or they risk deadlocks. Reads take no locks.

## SQLite

SQLite without WAL is supported. In rollback-journal mode, a read transaction and a write exclude each other, so
checks can wait on writes or fail with `SQLITE_BUSY`. `azguard:doctor` warns about this (`storage.sqlite`).
AzGuard never turns on WAL itself. Configure `journal_mode = wal` and a `busy_timeout` on the connection when
the database is used concurrently. See [Operations](/advanced/operations#sqlite).

## Custom sources: the FencesReads contract

A source that implements `FencesReads` returns a `StateToken` from `state($panel, $tenant)`:

- The token changes whenever the source's grants change, and it does not change otherwise.
- It is cheap to read and has no side effects.
- It is safe to call before and after a read of the source's grants.

AzGuard reads the state before and after each read of the source, up to three times. If the state keeps
changing, the decision fails with `consistency_error`. Only the source read is repeated; nothing else runs
again. Because these tokens are opaque and carry no subject revision, the source's cached sets are keyed by its
whole state.
