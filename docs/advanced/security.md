# Security and trust model

AzGuard evaluates authority supplied by trusted application code and configured sources. It does not
authenticate HTTP users, authorize every caller of its change API, or establish a cryptographic identity
for an audit actor. The host must authenticate and authorize every untrusted entry point.

This page defines the trust contract and the proof obligations for hardening work in **P2–P6**.
Code locations below describe the current enforcement points; requirements explicitly marked **pending**
are not guarantees of the current implementation. Test locations identify existing regression homes and
the concrete negative cases required there, not a claim that all those cases already exist or pass.
See [Consistency](./consistency.md) for timing guarantees and [Operations](./operations.md) for deployment.

## Assets

| Asset | Required protection |
|---|---|
| Host records, writes and exports | Only an authorized subject in the correct panel, tenant and assignment scope may use them |
| Grants, role definitions, conditions and source revisions | Preserve authority integrity and its binding to the selected identity and source |
| Database state and cache namespaces | Preserve storage, panel, incarnation, epoch, subject revision, build and read-mode isolation |
| Signing material and backend credentials | Keep them outside client input, Redis access and untrusted build steps |
| Audit records and diagnostics | Preserve transactional attribution; bound disclosure of identities, grant fields and internal failures |
| Authorization availability | Detect failures and deny safely; denial does not itself provide availability |

## Attacker capabilities and trusted components

| Boundary | Attacker capabilities | Trusted component and responsibility |
|---|---|---|
| HTTP, Livewire and Filament | Forge parameters, record and grant ids, tenant/panel/scope selectors, role names, actor fields and direct action calls; reuse stale UI state | Host authentication, route/action authorization and record resolution. UI visibility and client-supplied identity are not authorization |
| Host service → low-level change API | Request a grant, revoke or role escalation through an exposed endpoint | The server authorizes actor, target, role, tenant and scope before calling the trusted backend primitive |
| Sources and application code | Supply malformed contributions or trigger source errors through exposed inputs | Registered source implementations, policies, membership checks, conditions, hooks and resolvers are trusted executable code; contributions still require validation |
| Database | Race a legitimate grant/revoke or read through a lagging replica | Database engine, connection configuration and authorized writers preserve transaction, lock and revision semantics. Arbitrary database write access is authority compromise |
| Redis / shared cache | Read, replace, delete, copy between keys or replay stored bytes; cache integrity can be lost **without access to APP_KEY** | Redis is a disposable acceleration layer, not an authority root. Restrict credentials, network access and prefixes; authenticate authority bytes before trusting them as specified below |
| Signing key and backend process | Outside the Redis-only attacker model | Access to signing material, backend code execution or a malicious registered source crosses another trust boundary and permits authority forgery |
| Deployment and build runner | An untrusted dependency, artifact or build step may try to replace code, catalogs or steal secrets | Trusted maintainers and deployment runners control installed code, build id, configuration, migrations and secret delivery. Do not expose production signing material to untrusted jobs |

The model includes authenticated users trying to act outside their permissions. It also includes a Redis
writer who cannot change backend code or signing keys. It does not promise resistance to a compromised
host, authoritative database, trusted writer, policy, source implementation or secret-bearing deployment.

## Raw authority and final decisions

The cache stores raw `Grant` / `RoleContribution` data, never a final allow, tenant membership or policy
answer. `ReadAttempt::contributions()` materializes source authority; `AuthorityStage` qualifies it and
evaluates host inputs on each operation. Policy, membership, conditions and restrictions must remain live
on a cache hit. “Live” means at evaluation time using the supplied host models, not an automatic reload
of a model that the caller captured earlier.

**Pending integrity hardening (D3):** shared authority must use a versioned HMAC envelope binding the exact
namespace, full cache key, issue/expiry times and payload bytes. Verify the envelope, supported version,
key id, time bounds and size limits **before deserialization**, then validate every contribution and its
identity. Reject unsigned, malformed, tampered, expired or differently bound entries as a miss and reload
from the source; a failed reload must deny. Rotation must support a bounded verification window for old
keys and an active signing key, with finite TTL. Never log signing material.

The current `PermissionSetCache::decode()` uses `unserialize(..., allowed_classes: ...)` and item type
checks; it does **not** authenticate stored bytes. An attacker can forge a valid allowed `Grant` without
introducing a new PHP class. Until the envelope work is qualified, writable shared cache remains a trusted
integrity boundary; the Redis-only attacker guarantee is pending. An allowlist or dedicated prefix alone
does not establish integrity.

HMAC establishes possession of a shared signing key, not which person acted. It provides neither
confidentiality nor availability, does not repair an incorrect source state token, and cannot prevent replay
of a still-valid envelope at the same key and unchanged revision. Source fencing, revision changes and
finite expiry remain necessary. A backend holding the key can sign false authority.

## Invariant enforcement and P2–P6 checks

Each `SEC-*` entry is a shared proof obligation for the P2–P6 implementation and qualification work.
The checks specify observable negative cases; a documentation check alone cannot discharge them.
Paths are relative to the repository root. Method names identify enforcement, not supported public SPI.

### SEC-01 — Identity stays bound to authority

- **Enforcement:** `packages/core/src/Authorization/Internal/Pipeline/Stages/BoundaryStage.php`
  (`resolve`, `structural`, `decide`), `EvaluationFrame::acceptsContributionScope()` in
  `packages/core/src/Authorization/Internal/EvaluationFrame.php`, and `ReadAttempt::contributions()` /
  `selections()` in `packages/core/src/Authorization/Internal/ReadAttempt.php`. Cache identity is composed
  by `PermissionSetCache::key()` in `packages/core/src/Authorization/Internal/Cache/PermissionSetCache.php`.
- **Bypass and interference:** a host resolver lying about ownership, a cross-panel contribution or reuse
  of a cache entry across tenant, subject, scope, source, storage, build or read mode. No database lock
  repairs a false resolver result; the host must resolve and check the actual record identity.
- **P2–P6 check:** `tests/Feature/Authorization/Cache/CacheKeyTest.php` varies each authority tuple field
  while canonicalizing scope order; extend boundary/source regressions with foreign panel and tenant
  witnesses and require denial/error, including when another source allows.

### SEC-02 — Visibility preserves host scopes and fails closed

- **Enforcement:** `packages/core/src/Authorization/Visibility.php` (`build`, `predicate`) and
  `packages/core/src/Authorization/Internal/Query/PredicateCompiler.php::constrain()` materialize scopes
  on a clone, retain the resulting predicates and reject unsupported SQL. Removing deferred scope metadata
  after `applyScopes()` does not itself remove materialized SQL predicates (D2).
- **Bypass and interference:** ungrouped OR, UNION, custom Builder/scope behavior or treating an unsupported
  source/condition as allow. A later query execution can race a revoke; a Builder holds captured authority,
  not a lock. Never replace unsupported SQL with filtering a paginated result.
- **P2–P6 check:** `tests/Feature/Visibility/PredicateCompilerTest.php` and
  `tests/Feature/Visibility/UnsupportedTest.php`: require rejection without query weakening; retain OR and
  counting-scope cases and add explicit SoftDeletes and tenant global-scope regressions under D2.

### SEC-03 — Cached grants never freeze host authorization

- **Enforcement:** `ReadAttempt::contributions()` caches raw authority only;
  `packages/core/src/Authorization/Internal/Pipeline/Stages/AuthorityStage.php` (`decide`, `walk`) checks
  membership, grant activity, known roles, eligibility and conditions; restrictions and policies run per operation.
- **Bypass and interference:** caching final decisions, a stale supplied model, membership removal or a
  condition change without a grant revision. Grant writer locks do not lock host membership/policy tables.
- **P2–P6 check:** `tests/Feature/Authorization/Cache/LiveInputsTest.php` warms raw authority, changes
  policy inputs, conditions, restrictions or membership with the same database token, and requires a fresh denial.

### SEC-04 — Serialized authority is authenticated before use

- **Enforcement:** `PermissionSetCache::get()`, `decode()` and `put()` are the integration points;
  HMAC verification before deserialization, bounded decoding and rotation are **pending**, as specified above.
- **Bypass and interference:** a Redis writer forges an allowed value object, copies another key's bytes or
  replays a signed entry. There is no Redis-lock substitute for key binding; revoke must change the source
  revision, and finite expiry bounds same-key replay. Key/backend compromise remains outside this guarantee.
- **P2–P6 check:** extend `tests/Feature/Authorization/Cache/RedisStoreTest.php` with forged valid grants,
  unsigned legacy entries, changed MAC/payload/key/namespace/times, expired entries, unknown versions/key ids,
  oversize payloads and rotation. Require source reload, no forged grant, and denial if that reload fails.
  These adversarial envelope cases are pending; current Redis round-trip tests do not prove integrity.

### SEC-05 — A read reports the authority it actually consumed

- **Enforcement:** `packages/core/src/Storage/Internal/StorageReadSession.php::snapshot()` pins the
  database read; `ReadAttempt::observe()`, `fenced()` and `confirm()` bind observed state and publication.
  A custom `FencesReads` source must change its token whenever its authority changes.
- **Bypass and interference:** changing grants without changing the token, tearing state and row reads,
  or publishing tentative transaction authority to shared cache. A database reader uses a snapshot, not
  the writer's panel lock; a custom reader uses before/read/after fencing against a source revision change.
- **P2–P6 check:** `tests/Engines/FenceRaceTest.php` places a writer across the read barrier and requires
  one coherent snapshot or a consistency failure; `tests/Feature/Visibility/UnsupportedTest.php` changes a
  custom source on all three attempts and requires fail-closed without repeating host evaluation.

### SEC-06 — Writers publish one transactional authority transition

- **Enforcement:** `packages/core/src/Changes/Internal/ChangePipeline.php::operate()` plans and validates
  under the writer lock, records participants inside the transaction and dispatches via `afterCommit`.
  `packages/core/src/Storage/Internal/Storage.php` (`mutate`, `lockPanel`, `commitTouch`) orders panel locks
  and subject revisions; `renewIncarnation()` handles reset.
- **Bypass and interference:** direct SQL, restore, a participant on another connection or events before
  commit. Grant/revoke/reset writers contend on the same `panel_state` lock; multi-panel mutations take
  sorted panel locks, then subject revisions in fixed order. Raw writes require a state reset before serving.
- **P2–P6 check:** `tests/Feature/Changes/TransactionTest.php` requires rollback/retry to leave no leaked
  authority or duplicate effects; `tests/Modules/Audit/Feature/AuditPluginTest.php` requires journal rollback
  with the grant and one row after retry. Concurrent engine qualification must exercise writer/reset contention.

### SEC-07 — Sensitive use has an explicit freshness and atomicity policy

- **Enforcement:** `StorageReadSession` selects the configured read handle;
  `ReadAttempt::keyAt()` includes read mode and authority identity. `visibleTo()` does not enforce future
  host writes. The host must reload the concrete record and check it against primary authority (D10).
- **Bypass and interference:** a captured Builder or model, replica lag, request state memoization, or a
  revoke between `authorize()` and the host write/export. Primary plus `StateRefresh::Check` covers a
  revoke committed before the check, not one that commits after it. Strict atomicity needs a host protocol
  shared by write/export and revoke, on coordinated connections with the same locks/fence validation.
- **P2–P6 check:** `tests/Engines/ReplicaLagTest.php` pauses replay: Default may allow while Primary must
  deny after primary revoke. Add the captured-Builder and concrete-record write/export regression to
  `tests/Feature/Visibility/VisibleToTest.php`; a barrier test must show host write/revoke coordination or
  explicitly demonstrate the remaining race. A transaction wrapper on a different connection is insufficient.

### SEC-08 — Actor attribution never substitutes for caller authorization

- **Enforcement:** `ChangePipeline::operate()` and
  `packages/core/src/Changes/Internal/ChangeValidator.php::finalize()` protect mutation structure and target
  validity. `packages/core/src/Changes/Internal/ActingActor.php::resolve()` supplies attribution. Neither
  constitutes a universal actor authorization gate. The executable host service and its adversarial checks
  required by D8 are **pending**; low-level APIs remain trusted backend primitives.
- **Bypass and interference:** exposing `grantRole()` or managers directly, trusting an HTTP actor field,
  confusing actor with target, or treating an old UI selection as permission. Re-resolve actor server-side
  and authorize actor/target/role/scope for every action. Concurrent membership or role changes need the
  host protocol from SEC-07 if atomicity is required; the grant lock alone does not authorize the actor.
- **P2–P6 check:** `tests/Feature/Changes/ActorTest.php` proves guard/frame attribution and target separation;
  `tests/Feature/Filament/Gate/ForgedCallsTest.php` exercises direct forged actions. Extend integration
  coverage with the D8 host service: forged actor, foreign target/tenant/scope and role escalation must
  produce no grant and no successful-effect journal entry. Attribution tests alone do not prove authorization.

### SEC-09 — Failures deny with bounded public disclosure

- **Enforcement:** source failures in `ReadAttempt` fail the operation; unsupported visibility throws.
  The host renderer controls public disclosure. `DecisionResponder` is an existing opt-in adapter for
  403/503/500; ordinary authorization retains its generic 403 behavior (D9).
- **Bypass and interference:** swallowing a source exception as an empty contribution list, accepting a
  partial allow/super-admin result, or exposing internal reason/component/identity in a response or metric.
  A concurrent outage does not justify bypassing authorization; no lock restores source availability.
- **P2–P6 check:** `tests/Feature/Http/DecisionResponderTest.php` checks opt-in status mapping and private
  exception reasons; extend actual HTTP/Filament response coverage to require generic bodies without
  reason, component, identity or grant fields under source and contract failures. Telemetry, if enabled,
  must use bounded failure labels rather than subject, tenant or grant identifiers.

## Residual risks and host duties

- A valid snapshot can become stale immediately after reading. Neither a state token nor an HMAC is a
  lease, revocation barrier or lock on a future host operation. Follow [time-of-use guidance](./consistency.md#visibleto-and-time-of-check).
- Replica lag and request-scoped refresh deliberately weaken freshness. Load both sensitive host records
  and authority from primary; do not let a default-read cache entry satisfy a primary check.
- Raw database edits/restores, incorrect custom source tokens and an uncoordinated participant invalidate
  revision guarantees. Reset state after out-of-band changes and before admitting authorization traffic.
- The audit journal records what trusted application code attributed to an actor. Backend code can set
  explicit actors, system reasons or null. Transactional journaling is not cryptographic identity,
  nonrepudiation or tamper-proof storage; restrict database access and apply host retention/privacy policy.
- Authentication, host policy correctness, secret rotation, deployment integrity, denial-of-service controls
  and monitoring belong to the host. Redis deletion or outage can still impair availability after HMAC hardening.

When adding a source, writer or host entry point, identify the invariant it could bypass and the competing
operation it must coordinate with. Missing or skipped engine/Redis/replica evidence leaves that obligation
unqualified; a passing documentation build does not establish application security.
