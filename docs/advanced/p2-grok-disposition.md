# P2 Grok finding disposition

This is a bounded recheck on 2026-10-31 of HEAD `2e173123`, including the previous corrective
commit `91095023`. It records code and test evidence for the supplied P2 findings. It is not
a release qualification, a complete Grok review, or the start of P5.

The original reports and independent triage are under
`plans/2026.10.30-№1-AZGUARD-AUDIT-HARDENING/artifacts/review/grok-phases/`.
The original `P2.jsonl` explicitly reports **no repository/diff coverage**: the maind hook
blocked its tools. The production and test gap reports inspected supplied fragments only.
`P2.conductor.raw-result.txt` also records incomplete repository access. Neither their empty
findings nor this recheck establishes full coverage of the original phase.

The P2.1–P2.7 stage reports were read as historical evidence, including their qualifications
about adapter completeness, missing declared result artifacts, and omitted release/worker
matrices. Their reported checks are not counted as checks run in this recheck. Plan sources,
generated views, and plan state were not changed.

## Ownership and changes

Commit trailers in the reviewed code range identify:

- `e9b6fd4d`: P2.1, shared scalar/visibility contribution qualification and exact automatic roles.
- `460235ff`: P2.2, native Eloquent scopes tests.
- `04c216cf`, `fb10cdd8`, `d507dbe1`: P2.3, generated parity and shared-cache oracle fixtures.
- `417d1b08`: P2.4, dependency resolution and error attribution.
- `e9b213d0`: P3.1, signed cache envelopes; `39c3c3aa`: P3.2, shared TTL and bounded memo.

Thus the gap report's `P3-*` labels are hypotheses, not authoritative phase assignments.
Visibility findings below belong to P2 despite those labels. Cache findings are cross-phase
context already included in the supplied corrective review. Existing later cache fixes were
preserved; no new P3 or P5 feature was implemented. The archived target-map hunk mentioned
by the reviewer was not changed or treated as proof of phase completion.

`91095023` already fixed unmapped selections, retained reconstructed cache values, isolated
the Redis fixture, and validated the full-seed boolean. The current recheck changes tests and
their fixture only:

- Fix the Redis oracle's reproduction command after the helper introduced random store names.
  Choose the test file by the configured driver, rather than the literal store name `parity`.
- Include scoped-only worlds for seeds congruent to 2 modulo 4, including default seed 2.
  Broad witnesses can no longer mask lost scoped contributions throughout the generated corpus.
- Check automatic selection failure category, component, complete safe message, and nonempty
  caller bindings. Add mixed global/concrete automatic scope parity in all three mapped modes.
- Exercise signed expiry round trips with a non-UTC timezone and microseconds.

## Conductor findings

| Finding | Disposition and evidence |
| --- | --- |
| F1, unmapped/global selection | **Fixed in 91095023; verified.** `ReadAttempt::selections()` ignores concrete refs and witnesses when the mapping type is null, retaining tenant-wide witnesses. `UnmappedSelectionTest.php` covers database/custom sources, permissions/roles, with/without a tenant-wide grant, and a mapped concrete alias named `global`. Scalar, batch and exact visibility agree. |
| F2, missing subject silently loses automatic authority | **Rejected as a scalar/visibility mismatch.** `Visibility::inputs()` resolves accepted subject refs before selection; `FolderSource::roleGrants()` and `exactSelections()` both skip automatic roles without an accepted subject model. `AutomaticRoleParityTest.php` tests present `user:1` and absent `user:999`, with applies true/false. An incompatible subject type is rejected in `Visibility::inputs()` before enumeration. A missing selector remains unsupported rather than partial success. |
| F3, forgetState retains an obsolete Allow | **Rejected on current production paths.** `AzGuardServiceProvider` scopes `PermissionSetCache` and handles `StorageTouched` with `forgetPanel()`. There is no production caller of `forgetState()`. Revision-keyed sets are separate from state memo; `ReadAttempt::begin()/key()` reread absent state. `BoundedMemoTest.php` proves eviction rereads state, observes revocation and preserves unrelated sets. The independent conductor triage separately exercised explicit forgetState with a revision bump and database mutation under Request refresh. No blanket set wipe was restored. |

## Production fragment findings

IDs below are exactly those in the final result of `P2.gap.prod.jsonl`.

| Finding | Disposition and evidence |
| --- | --- |
| P3-VIS-NULL-TYPE | **Duplicate of F1; fixed and verified.** The null mapping remains distinct from a concrete `global` alias. See `UnmappedSelectionTest.php`. |
| P3-FOLDER-GRANT-TYPES | **Rejected.** `FolderSource::grants()` yields only the scopes it receives; `exactSelections()` supplies one tenant-wide scope. It cannot place a second concrete grant type into `inEveryContext()`. Automatic scopes are separately type-checked. The automatic failure dataset covers wrong-type scopes; granted-to-all and automatic coexistence passes. |
| P3-GLOBAL-SCOPE-WIDENS-SELECTION | **Rejected with an added executable case.** `AssignmentScopeSelection::everywhere()` does not set `everyContext`; only `inEveryContext()` does. `ReadAttempt` retains each witness's own scope, and `Visibility::build()` filters isolated/global witnesses and compiles concrete branches. The new mixed-context test gets `[1,2,3,4]` in inherit/required and `[1,3]` in isolated, equal to scalar decisions. |
| P3-VISIBILITY-AUTOMATIC-ROLES | **Rejected as a bypass; intended P2.1 parity repair.** `ContributionQualifier` accepts a non-grantable automatic role only as a RoleContribution from an `EvaluatesAutomaticRoles` source and a compiled automatic role definition. Tenant allowlisting and role scope eligibility still apply. `AutomaticRoleParityTest.php` covers trusted/untrusted sources, unknown/non-grantable/forged grants, mapped modes, and global tenant allowlisting. |
| P3-AUTOMATIC-ROLE-WITHOUT-SELECTOR | **Rejected as a defect; intended fail-closed boundary.** An automatic role with no exact selector makes the source unsupported. `UnsupportedTest.php` explicitly proves this, and the sibling-source cases prove an existing grant cannot turn unsupported selection into a successful partial list. Applications must handle the exception without executing the original unconstrained query. |
| P3-FOLDER-UNFENCED | **Rejected on the actual source contract.** The final `FolderSource` does not implement `FencesReads`; `fenced()` would directly execute the same callback. Its volatility is Request. Source-provided automatic enumeration must be complete for its inputs; this change does not invent a revision contract for application code. `UnsupportedTest.php` separately proves three bounded attempts for an actual changing fenced source. |
| P3-NO-MODEL-OMITS-AUTOMATIC | **Duplicate of F2; rejected.** Both scalar and exact paths use the same accepted-model guard. Existing/missing-subject tests pass. |
| P3-STATE-MEMO-NO-WIPE | **Duplicate of F3; rejected.** Current keys cover incarnation, epoch/revision or storage version, generation, fingerprint, subject, scopes, source/read authority and signing namespace. Subject-revision keys intentionally omit unrelated panel-version changes. Scoped lifetime, invalidation and bounded eviction are tested; retention of an old key alone does not authorize a new revision. |
| P2-FACTORY-OBJECT-KEPT | **Fixed in 91095023; verified.** `PermissionSetCache::get()` stores the result of identity reconstruction and Grant/RoleContribution factories. `EnvelopeIntegrityTest.php` proves signed cross-item field references cannot mutate returned or memoized fields, for both contribution classes; object-valued decision fields are rejected. |
| P2-SKEW-DELETES-ENTRY | **Split disposition.** Rejecting future-issued or expired envelopes is intentional: the cache is an optional accelerator, and clock synchronization remains an operational requirement. The concurrent-writer deletion defect was already fixed after 91095023: `forgetEnvelope()` compares bytes, atomically in Redis. `RedisStoreTest.php` proves a replacement survives rejection of missing, malformed and unsigned observations. No clock-skew tolerance or new signing policy was introduced. |
| P2-LOCAL-TTL-SHADOW | **Rejected as stale authorization on the supported lifecycle.** Shared TTL bounds a stored envelope, while request/job memo has its configured validity and always filters contribution expiry. The provider binds the memo with `scoped()`, not singleton; changed authority produces another key or invalidates a panel. `ExpiryTest.php` checks absolute grant expiry and configured TTL; `BoundedMemoTest.php` checks a real two-job worker, including an exception and reset. Manually retaining a cache instance beyond its lifecycle is not tested as a supported usage. |
| P2-SIGNER-SILENT-NULL | **Rejected as an unsafe fallback; configuration failure remains observable.** Invalid signing settings disable shared reads/writes while local authority still works. There is no unsigned fallback. `EnvelopeIntegrityTest.php` covers missing/short/base64-invalid secrets and missing namespace, and checks the `CacheIntegrity` diagnostic for a shared Redis configuration. Invalid previous-key configuration also fails closed in `SignedEnvelope::fromConfig()`. |
| P-MEMBERSHIP-LIFETIME | **Rejected.** `TenantMembershipRestriction` retains only the container, resolves the declared adapter on each check, and stores no subject, tenant or membership result. Membership remains mandatory even for administrator candidates. `TenantMembershipVisibilityTest.php` and generated membership-denial seeds exercise the denial path. No claim is made about a host adapter that incorrectly caches its own state. |
| P2-DATETIME-EQ | **Rejected with an added executable case.** DateTimeImmutable equality compares instants, including microseconds, across timezones. The new signed-expiry round trip uses Asia/Kolkata and microsecond 123456 and is accepted through a fresh cache instance. Exact expiry-boundary rejection also passes. |
| P3-QUALIFIER-REASON-ORDER | **Accepted diagnostic behavior, not a decision defect.** Qualification checks role identity/grantability before tenant eligibility, consistently for scalar and visibility. All rejection reasons still discard the witness. `FormerRoleKeysTest.php` checks former/unknown trace reasons, exact visibility denial and notice fields, with two notices for the two entry points. Former identities are never redirected. |
| P3-LOG-ON-VISIBILITY | **Rejected as an authorization bypass; bounded operational risk remains.** Logging is an application dependency. Visibility wraps unexpected component exceptions as safe `source_error` on a cloned builder; it cannot return an unconstrained successful query. Normal notice cardinality is tested. A deliberately throwing or recursively authorizing host logger was not exercised, and this report does not claim logger availability or reentrancy safety. No catch-and-ignore logging policy was added. |

## Test fragment findings

`P2.gap.tests.jsonl` has no finding IDs. T1–T13 below identify its findings in their original
array order; they do not invent additional Grok findings.

| Finding | Disposition and evidence |
| --- | --- |
| T1, shared Redis config/process leak | **Fixed in 91095023; verified.** `RedisParity::run()` installs a private manager, snapshots cache/signing configuration and the automatic-role flag, purges the temporary store, restores the previous manager, and disconnects its own connections in finally. Both normal and throwing callbacks are tested, including preservation of an existing cache store and manager connections. |
| T2, PhpRedis KEYS prefix and cleanup assumptions | **Rejected for the supported client; verified against real Redis.** Prefix ownership is checked before deletion, all returned keys must belong to it, and only then are suffixes passed to the prefixed client. The restoration test proves a foreign key survives and the owned prefix is empty. The random 96-bit namespace is private to this helper; concurrent uncooperative writes into that same namespace are outside its isolation contract. There is no FLUSHDB. |
| T3, role key mismatch and simplified oracle | **Mismatch rejected; oracle boundary retained.** `VisibilityWorld::role()` defaults to `viewer`, matching database rows and the compiled VisibilityRole. Its permission is `orders.view`. In this corpus both `orders.*` and `orders.view` cover the sole requested permission, so `allows()` correctly treats them alike. It does not prove every wildcard pattern or every diagnostic reason. |
| T4, index zip misaligns rows | **Rejected on this fixture.** Rows are explicitly inserted with consecutive IDs 1–200, fetched with orderBy(id), and VisibilityProject has no global tenant scope. Changing W::$tenanted does not filter that collection. Tenant filtering occurs in the loop and predicate. The native-scopes corpus is separate. The generated 50-seed scalar/batch/list comparisons pass. |
| T5, nullable decision reason crashes messages | **Rejected.** `Decision::$reason` is a non-null DecisionReason. `failure()` is the nullable derived FailureKind, not the reason. Generated assertions execute for both allows and ordinary denials. |
| T6, marker test reuses a stale source or lacks a grant | **Rejected for this source.** GeneratedSource declares Volatility::Volatile; replacing its arrays is observed without generation changes. Null-grant acceptance is for the base context check, while contribution eligibility sees actual grant fields. The initial single rejected marker denies; both orderings of integer/float and binary/float mixtures then allow in scalar, repeated batch, and exact selection. This is a typed-field identity regression, not a full per-resource mixed-marker corpus. |
| T7, deny('city') changes failure semantics | **Rejected.** RestrictionResult::deny requires a nonempty snake_case reason; the old no-argument call was invalid. `city` is the restriction key, and a normal restriction denial is not a transient failure. Restriction seeds in the generated oracle execute with failure() null. |
| T8, FULL_SEEDS=0/false runs 50 seeds | **Fixed in 91095023; verified.** Boolean false spellings select `[1,2]`, true spellings select 1–50, and invalid values throw. The seed-range test also restores all three environment variables in finally. |
| T9, NativeScopes DDL/rows survive tests | **Rejected on the test harness.** Orchestra creates a fresh SQLite memory database per application. For server connections `tests/TestCase::setUpTraits()` drops tables before each test, independently of transaction rollback. All 14 native-scope cases pass in the SQLite run here. A PostgreSQL/MySQL/MariaDB native-scope rerun was not performed in this recheck. |
| T10, enum parameter/registration/GrantedToAll mismatch | **Rejected.** Visibility accepts UnitEnum or string, inputs resolves enum keys through the catalog, and permissions() accepts declaration class strings. The existing every-context test executes enum and string entry points, proving `[1,2,3,4]` for the GrantedToAll permission and no accidental orders.view grant in isolated mode. |
| T11, broad exception and SQL-only assertions | **Confirmed test-quality gap; fixed here.** Each of the eight behaviors now specifies reason and component, checks the entire safe exception message and null previous exception, and preserves both SQL and nonempty bindings. A partial selector's different private exception text cannot satisfy the exact message assertion. |
| T12, broad grants mask scoped loss / visibility cache accounting | **Split disposition.** The corpus weakness is fixed here with scoped-only seeds, including default seed 2, and an explicit check that it contains both allowed and denied rows. The generated array and Redis oracles execute the new worlds. Visibility intentionally materializes selections directly, without PermissionSetCache; its memo/shared labels describe surrounding authority state, not a claimed visibility cache hit. The scalar primer and visibility exception in cache accounting are retained and this limitation is explicit. No per-entry visibility shared-cache-hit claim is made. |
| T13, automatic-role static flag leaks | **Fixed in 91095023; verified.** The Redis helper restores the prior flag in finally and the Redis test file resets it after each test. Restoration is explicitly tested for returning and throwing callbacks that enable the flag. The 1–50 corpus also includes automatic seeds. |

Additional regression found here: after `RedisParity` started using random store names,
`cachedOracle()` still selected its reproduction test by `$store === 'parity'`. It therefore
printed the array test for a Redis failure. The new Redis test deliberately causes a source
read failure and requires the Redis command in the resulting assertion. Its expected stderr
line in the passing log is deliberate, not an unreported failing test.

## Remaining review uncertainties

The production gap report's questions about selection semantics, grant filtering, source
interfaces, factory normalization, memo lifetime, key completeness, TTL type and membership
state are answered by the named implementations above. `put()` takes no ambiguous TTL value
from callers: it reads the panel's validated nullable integer TTL. Invalid memo limits and
worker reset are covered by BoundedMemoTest. The signing namespace is a digest input rather
than a replacement for the `azguard:sets:v2:` key prefix, so the event counters still match.

The test report's expiry concern is answered by `VisibilityWorld::seed()` freezing Carbon at
2026-10-06 12:00 UTC, with equality counted as expired in both facts and contributions.
AssignmentScopeRef IDs are canonical strings, matching automatic-role checks. Former-role
notice counts are executed, not inferred from a fragment.

These limits are retained rather than declared resolved:

- Exact selector completeness is an application adapter contract; tests cannot prove arbitrary
  adapters enumerate every applicable scope. There is no automatic failing-fixture reducer.
- The generated oracle requests one permission and models allowed IDs, not the full public
  wildcard, diagnostic reason, or mixed-marker resource space.
- Exact visibility reads source selections directly. The tests prove shared authority reuse for
  scalar/batch and ID parity for visibility; they do not prove visibility reads permission-set cache.
- Clock skew can cause cache misses. HMAC provides integrity, not confidentiality or source
  freshness independent of revisions; same-key replay before expiry remains within that boundary.
- Invalid signing configuration disables sharing; a host logger or membership implementation can
  still fail. No new operational policy for those host dependencies was introduced.
- Original maind-blocked review coverage remains incomplete. No daemon setup, credential change,
  full release matrix, full acceptance suite, or P5 work was performed.

## Checks performed

All paths below are relative to the repository. Logs are generated proof under the ignored
`build/review/p2-recheck/` directory; they are not plan artifacts. V1 is withheld for the
conductor and was not run. The commands below are individual test files, not full suites.

| Command | Observed result | Log |
| --- | --- | --- |
| `AZGUARD_PARITY_SEEDS=1-50 php -d memory_limit=1G vendor/bin/pest tests/Feature/Parity/AuditParityTest.php --colors=never` | exit 0; 12 tests, 132148 assertions | `array.log` |
| `REDIS_PORT=26379 AZGUARD_PARITY_SEEDS=1-50 php -d memory_limit=1G vendor/bin/pest tests/Feature/Parity/SharedRedisParityTest.php --colors=never` | exit 0; 5 tests, 61951 assertions | `redis.log` |
| `php -d memory_limit=1G vendor/bin/pest tests/Feature/Visibility/AutomaticRoleParityTest.php tests/Feature/Visibility/UnmappedSelectionTest.php tests/Feature/Visibility/NativeScopesTest.php tests/Feature/Visibility/FormerRoleKeysTest.php tests/Feature/Visibility/UnsupportedTest.php tests/Feature/Visibility/TenantMembershipVisibilityTest.php --colors=never` | exit 0; 62 tests, 247 assertions | `visibility.log` |
| `php -d memory_limit=1G vendor/bin/pest tests/Feature/Authorization/Cache/EnvelopeIntegrityTest.php tests/Feature/Authorization/Cache/ExpiryTest.php tests/Feature/Authorization/Cache/BoundedMemoTest.php --exclude-group=redis --colors=never` | exit 0; 46 tests, 207 assertions, before the added timezone test | `cache.log` |
| `REDIS_PORT=26379 php -d memory_limit=1G vendor/bin/pest tests/Feature/Authorization/Cache/EnvelopeIntegrityTest.php tests/Feature/Authorization/Cache/RedisStoreTest.php --colors=never` | exit 0; 34 tests, 302 assertions, including the timezone test | `cache-transport.log` |
| `REDIS_PORT=26379 php -d memory_limit=1G vendor/bin/pest tests/Feature/Parity/AuditParityTest.php tests/Feature/Parity/SharedRedisParityTest.php --filter='includes a scoped-only world\|names the Redis reproduction file' --colors=never` | With the two fixture fixes temporarily reverted: exit 1, both regression tests fail. After restoration: exit 0, both pass. The filter's pipe is a literal regex alternation. | `regressions-before-fix.log`, `regressions-after-fix.log` |

Tests used PHP 8.4.1 and the existing Redis 7 container on 127.0.0.1:26379, database 15,
with private random prefixes and guarded cleanup. No container was created or stopped.
The two-fix mutation run did not change acceptance commands or leave the old fixture in place.

Pint `--test` on the five edited PHP files passed (exit 0, `style.log`). Each file also
passed `php -l` (exit 0, `syntax.log`). `git diff --check` passed (exit 0,
`diff-check.log`). These results cover the final PHP tree; only this disposition document
was edited after those checks.
