# Security and trust model

AzGuard evaluates authority supplied by trusted application code and configured sources. It does not
authenticate HTTP users, authorize every caller of its change API, or establish a cryptographic identity
for an audit actor. The host must authenticate and authorize every untrusted entry point.

This page defines the security invariants and trust boundaries of AzGuard 2.0, along with their runtime
enforcement points and regression verification suites. Code locations below identify where each invariant
is enforced, and verification entries identify the automated regression and engine tests that guard it.
See [Consistency](./consistency.md) for timing guarantees and [Operations](./operations.md) for deployment.

## Release scanner scope

The reusable release controls require successful CodeQL analyses for GitHub Actions and
JavaScript/TypeScript, including `docs/.vitepress/config.ts`. CodeQL does not analyze PHP;
PHPStan/Larastan, tests, Semgrep and Composer audit are separate controls. CodeQL
requires repository entitlement where applicable; an unavailable analysis or upload blocks
the release rather than substituting Zizmor or actionlint. Entitlement must be verified on
the private repository before a release candidate.

Zizmor audits `.github/workflows` and `packages/core/.github/workflows`
with the pedantic persona at medium severity and above.
The reusable `secret-scan.yml` runs Gitleaks 8.30.1 on the checkout as a directory and on
Git patches reachable from the candidate SHA, including first-parent merge diffs; it does
not scan unrelated refs. The secondary `security.yml` history job instead scans `--all`
refs available in its full-depth checkout, including first-parent merge diffs; this does
not establish coverage of refs absent from that checkout. Neither patch scan establishes
binary file content coverage. The pinned detection
rules in `.gitleaks.toml` remove the upstream allowlists; inline comments and ignore files
are disabled. Findings in history still require investigation. Scanner reports and metadata
identify the commit and scope; missing, failed or incomplete reports block the release.

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

Shared authority uses a versioned HMAC envelope binding the namespace, full cache key digest,
issue/expiry times and payload bytes. `PermissionSetCache::get()` reads the stored bytes without object
deserialization and calls `SignedEnvelope::decode()` before its allowed-class payload decoder. The signer
checks the version, key id, time bounds, size and MAC; contributions are then reconstructed through their
validated factories. Unsigned, malformed, tampered, expired or differently bound entries are cache misses
and reload from the source; a failed reload must deny.

Cross-request caching requires an explicit namespace and signing material; unsupported stores or Redis
client serialization/compression bypass it. Entries have finite TTL, and previous keys need an explicit
verification deadline. See [cache configuration](../reference/configuration.md) for setup and rotation.
An allowlist or dedicated prefix alone does not establish integrity. Never log signing material.

HMAC establishes possession of a shared signing key, not which person acted. It provides neither
confidentiality nor availability, does not repair an incorrect source state token, and cannot prevent replay
of a still-valid envelope at the same key and unchanged revision. Source fencing, revision changes and
finite expiry remain necessary. A backend holding the key can sign false authority.

## Invariant enforcement and verification checks

Each `SEC-*` entry defines an active security invariant, its runtime enforcement points, and the
regression checks that verify its negative and boundary cases. A documentation check alone cannot
discharge an invariant. Paths are relative to the repository root. Method names identify enforcement,
not supported public SPI.

### SEC-01 — Identity stays bound to authority

- **Enforcement:** `packages/core/src/Authorization/Internal/Pipeline/Stages/BoundaryStage.php`
  (`resolve`, `structural`, `decide`), `EvaluationFrame::acceptsContributionScope()` in
  `packages/core/src/Authorization/Internal/EvaluationFrame.php`, and `ReadAttempt::contributions()` /
  `selections()` in `packages/core/src/Authorization/Internal/ReadAttempt.php`. Cache identity is composed
  by `PermissionSetCache::key()` in `packages/core/src/Authorization/Internal/Cache/PermissionSetCache.php`.
- **Bypass and interference:** a host resolver lying about ownership, a cross-panel contribution or reuse
  of a cache entry across tenant, subject, scope, source, storage, build or read mode. No database lock
  repairs a false resolver result; the host must resolve and check the actual record identity.
- **Verification check:** `tests/Feature/Authorization/Cache/CacheKeyTest.php` varies each authority tuple field
  while canonicalizing scope order; boundary and source regressions verify that foreign panel and tenant
  witnesses are denied or rejected with an error, including when another source allows.

### SEC-02 — Visibility preserves host scopes and fails closed

- **Enforcement:** `packages/core/src/Authorization/Visibility.php` (`build`, `predicate`) and
  `packages/core/src/Authorization/Internal/Query/PredicateCompiler.php::constrain()` materialize scopes
  on a clone, retain the resulting predicates and reject unsupported SQL. Removing deferred scope metadata
  after `applyScopes()` does not itself remove materialized SQL predicates (D2).
- **Bypass and interference:** ungrouped OR, UNION, custom Builder/scope behavior or treating an unsupported
  source/condition as allow. A later query execution can race a revoke; a Builder holds captured authority,
  not a lock. Never replace unsupported SQL with filtering a paginated result.
- **Verification check:** `tests/Feature/Visibility/PredicateCompilerTest.php` and
  `tests/Feature/Visibility/UnsupportedTest.php` verify rejection without query weakening, covering OR and
  counting-scope cases as well as `SoftDeletes` and tenant global-scope preservation under D2.

### SEC-03 — Cached grants never freeze host authorization

- **Enforcement:** `ReadAttempt::contributions()` caches raw authority only;
  `packages/core/src/Authorization/Internal/Pipeline/Stages/AuthorityStage.php` (`decide`, `walk`) checks
  membership, grant activity, known roles, eligibility and conditions; restrictions and policies run per operation.
- **Bypass and interference:** caching final decisions, a stale supplied model, membership removal or a
  condition change without a grant revision. Grant writer locks do not lock host membership/policy tables.
- **Verification check:** `tests/Feature/Authorization/Cache/LiveInputsTest.php` warms raw authority, changes
  policy inputs, conditions, restrictions or membership with the same database token, and verifies a fresh denial.

### SEC-04 — Serialized authority is authenticated before use

- **Enforcement:** `PermissionSetCache::get()`, `readEnvelope()`, `decode()` and `put()` integrate
  `SignedEnvelope` in `packages/core/src/Authorization/Internal/Cache/SignedEnvelope.php` for HMAC verification
  before payload deserialization, bounded decoding and rotation.
- **Bypass and interference:** a Redis writer forges an allowed value object, copies another key's bytes or
  replays a signed entry. There is no Redis-lock substitute for key binding; revoke must change the source
  revision, and finite expiry bounds same-key replay. Key/backend compromise remains outside this guarantee.
- **Verification check:** `tests/Feature/Authorization/Cache/EnvelopeIntegrityTest.php` covers forged grants,
  unsigned entries, tampering, copies, rotation and unsafe store transports. `SignedEnvelopeTest.php` in the
  same directory covers authenticated invalid fields, exact expiry and oversize envelopes.
  `tests/Feature/Authorization/Cache/RedisStoreTest.php` exercises real Redis tampering, replay after revoke
  and transaction publication. Local in-memory tests do not replace that dedicated Redis qualification.

### SEC-05 — A read reports the authority it actually consumed

- **Enforcement:** `packages/core/src/Storage/Internal/StorageReadSession.php::snapshot()` pins the
  database read; `ReadAttempt::observe()`, `fenced()` and `confirm()` bind observed state and publication.
  A custom `FencesReads` source must change its token whenever its authority changes.
- **Bypass and interference:** changing grants without changing the token, tearing state and row reads,
  or publishing tentative transaction authority to shared cache. A database reader uses a snapshot, not
  the writer's panel lock; a custom reader uses before/read/after fencing against a source revision change.
- **Verification check:** `tests/Engines/FenceRaceTest.php` places a writer across the read barrier and verifies
  one coherent snapshot or a consistency failure; `tests/Feature/Visibility/UnsupportedTest.php` changes a
  custom source on all three attempts and verifies fail-closed behavior without repeating host evaluation.

### SEC-06 — Writers publish one transactional authority transition

- **Enforcement:** `packages/core/src/Changes/Internal/ChangePipeline.php::operate()` plans and validates
  under the writer lock, records participants inside the transaction and dispatches via `afterCommit`.
  `packages/core/src/Storage/Internal/Storage.php` (`mutate`, `lockPanel`, `commitTouch`) orders panel locks
  and subject revisions; `renewIncarnation()` handles reset.
- **Bypass and interference:** direct SQL, restore, a participant on another connection or events before
  commit. Grant/revoke/reset writers contend on the same `panel_state` lock; multi-panel mutations take
  sorted panel locks, then subject revisions in fixed order. Raw writes require a state reset before serving.
- **Verification check:** `tests/Feature/Changes/TransactionTest.php` verifies that rollback and retry leave no leaked
  authority or duplicate effects; `tests/Modules/Audit/Feature/AuditPluginTest.php` verifies journal rollback
  with the grant and one row after retry, while concurrent engine qualification exercises writer and reset contention.

### SEC-07 — Sensitive use has an explicit freshness and atomicity policy

- **Enforcement:** `StorageReadSession` selects the configured read handle;
  `ReadAttempt::keyAt()` includes read mode and authority identity. `visibleTo()` does not enforce future
  host writes. The host must reload the concrete record and check it against primary authority (D10).
- **Bypass and interference:** a captured Builder or model, replica lag, request state memoization, or a
  revoke between `authorize()` and the host write/export. Primary plus `StateRefresh::Check` covers a
  revoke committed before the check, not one that commits after it. Strict atomicity needs a host protocol
  shared by write/export and revoke, on coordinated connections with the same locks/fence validation.
- **Verification check:** `tests/Engines/ReplicaLagTest.php` pauses replay and verifies that Default may allow while
  Primary denies after primary revoke. `tests/Feature/Visibility/VisibleToTest.php` and
  `tests/Feature/Visibility/RevocationWindowTest.php` cover captured-Builder staleness, concrete-record primary
  revalidation for writes and exports, and the revocation window between check and use (confirming that a
  transaction wrapper on a different connection does not serialize against a revoke).

### SEC-08 — Actor attribution never substitutes for caller authorization

- **Enforcement:** `ChangePipeline::operate()` and
  `packages/core/src/Changes/Internal/ChangeValidator.php::finalize()` protect mutation structure and target
  validity. `packages/core/src/Changes/Internal/ActingActor.php::resolve()` supplies attribution. Neither
  constitutes a universal actor authorization gate. The executable host service `tests/Fixtures/Changes/AuthorizedRoleAssignments.php`
  illustrates the D8 boundary; low-level APIs remain trusted backend primitives. See the
  [assignment authorization guide](../guides/managing-grants.md) for its policy and integration duties.
- **Bypass and interference:** exposing `grantRole()` or managers directly, trusting an HTTP actor field,
  confusing actor with target, or treating an old UI selection as permission. Re-resolve actor server-side
  and authorize actor/target/role/scope for every action. Concurrent membership or role changes need the
  host protocol from SEC-07 if atomicity is required; the grant lock alone does not authorize the actor.
- **Verification check:** `tests/Feature/Changes/ActorTest.php` proves guard/frame attribution and target separation;
  `tests/Feature/Filament/Gate/ForgedCallsTest.php` exercises direct forged actions.
  `tests/Feature/Changes/AuthorizedAssignmentsExampleTest.php` checks the example's denied grants and revokes,
  forged actor fields, foreign targets and scopes, and a user job whose actor lost authority. It checks that
  denials leave grants, revisions, successful events and audit rows unchanged. Attribution tests alone do not
  prove authorization.

### SEC-09 — Failures deny with bounded public disclosure

- **Enforcement:** source failures in `ReadAttempt` fail the operation; unsupported visibility throws.
  The host renderer controls public disclosure. `DecisionResponder` is an existing opt-in adapter for
  403/503/500; ordinary authorization retains its generic 403 behavior (D9).
- **Bypass and interference:** swallowing a source exception as an empty contribution list, accepting a
  partial allow/super-admin result, or exposing internal reason/component/identity in a response or metric.
  A concurrent outage does not justify bypassing authorization; no lock restores source availability.
- **Verification check:** `tests/Feature/Http/DecisionResponderTest.php`,
  `tests/Feature/Http/DenialDisclosureTest.php`, and `tests/Feature/Filament/Gate/DenialDisclosureTest.php`
  verify opt-in status mapping, private exception reasons, and generic HTTP and Filament denial responses
  without leaking internal reasons, source classes, table names, identities or grant fields under source and
  contract failures. Telemetry, if enabled, uses bounded failure labels rather than subject, tenant or grant
  identifiers.

## Limitations

### Residual risks and host duties

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

## Incident response

### Operational key compromise

The incident owner fences traffic and workers if authority integrity is uncertain, inventories affected deployments
and preserves protected logs without copying secret values into tickets. For an exposed cache signing key, assume
accepted authority may have been forged: revoke access to the leaked secret, issue a new key/ID, remove the leaked
ID from **all** verification sets immediately and move every instance to a new namespace/generation. Unlike a
planned rotation, do not allow an overlap for the compromised key. Restart workers, warm from trusted primary
sources, check expected allow/deny identities and production Doctor, and reopen only after deployment evidence
shows no instance still accepts the old key. Expiring Redis entries or resetting state alone does not revoke a signer.

If Redis credentials leaked, rotate/restrict those credentials, verify ACL/TLS/network isolation and inspect
deletion, replay and availability effects. HMAC prevents an unkeyed Redis writer from forging accepted authority,
but neither encrypts cached payloads nor restores availability. If backend/database/source or secret delivery is
compromised, signatures cannot establish correct authority: recover that trust boundary and reconcile grants first.

For `MONOREPO_SPLIT_TOKEN`, registry, deploy or release-signing compromise, revoke the credential at its issuer,
pause affected publishing, inventory reachable repository/package/tag changes and review provenance for the exact
SHAs. Prepare a replacement with the minimum required destinations and permissions; applying remote settings or
registering a token needs its separate owner gate. A local runbook or dry run grants no publication authority.
Supersede a suspect published artifact with a new authorized release; never overwrite a tag. Follow the
[key compromise policy](../security.md#key-compromise-response) and preserve the incident record.

Use the [restore and rotation runbooks](./operations.md#manual-database-changes-and-restores) for the concrete
recovery order. The disposable drill uses generated test keys; it does not validate secret-manager revocation,
production ACL changes or split-token registration.

The [reporting and support policy](../security.md) defines the private contact, supported lines and coordinated
disclosure process. The single maintainer targets acknowledgement within 3 business days, without a contractual SLA.

1. Receive the report privately by email or a verified reporting form; open a private advisory draft where available.
2. Reproduce and classify severity, prioritize critical/high and exploited issues, and agree the next private update.
3. Fix under private access; a branch in a public repository does not preserve an embargo. Add a failing test first.
4. Release a patch on each affected supported stable line, or the next prerelease for development-only issues.
   Coordinate disclosure and mitigations with the reporter; exploitation or a delayed fix can require an earlier warning.
5. Follow the [key compromise response](../security.md#key-compromise-response) for exposed release/signing/cache
   credentials; never rewrite a published tag.

## Emergency release

Use this checklist when a fix must ship outside the normal cadence.

1. Confirm the vulnerable range and the supported lines it touches.
2. Prepare the minimal fix with private access, using an advisory private fork where available.
3. Run the full qualification on the exact commit that will be tagged.
4. Tag the patch release from the protected branch; the release workflow publishes the split repositories.
5. Verify the Packagist versions and a clean install on supported Laravel versions.
6. Publish the advisory, update `CHANGELOG.md`, and mark the vulnerable releases as superseded.
7. If the release itself is faulty, supersede it with a new patch release; never move or delete a tag.
