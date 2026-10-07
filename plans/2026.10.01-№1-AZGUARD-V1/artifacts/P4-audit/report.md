# P4 independent read-only audit — AzGuard 1.0 (plan 2026.10.01-№1-AZGUARD-V1, HEAD ee63438)

Auditor: Opus, effort xhigh, read-only. Reproductions: `plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4-audit/repro/*.php` (run from repo root: `php -d memory_limit=1G vendor/bin/pest <file>`); mutants and results: `artifacts/P4-audit/mutation/`. The repository was not changed by the audit.

## 1. Verdict: RED — 2 blocker, 2 major, 5 minor

The scalar pipeline is implemented as specified and largely guarded by tests (19 of 22 targeted mutants killed, see §3): stage order and error→deny, PolicyOnly isolation, expiry, former/unknown/NotGrantable roles, global-role allow-list, membership, superadmin restriction exemption, fence and retries, cache keying.

P4.14's GREEN review missed four defects that produce a wrong Allow or show forbidden rows:
- A01 (blocker): the singleton `Authorizer` captures the scoped `CurrentContext` (via `BoundaryStage`) and `CurrentPanel` (via `PanelResolver`). In Octane/queue workers later requests/jobs are evaluated in the tenant/context/panel of an earlier lifecycle. Reproduced: a request with ambient tenant B gets Allow in tenant A.
- A06 (blocker): exact visibility appends its authority group to the host WHERE without parenthesising host conditions. Any `whereRaw('… OR …')` in the host query or the scope-definition query lets rows bypass authorization. Reproduced: forbidden row 102 listed; a guest (P04a) sees 102.
- A02 (major): a direct `Grant` carrying the role key of a `#[SuperAdmin]` role qualifies as whole-panel superadmin regardless of its pattern, in scalar and exact paths.
- A03 (major): `decideMany` caches external-adapter eligibility under a key using `json_encode(Grant)`, which serialises pattern and scope to `{}`; one contribution's verdict is reused for another. Reproduced: batch Allow where scalar Deny.

Minor: A04 policy invoked (and its veto reported) before NotGranted when no authority qualified; A05 exact visibility skips a restriction whose `appliesTo` depends on the resource; A07 `decideMany` turns direct-API configuration errors into SourceError; A08 on the dynamic Prepare path a source-construction exception escapes `decide()`; A09 exact-visibility tests do not cover global-role and role-scope gates (mutants M11/M12 survive).

None was introduced by P5.2; every affected line predates ee63438.

## 2. Findings (paths relative to `packages/core/src/`)

| ID | Sev | Owner | Location | Violated source | Consequence |
|---|---|---|---|---|---|
| A01 | blocker | P4.6 (+P4.1 registration, P4.9/P4.11 use of `resolve()`) | `AzGuardServiceProvider.php:60,69-70`; `Authorization/Pipeline/Stages/BoundaryStage.php:30,36`; `Authorization/Authorizer.php:42`; `Panels/PanelResolver.php:43-46,236` | V38, V103; 09 §7 runtime table; P4.19 guidance "singleton mutable stack forbidden" | Cross-request tenant/context/panel leak → wrong-tenant Allow |
| A06 | blocker | P4.22 (PredicateCompiler) + P4.12 (Visibility/VisibilityScope) | `Authorization/Query/PredicateCompiler.php:42-55`; `Authorization/Visibility.php:61-63,207`; `Authorization/Query/VisibilityScope.php:82-85` | D15 §6 "host WHERE AND owner AND … AND authority"; P4.22 rule "single nested where group, no outer OR"; P4.12 rule "existing conditions are not weakened by an outer OR"; P04a; R31/R33 | `x OR (y AND authority)` → unauthorized rows listed and counted |
| A02 | major | P4.1 + P4.12 (P4.7 semantics) | `Authorization/Pipeline/Stages/AuthorityStage.php:160,242,251-253`; `Authorization/Visibility.php:150,174,194-197` | P4.1 rule "a direct Grant counts only through its own pattern"; P4.7 "superadmin only via a contribution of that role"; D15 §2 | Grant(pattern X, role: superadmin key) → Allow(SuperAdmin) for every Grants permission + restriction exemption |
| A03 | major | P4.9 | `Authorization/BatchInputs.php:224-225` (external key); `:245-252` (native key omits contribution scope) | P4.9 main property `decideMany[i] == decide` | Wrong Allow/Deny in batch |
| A04 | minor | P4.1/P4.3 (test P4.15) | `AuthorityStage.php:79-100`; `Policies/PolicyDecider.php:80-90`; `tests/Acceptance/Crm/PoliciesTest.php:34` | 09 §2 step 3b | Policy host code runs without authority; reason Policy/PolicyError instead of NotGranted |
| A05 | minor | P4.12 | `Authorization/Visibility.php:325` | D15 §6 (restrictions mandatory AND); P14 parity | Restriction with resource-dependent `appliesTo` skipped in lists |
| A07 | minor | P4.9 | `Authorization/BatchEvaluation.php:194-201`, `:252-259` | P4.1 rule (duplicate restriction key → DefinitionException); parity | decide throws DefinitionException; decideMany returns source_error |
| A08 | minor | P4.17 | `Authorization/Pipeline/Stages/PrepareStage.php:121-123` (vs guarded `:152-158`) | P4.1 "anything sources throw is a deny and does not escape" | Exception escapes decide() on dynamic lookup |
| A09 | minor | P4.12/P4.23 (tests) | `Authorization/Visibility.php:156-159`, `:171-173` untested | P13/P01a/P4.19 rules in exact path; P14 denial controls | Mutants M11/M12 survive the whole suite |

### A01 — blocker — singleton Authorizer captures scoped CurrentContext/CurrentPanel
- `AzGuardServiceProvider.php:60` registers `singleton(Authorizer::class)`; lines 69-70 make `CurrentPanel` and `CurrentContext` scoped.
- `Authorizer::__construct` (`:42`) injects `PrepareStage` and `AccessPipeline` (both hold `BoundaryStage`) and `PanelResolver`. `BoundaryStage` keeps `private CurrentContext $current` (`:30`) and reads `$this->current->get($panel)` (`:36`). `PanelResolver` keeps `CurrentPanel` (`:43-46`), read at `:236`.
- Effect: after `forgetScopedInstances()` (between Octane requests, before each queue job) host code writes the new scoped instance, but `decide`, `explain`, `isSuperAdmin`, `decideMany`/`GateBridge` (via `Authorizer::resolve()`) and `ExplainCommand` keep reading the first lifecycle's instance. In `GateBridge`, `owner()` uses a fresh resolver while `resolve()` uses the stale one, so they can pick different panels. `Visibility` is resolved fresh, so scalar and list diverge. Even with `WithinContext`, job 2 reads a stale (restored-to-null) context, so the context's common filters are skipped.
- Why tests miss it: 17 fixtures call `app()->forgetInstance(Authorizer::class)` (e.g. `tests/Fixtures/Crm/CrmWorld.php:138-139`, `tests/Fixtures/Authorization/AuthorizationWorld.php:38`); the only isolation test `tests/Feature/Scopes/RuntimeInputsTest.php:71` covers fibers in one lifecycle. V38/V103 "two A/B requests/jobs" is not backed.
- Reproduction 1 `artifacts/P4-audit/repro/StaleContextTest.php` (test clock 2026-10-05 12:00 UTC):
```php
$source = new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope('A'))]);   // grant only in tenant A
[$engine, $panel, $request] = ScopeWorld::compile($source);
app(CurrentContext::class)->set($panel, ScopeWorld::scope('A'));
$first = app(Authorizer::class)->decide($panel, $request);
app()->forgetScopedInstances();                                // next Octane request / queue job
app(CurrentContext::class)->set($panel, ScopeWorld::scope('B')); // tenant B, no grant
$second = app(Authorizer::class)->decide($panel, $request);
expect($second->scope->tenant->key())->toBe('org:B')->and($second->allowed())->toBeFalse();
```
Observed: second = [allowed=true, granted, org:A].
- Reproduction 2 `artifacts/P4-audit/repro/StalePanelTest.php`: compile `PanelWorld::adminAndCabinet()`, set CurrentPanel=admin, `resolve`, `forgetScopedInstances()`, set CurrentPanel=cabinet; `app(Authorizer::class)->resolve(SubjectRef::of('user',1),'orders.view')` returns admin (cabinet expected).
- Fix: never capture scoped services in singletons — `BoundaryStage` resolves `CurrentContext` from the container at use time; `Authorizer` resolves `PanelResolver`/`CurrentPanel` per call; or make `Authorizer` and its stage graph scoped. Add an arch/unit rule: no class in the Authorizer graph type-hints `CurrentContext`, `CurrentPanel`, `WithinContext`, `PermissionSetCache` or `ActingActor` as a captured dependency. Regression tests WITHOUT `forgetInstance(Authorizer::class)` for `decide`, `explain`, `isSuperAdmin`, `decideMany`, `GateBridge` (`Gate::forUser()->inspect`) and `azguard:explain` across a `forgetScopedInstances()` boundary; map to V38/V103.

### A06 — blocker — exact visibility does not parenthesise host WHERE; raw OR bypasses authorization
- `PredicateCompiler::constrain` (`:42-46`) only rejects where-clauses whose boolean starts with `or`, then appends our group at `:52` onto unwrapped host wheres. `Visibility.php:207` appends the authority group the same way. `VisibilityScope::query` (`:82-85`) constrains the host `AssignmentScopeDefinition::query()` with owner predicate and `whereKey`, also appending — a descriptor with raw OR defeats owner/tenant and context key.
- `whereRaw("x=1 or y=2")` has boolean `and`, passes the check, and becomes `x=1 OR (y=2 AND authority)`. `visibleTo(subject: null)` (`Visibility.php:61-63`) uses the same compiler, so P04a breaks too. `Scopes/Query/EligibilityBuilder.php:30-34` already re-nests host wheres correctly. Existing tests use only grouped closures (`PredicateCompilerTest.php:148-179`, CRM `VisibilityTest.php:44`). P4.14 dimension 6 claim is wrong for raw SQL.
- Reproduction `artifacts/P4-audit/repro/VisibilityRawOrTest.php` (VisibilityWorld as W, seed/reset):
```php
[$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1)])); // only project 1
$host = VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'");
$ids = $visibility->visibleTo($panel, $host, W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();
// oracle: decide() on every host row
```
Observed SQL: `... where city = 'Rome' or city = 'Paris' and (1 = 1) and (exists …) and (...)`; list = [101, 102], scalar = [101]. Guest `visibleTo(..., null, ...)` on the same host gets [102], [] expected.
- Fix: in `PredicateCompiler::constrain` move effective host `wheres` and their where bindings into one nested group (`forNestedWhere` + `addNestedWhereQuery`, as `EligibilityBuilder::matches`) before appending; route `Visibility.php:207` and `VisibilityScope::query/owner` through that helper. Tests: raw-OR host query in PredicateCompilerTest (SQL shape and rows); CRM R33 search via `whereRaw('name like ? or phone like ?')` with literal ids and scalar parity; P04a guest with raw-OR host → []; scope descriptor whose `query()` has raw OR — tenant-B projects must not leak. Document: callers must not add a top-level `orWhere` after `visibleTo()`.

### A02 — major — direct Grant with a superadmin role key = whole-panel superadmin
- `AuthorityStage.php:160` resolves `$roleDefinition` for any item with `role !== null`, Grants included; `:242` sets `$admin = superAdmin($roleDefinition)`; `:251-253` qualifies and sets `qualifiedSuperAdmin`. Grant coverage uses only its pattern (`:243`) but super_admin applies regardless of item type. Same at `Visibility.php:150,174,194-197`. `Grant::of(..., role:)` is public API (05 §5), so any `ProvidesGrants` adapter recording role provenance escalates and triggers `exemptsSuperAdmin()` exemptions.
- Reproduction `artifacts/P4-audit/repro/GrantRoleSuperAdminTest.php` (AuthorizationWorld root role = `GrantableRootRole`, `#[Role('root')] #[SuperAdmin]`):
```php
$grant = Grant::of(PermissionPattern::of('admin','orders.view'), 'generated', AccessScope::in(TenantRef::global()), role: RoleKey::of('admin','root'));
[$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [$grant]));
$edit = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin','orders.edit')));
```
Observed: view = [true, super_admin], edit = [true, super_admin]; expected edit Deny(NotGranted). Exact path `artifacts/P4-audit/repro/VisibilityGrantRoleTest.php`: `Grant(orders.edit, role: root)` makes `visibleTo('orders.view')` return [101, 102, …] instead of [].
- Fix: `$admin = $item instanceof RoleContribution && $this->superAdmin($roleDefinition)` in AuthorityStage and the same in Visibility (alternatively reject such a Grant as SourceError and document in the SPI). Tests: decide, `isSuperAdmin()` false, restriction not exempted, exact list; add to SuperAdminTest/P01b.

### A03 — major — decideMany external eligibility key collides
- `BatchInputs.php:224-225` key = `IdentityCodec::compose([... $runtime->role?->key(), json_encode($runtime->grant), json_encode(fields)])`. `json_encode` of Grant/RoleContribution drops private-property value objects: three different grants encode identically as `{"pattern":{},"source":"database","role":null,"scope":{"tenant":{},"context":{}},"origin":"manual","expiresAt":null}`. `eligibilityKey()` (`:245-252`) also omits the contribution's own scope (only the selected scope is keyed) → false-deny parity breaks between tenant-wide and context-specific contributions of the same role/pattern.
- Reproduction `artifacts/P4-audit/repro/BatchExternalKeyTest.php` and `BatchExternalKeyAllowTest.php`: tenant panel, `StoreScope` inherit mode, an `AssignmentScopeAccessAdapter` allowing only grants with `pattern->local()==='orders.view'`; two direct grants in store:1 for orders.view and orders.edit; compare decide vs decideMany.

| Grant order | scalar (view, edit) | batch (view, edit) |
|---|---|---|
| edit first | allow granted, deny not_granted | deny, deny |
| view first | allow granted, deny not_granted | allow, allow (wrong Allow) |

`DecideManyParityTest:25` has no contribution-sensitive adapters/filters.
- Fix: key on full contribution identity with `IdentityCodec::compose` (class, pattern or role, scope, source, origin, expiresAt, fields); never `json_encode` value objects; add contribution scope and `Grant::$role` to `eligibilityKey`. Tests: both reproductions as DecideManyParity cases + a native role filter reading `$grant->scope`.

### A04 — minor — policy consulted without authority
- `AuthorityStage.php:79-100` calls `PolicyDecider::decide` in Grants mode even when `$qualified === false`: false → Deny(Policy) (`PolicyDecider.php:80-82`), throwing → PolicyError, both before Deny(NotGranted) at `AuthorityStage.php:98`. `tests/Acceptance/Crm/PoliciesTest.php:34` asserts this order. 09 §2 step 3b: no authority → Deny(NotGranted); then attached policy true/null passes, false vetoes. Consequences: host policy code runs on every unauthorised check, wrong reason, exception masks NotGranted. Never a wrong Allow.
- Fix: return NotGranted before invoking the policy when Grants mode is unqualified; update R22; add a spy asserting the policy is not called. If the owner prefers the current order, record a deviation from 09 §2 (P4.3 text is ambiguous). → may need owner decision.

### A05 — minor — visibility skips a restriction whose appliesTo depends on the resource
- `Visibility.php:325` evaluates `appliesTo($request, $frame)` with the resource-less list request and skips the predicate when false; scalar evaluates with the concrete resource.
- Reproduction `artifacts/P4-audit/repro/VisibilityAppliesToTest.php`: restriction with `appliesTo = $request->resource() !== null`, check allows only Paris, `predicate = P::eq('city','Paris')`, grants on projects 1 and 2 → scalar [101], list [101, 102].
- Fix (OWNER DECISION): either always compile the restriction predicate and let `predicate()` encode applicability, or document that `appliesTo` must be resource-/context-independent and enforce it. Add to ParityPropertyTest.

### A07 — minor — decideMany swallows configuration errors
- `BatchEvaluation.php:194-201` runs `AccessPipeline::start` (calls `RestrictionStage::validateKeys`, throws DefinitionException on duplicate key) inside a try whose `catch (Throwable)` at `:252-259` converts everything into SourceError.
- Reproduction `artifacts/P4-audit/repro/BatchDefinitionErrorTest.php`: panel with two `RecordingRestriction` → decide throws DefinitionException; decideMany returns source_error.
- Fix: let DefinitionException, UnknownPermissionException, SubjectNotAcceptedException and InvalidConfigurationException other than `authority_transaction` propagate out of `group()`, as decide() does; add a parity test.

### A08 — minor — dynamic Prepare lets source construction exceptions escape
- `PrepareStage.php:121-123` calls `$this->attempt()` → `PanelSources::of($recipe, $container)->all()` outside the try starting at `:125`; the static Grants path wraps the same call (`:152-158`) → Deny(SourceError). Scenario: dynamic panel + a named source whose factory throws at runtime → decide() for a non-static permission throws. Established by code reading only (no executed reproduction).
- Fix: wrap the dynamic branch the same way → Deny(SourceError, 'dynamic_sources'); add a test with a throwing source factory.

### A09 — minor — exact-path P13 and role-scope gates are untested
- Mutants surviving all 898 P4 tests: M11 removes the Visibility global-role tenant check (`Visibility.php:156-159`); M12 removes the role-scope acceptance check (`:171-173`). In both cases the Visibility check is the only guard (`DatabaseSource::readSelection` returns global-tenant rows whenever `allowGlobalRoles` is configured; `ReadAttempt::selections` accepts them with no role-scope check). No visibility test/CRM fixture configures `allowGlobalRoles` or a role without a context binding; context membership in the exact path is not exercised by any Visibility test.
- Guards written (HEAD pass, mutant fail): `artifacts/P4-audit/repro/VisibilityGlobalRolesGuardTest.php` (tenant panel with `allowGlobalRoles([GrantableRootRole])` + a global ordinary Grant + a global non-listed `viewer` role → list must be [], fails under M11); `artifacts/P4-audit/repro/VisibilityRoleScopeGuardTest.php` (project-1 contribution of the root role with empty `scopes()` → scalar deny and list [], fails under M12).
- Fix: add both guards (and a context-membership case) to `tests/Feature/Visibility`; extend the P14 generator with tenant mode, global roles and role-scope variety.

## 3. Acceptance claims verified / not verified

Mutation probes (one textual mutant on a scratch copy, 898 tests: tests/Acceptance, tests/Regression, tests/Unit/Authorization, tests/Feature/{Authorization,Visibility,Scopes,Gate,Sources,Policies}; baseline 2 errors = RedisStoreTest without phpredis). Killed: M01 global-role tenant check (3 failures), M02 expiry (16), M03 roleScopeAccepted (2), M04 NotGrantable (5), M05 exemption without superadmin witness (3), M06 fence always stable (10), M08 Boundary owner-tenant (12), M09 Visibility policy veto (5), M10 Visibility branch restrictions (4), M13 Visibility tenant membership (1), M14 Grants-mode veto ignored (17), M15 role filters skipped (16), M16 isolated as inherit (6), M17 batch eligibility key without role (3), M18 DB scope pairs without context (55), M19 context membership off (4), M20 common filters off (35), M21 before Deny ignored (9), M22 Visibility before ignored (4). Survived: M07 (PermissionSetCache expiry filter; equivalent — AuthorityStage:155 and Visibility:147 recheck activeAt), M11/M12 (A09).

Verified by reading and runs: stage order and error→deny per component; `after` only observes, `before` cannot allow; PolicyOnly opens no assignment ReadAttempt or DB read (PrepareStage:152, Visibility:103; R61 aborting connection); GrantedAutomatically provenance tied to the real FolderSource adapter; expiry `expiresAt <= now` in scalar, memo and store (R51); cache key covers storage/panel/incarnation/version/generation, fingerprint incl. buildId, subject, tenant, sorted scopes, source, read mode, authority identity; volatile entries never stored; tentative authority transaction bypasses memos and never publishes; unknown host transaction → authority_transaction from the direct API, Deny(SourceError) from decide, PolicyOnly/code-only untouched; fence/race tests pass on all three engines; CRM README rows R01, R02, R06, R09–R14, R16, R17, R21, R22, R61, R62 match literal tests with positive controls. NOT fully backed: R31/R33 "GREEN exact" (A06); V38/V103 "two A/B requests/jobs" (A01). Gate: owned qualified/prefixed names deny, foreign return null, responses map correctly (except A01 resolver mismatch). P4.14 tail fixes present.

P5.2 regression check: AuthorityStage null-guards inert (unknown roles skipped at :172); Scopes\Query move is namespace-only; `ScopeEligibility::bindings()` validates every binding before filters (stricter, fail-closed); Access path `proposed` empty and actor never null. No P4 regression.

Not verified: ReplicaLagTest (V46/R52) needs the authority-replica profile (skipped, not a pass); V43 latency baseline not rerun; real Octane/queue lifecycle only simulated with `forgetScopedInstances()`; A08 by code reading only; Filament (P7) and P5.2 write semantics beyond P4 seams not audited.

## 4. Checks actually run

| Check | Exit | Result |
|---|---|---|
| `php -d memory_limit=1G vendor/bin/pest --exclude-group=engines` (host PHP 8.3) | 2 | 2998 passed, 1 skipped (ReplicaLag), 2 errors RedisStoreTest "Class Redis not found" (host env) |
| same in `serversideup/php:8.3-cli` | 0 | 3000 passed, 1 skipped (ReplicaLag), 404901 assertions |
| `pest --ci --fail-on-skipped --group=redis` (container, Redis :26379) | 0 | 2 passed |
| Engines PostgreSQL 16.15 / MySQL 8.4.11 / MariaDB 10.11.19 (php8.4, `--group=engines --fail-on-skipped`) | 0/0/0 | 45 passed each |
| phpstan / pint --test / api-manifest --check / type-coverage 99.4% / git diff --check | 0 | pass |
| 9 defect reproductions | fail as expected | StaleContext, StalePanel (A01); VisibilityRawOr ×2 (A06); GrantRoleSuperAdmin, VisibilityGrantRole (A02); BatchExternalKey, BatchExternalKeyAllow (A03); VisibilityAppliesTo (A05); BatchDefinitionError (A07) |
| 2 guard tests (A09) | pass on HEAD, fail under M11/M12 | VisibilityGlobalRolesGuardTest, VisibilityRoleScopeGuardTest |
| 22 mutation probes | — | 19 killed, 3 survived (M07 equivalent; M11/M12 → A09) |

Not run: ReplicaLagTest with the replica profile; bin/coverage-gate.sh; Infection gate; Rector dry-run.
