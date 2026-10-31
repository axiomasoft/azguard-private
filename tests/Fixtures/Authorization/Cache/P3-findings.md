# P3 finding disposition

Original execution baseline: `91095023977a70dbdefda7f408cf166bf5e13e34`; its fixes were committed as
`c16fdefa468f4b7c1ad8112327d89061fefdc306`. Re-execution baseline (2026-10-31):
`d944d935c53c2764eb5ff29042696fedfe1eee19`, including the subsequent P1/P2/P4 changes, which are preserved.
Sources are the final result events in
`plans/2026.10.30-№1-AZGUARD-AUDIT-HARDENING/artifacts/review/grok-phases/P3*.jsonl` and their four preliminary
triage documents. IDs below number findings in each raw result, in their original order. The original `P3.jsonl`
contains **no code review**, because its tools were blocked. `P3.static.jsonl` omitted part of the diff; the gap
reviews inferred behavior from fragments without executing tests. This reconciliation does not turn those partial
reviews into a complete P3 audit or certify earlier P3.6/P3.7 reviews.

Only reproduced defects and concrete test gaps were changed. P2's detached factory rehydration remains intact.
Tests use disposable Redis prefixes, never FLUSHDB, and file-store gadgets use an owned directory removed in finally.
The existing Redis instance and other database containers were preserved.

## Static runtime assertions (`P3.static.jsonl`)

| ID | HEAD verdict and evidence |
| --- | --- |
| S1 | **Partly confirmed and fixed.** Invalid bytes followed by unconditional DEL could remove another writer's valid replacement. `RedisStoreTest` now interleaves a replacement after GET for malformed and unsigned bytes; both fail with the baseline class and pass with atomic compare-and-delete. The cold-miss premise is **rejected**: installed `PhpRedisConnection::get()` converts false to null; the missing-key interleaving already passed at baseline. Network GET still retrieves the full value before the envelope byte bound; no memory-exhaustion exploit was reproduced. |
| S2 | **Confirmed and fixed.** Namespace was absent from the physical key digest. `CacheKeyTest` fails at baseline with identical authority inputs and two namespaces. `IsolationTest` now checks independent signed values in one store, with the same signing secret. Changing namespace causes cold reads. |
| S3 | **Rejected for installed dependency.** `vendor/laravel/framework/src/Illuminate/Cache/ArrayStore.php::all($unserialize = true)` supports false. Serialized-array and fresh-instance shared hits pass in `IsolationTest` and `EnvelopeIntegrityTest`. This is not a certification of every supported dependency version. |
| S4 | **No bypass demonstrated.** `ReadAttempt` qualifies state before forming the contribution key; storage identity, incarnation, epoch, revision and generation participate in that key. State eviction does not promise deletion of unrelated sets. `BoundedMemoTest` now proves state eviction and reread with unchanged revision before separately changing revision and observing revoke. `TransactionGuardTest` and Redis rollback tests now deny in the same scope. |
| S5 | **Already fixed by P2.** `PermissionSetCache::get()` assigns factory return values and reconstructs identity values. Existing detached-reference regressions for Grant and RoleContribution pass; no duplicate runtime change. |
| S6 | **Confirmed observability gap, fixed; remaining URL gap fixed in re-execution.** Doctor reports configured PhpRedis serializer/compression as `cache.transport`, without connecting. It now uses Laravel's ConfigurationUrlParser before merging connection, global and nested options in connector order. `CacheSecurityTest` covers array/string URLs, query options, effective overrides to NONE, safe defaults and Predis; four new cases failed before the fix. Runtime rejection remains based on the actual connection. Custom runtime client mutations cannot be certified by a configuration-only doctor. |
| S7 | **Rejected as a shared-TTL violation.** The documented 3600-second cap applies to shared entries; local memo is request/job scoped (`AzGuardServiceProvider`). It honors configured TTL and contribution expiry. Worker tests prove lifecycle reset, including after a failing job. No new global local-memo cap was imposed. |
| S8 | **Partly confirmed fixture robustness gaps, fixed.** Readiness curl now has timeouts and shutdown has a bounded grace period; worker count is explicit in the invocation as well as `rr.yaml`. The claim that the YAML lacked a one-worker pool is false. A free-port discovery/bind race remains possible; functional sample validation prevents treating an unrelated response as a valid series. Queue RSS is now checked independently in memory/soak modes. PR smoke versus scheduled/manual memory is the existing CI policy and was preserved. |
| S9 | **Intended bounded encoding.** MAX_BYTES bounds the final JSON as well as payload, so base64 overhead reduces usable payload capacity. Oversized authoritative data stays local and is not published; the existing regression passes. No authorization defect established. |

## First test-gap assertions (`P3.gap.jsonl`)

| ID | HEAD verdict and evidence |
| --- | --- |
| G1 | **Gap fixed.** Volatile test reads matching volatility and directly asserts empty sets, panels and shared store. Runtime put/get already bypass Volatile. |
| G2 | **Gap fixed.** Array and Redis rollback checks now make their first denial assertion without `forgetScopedInstances()`. `ReadAttempt` bypasses cache reads/publication during authority transactions; no stale-Allow defect reproduced. |
| G3 | **Gap fixed.** State eviction and reread are observed before the source revision changes, independently of the revoke assertion. |
| G4 | **Gap fixed.** Queue jobs store nonempty contributions and assert exact 1024 set/state bounds and aligned panel keys; RSS remains a supplementary observation. |
| G5 | **Rejected.** Installed Mockery treats unconstrained `shouldReceive` as a stub, not an implicit at-least-once expectation. Both success and exception-job variants run and pass. Explicit once/twice expectations remain on job execution and queue consumption. |
| G6 | **Partly rejected.** `doctor-cache-v2.json` exists and the cache snapshot passes. Explicit exit, finding-key and signing-severity assertions were added. The broader doctor inventory and healthy CRM snapshots had pre-existing MorphIdentity mismatches during the original execution. At the re-execution baseline those have been corrected by intervening work: all three DoctorSnapshotTest cases pass, with no snapshot edits in this round. |
| G7 | **Gap fixed.** Parent test checks the exact forged payload for each attack and verifies that bait is unchanged and still readable afterward. The attacker process already writes the intended key. |
| G8 | **Gap fixed.** Expiry test checks the ArrayStore expiry timestamp and a successful fresh-instance read immediately before the cap, then rejects equality. |
| G9 | **Gap fixed.** `forgetState` is directly asserted, invalid bounds exercise both options, and unequal valid set/state limits are observed. Numeric strings representing valid integers are intentionally accepted by the existing configuration integer parser; they are not a runtime defect. |
| G10 | **Gap fixed.** Fault injection now throws from connection GET and compare-delete EVAL independently of successful connection acquisition and writes. Real Redis command-timeout coverage has a separate TCP fixture. |
| G11 | **Gap fixed.** Envelope Redis test explicitly selects PhpRedis, verifies an empty owned prefix, restores the original serializer and cleans every key under that prefix in finally. Other Redis tests retain per-test random prefixes and guarded cleanup. |
| G12 | **Gap fixed / clarified.** Missing/empty/short/invalid signing material is covered with pre-existing unsigned data; doctor safe configuration is covered by `CacheSecurityTest`. An authenticated same-key replay up to the exact expiry boundary is explicitly tested in `SignedEnvelopeTest`. |

## Cache-focused assertions (`P3.gap.cache.jsonl`)

| ID | HEAD verdict and evidence |
| --- | --- |
| C1 | Same as G1: matching Volatile read plus direct retention assertions. |
| C2 | Same as G3: unchanged-revision eviction and authority reread first. |
| C3 | **Remaining oracle gap fixed in re-execution.** The c16fdefa sentinel payload would also fail shape validation if previous-key MAC verification were broken. The test now changes a valid contribution payload, retaining a matching deadline and the original MAC, and requires rejection both directly by SignedEnvelope and through PermissionSetCache inside the old-key window. A mutant that skips only previous-key MAC verification fails this test; the original gadget/no-wakeup assertion is retained. No runtime MAC defect was reproduced. |
| C4 | **Gap fixed.** Unsupported file store is seeded with a real serialized wakeup gadget; cache get bypasses it without invoking the gadget. The owned directory is removed. |
| C5 | Same as G12: missing current key, empty fallback, short and invalid base64 keys, invalid namespace, and an existing unsigned value are exercised. A valid APP_KEY fallback remains intentional. Minimum length is not an entropy guarantee. |
| C6 | **Gap fixed.** A disposable TCP process acknowledges Redis connection setup and stalls the actual GET. The test warms Allow, commits revoke, changes transport, verifies a logged GET for the authority key, and observes authoritative NotGranted with grant queries. No separate probe can satisfy the oracle. |
| C7 | **Claim clarified.** When authoritative state is unavailable, qualification fails before consulting even a warm shared Allow. The test now says so and asserts zero cache faults, rather than claiming to exercise two independent failing reads in that decision. Separate tests prove Redis faults and database fallback. |
| C8 | Same as G10: GET, PUT and invalid-entry EVAL faults are independent. |
| C9 | Same as G4: exact queue map bounds with nonempty contributions. |
| C10 | **Gap fixed.** First job can throw after filling memo; the next job still sees no prior-job entry. Exception reporting is explicitly checked. |
| C11 | **Gap fixed.** `SignedEnvelopeTest` resigns invalid namespace/digest/version/extra/future/expired/overlong-lifetime fields and oversized syntactically valid JSON. Positive signed controls prevent an always-miss oracle. Protocol v1 MAC framing is retained when deliberately invalidating the version field. |
| C12 | **Gap fixed.** Previous-key read succeeds at +29s and fails at +30s; absent/malformed deadline and short previous key are rejected. A rotation deadline longer than one envelope TTL is permitted: each envelope independently retains its own MAX_TTL bound. |
| C13 | Same as G9: both limits and unequal valid values; valid numeric strings are intentional. |
| C14 | Same as G7: exact per-mode poison bytes and unchanged bait. |
| C15 | **Overbroad assertion rejected; targeted negative added.** Raw payload intentionally contains kernel value objects and DateTimeImmutable. The relevant contract is plain decision fields and an allowed class set, not absence of all PHP object tokens. Authenticated stdClass, DateTimeImmutable and gadget values inside fields are rejected by factory validation without wakeup. |
| C16 | **Gap fixed.** A committed authority change is published after leaving the transaction and reused from Redis in a fresh scope with zero grant queries. Tentative data is still never published inside the transaction. |
| C17 | Same as G9: state is null immediately after forgetState, while the unrelated set survives. |
| C18 | **Gap fixed.** Real Redis PTTL must lie in (15s, 20s] for a grant expiring in approximately 20s, rather than accepting any positive millisecond. |
| C19 | Same as G8: successful pre-boundary read and backend expiry timestamp. |

## Doctor-only fragment (`P3.gap.worker.jsonl`)

| ID | HEAD verdict and evidence |
| --- | --- |
| D1 | **Gap fixed.** Snapshot explicitly requires cache.redis/cache.signing/cache.ttl, exit 1 and error severity for signing; unrelated doctor errors cannot satisfy those assertions. |
| D2 | **Rejected at HEAD and strengthened.** `doctor-cache-v2.json` is present and nonempty; explicit key assertions now also prevent accidentally blessing an empty snapshot. |
| D3 | **Not a full-format test.** This snapshot intentionally projects cache findings and their format. APP_KEY absence is covered through signing availability, not a separate promised app.key finding. The broader full-document snapshot, red during the original execution, passes at re-execution after intervening work. |
| D4 | **Gap fixed.** `CacheSecurityTest` contrasts development warning with production error and already checks disappearance with valid signing configuration. |
| D5 | **Partly covered, no new policy invented.** Signing tests isolate invalid inputs; supported array/file/Redis paths, TTL cap and invalid memory bounds have dedicated tests. Empty Redis prefix is not forbidden when namespace and envelope binding provide isolation. Whitespace-only namespace is not an existing specified validation rule. |
| D6 | **Rejected.** `permissions([OrderPermission::class, DatabaseSource::make()])` is valid fixture configuration; the passing cache snapshot explicitly observes security findings. |
| D7 | **Rejected.** Testbench refreshes the application between tests; fixture runtime registries are also reset by the file's afterEach hook. This is not persistent application configuration leakage. |

## Actual worker fragments (`P3.gap.workers.actual.jsonl`)

| ID | HEAD verdict and evidence |
| --- | --- |
| W1 | **Unsubstantiated; no permission expansion.** The assertion assumes artifact upload uses GITHUB_TOKEN actions:write. No Actions 403 was supplied or reproduced, and current HEAD already uses pinned upload-artifact v7, not the asserted v4. Token permissions were left unchanged. Hosted artifact upload was not run locally. |
| W2 | **Confirmed checker gap, fixed.** RSS growth now includes the maximum over all post-warmup samples relative to warmed RSS, in addition to the final-window growth. Negative 10,000-measurement fixtures cover early plateau and early transient peak; both were invisible to the old final-5000-only oracle. Warmup is deliberately excluded from measured growth. |
| W3 | **Confirmed test distinction.** Poison `time` is timestamp tampering, not an independently signed expiry attack, and remains so. Exact bytes are now asserted; authenticated expiry/future/precision cases live in `SignedEnvelopeTest`. |
| W4 | **Existing CI policy retained.** PR/push run smoke; scheduled/manual/opt-in runs memory. CLI rejection self-tests are now invoked by the workflow. No basis was supplied to change PR workload policy or add a dispatch schema. |
| W5 | **Partly confirmed, fixed where meaningful.** HTTP markers vary per request and are checked against the request number. Queue memory/soak receive their own warmup, measured workload and RSS check. Re-execution fixes a c16fdefa reporting regression: --report jobs now counts measured jobs, excluding the 200 warmup jobs, just as requests does. HTTP and queue are distinct processes; the empty-header check alone never proved a transfer between them. Queue memo cleanup, including exception jobs, is independently covered. No generic queue Request-object reset guarantee is claimed. |
| W6 | **Rejected by execution.** Archive self-test installed Octane 2.12.0, CLI 2.7.1, HTTP 3.6.0 and RR 3.0.1, then successfully drove one persistent HTTP worker and one queue worker. Pool size is explicit both in config and CLI. No speculative dependency change. |
| W7 | **Rejected for the actual caller.** `RedisStoreTest` supplies matching target, bait, database and namespace; the separate producer deliberately receives signing material and the attacker does not. Five per-mode byte assertions now show the intended key was poisoned; unchanged bait rules out clearing the prefix. Missing-key arbitrary injection is not this fixture's contract. |
| W8 | **Rejected.** WorkerMembership supplies membership without tenant model lookup; real worker self-test succeeds with the existing tenant marker model. |
| W9 | **Rejected.** SubjectRef and ActorRef canonicalize integer IDs; real worker output verification succeeds for all five cases. |
| W10 | **Confirmed and fixed.** Readiness curl has connect and total timeouts. |
| W11 | **Gap fixed.** `rejected()` is checked in the final postcondition as well as on entry, including the last lifecycle. |
| W12 | **Confirmed CI robustness gap, fixed.** Missing early-failure artifacts now warn, preserving the original failing step instead of adding a second failure. |
| W13 | **Rejected.** `RedisStoreTest` invokes PoisonWorker through Symfony Process; the claimed missing caller was outside the reviewed fragment. All five modes were executed. |

## Re-execution evidence (2026-10-31)

All 60 findings are accounted for above: S1-S9, G1-G12, C1-C19, D1-D7 and W1-W13.
`P3.jsonl` has no findings because the review did not run; it is not a clean security verdict.
The following are actual re-execution results, separate from the historical logs below. V1 is withheld
and belongs to the conductor; neither V1 nor a whole acceptance suite was run here.

- `build/p3-recheck/redis-url-before.log`: the expanded CacheSecurityTest `reports configured unsafe`
  dataset before the runtime fix: 11 cases, 7 passed, 4 failed. The failures demonstrate missed transforms
  and false warnings from ignoring URL overrides. No Redis connection is opened by this diagnostic.
- `build/p3-recheck/targeted-tests.log`: `php -d memory_limit=1G vendor/bin/pest` with these eleven explicit
  files and `--colors=never`: **127 passed, 100647 assertions**, including the Redis-group examples (no skips).

| Findings rechecked | Test file(s), repository-relative |
| --- | --- |
| S2, G9, C13, C17 | `tests/Feature/Authorization/Cache/CacheKeyTest.php`, `BoundedMemoTest.php` in the same directory |
| S3-S5, S7, S9, G1-G5, G8, G12, C1-C5, C9-C13, C15, C19 | `tests/Feature/Authorization/Cache/{BoundedMemo,EnvelopeIntegrity,Expiry,Isolation,SignedEnvelope,TransactionGuard}Test.php` |
| S1, G2, G7, G10-G11, C6-C8, C14, C16, C18, W3, W7, W13 | `tests/Feature/Authorization/Cache/{RedisFailure,RedisStore,EnvelopeIntegrity}Test.php` |
| S6, G6, D1-D7 | `tests/Feature/Diagnostics/CacheSecurityTest.php`, `tests/Regression/Formats/DoctorSnapshotTest.php` |

- `build/p3-recheck/previous-mac-negative.log`: the single EnvelopeIntegrityTest `rotates keys only`
  case with a disposable SignedEnvelope mutant that omits only previous-key MAC verification: **exit 1**,
  failing at the new direct rejection assertion. The mutant and its bootstrap live only in ignored build output;
  no production class was replaced. The corresponding unmodified implementation passed in the 127-test run.
- `build/p3-recheck/{memory-check,worker-outcome,worker-cli}.log`: the repository self-check commands
  `php tests/Fixtures/Workers/test-memory-check.php`, `php tests/Fixtures/Workers/test-verify.php` and
  `bash tests/Fixtures/Workers/test-cli.sh` all passed. Memory fixtures include early plateau/peak negatives (W2).
- `build/p3-recheck/style.log`: targeted Pint `--test` passed on the three changed PHP files.
  `bash -n bin/worker-smoke.sh tests/Fixtures/Workers/test-cli.sh` passed.
- `build/p3-recheck/worker-memory.log`: `bash bin/worker-smoke.sh memory --requests=5000 --jobs=5000
  --report=build/p3-recheck/worker-report.json` passed against a fresh archive consumer, using Octane 2.12.0,
  RoadRunner CLI 2.7.1, HTTP 3.6.0 and RR 3.0.1. One HTTP process and one distinct queue process each completed
  5,200 lifecycles (200 warmup plus 5,000 measured). HTTP RSS growth was **2,199,552 bytes**, queue growth
  **1,814,528 bytes**, both below **8,388,608 bytes**. The summary links to the retained series and process logs.
- `build/p3-recheck/worker-report.json` and `report-check.log` in that directory: explicit report validation
  passed for 5,000 measured requests, 5,000 measured jobs, 200 warmup each, distinct process IDs and exactly
  5,200 records in each series. The old shell argument would report 5,200 jobs and fail that comparison.
- `build/p3-recheck/diff-check.log`: clean `git diff --check`. No plan file was written and no commit was made.

S8 and W2/W5/W6/W8-W11 are also exercised by the real archive-consumer memory run recorded below.
W1/W4/W12 retain their bounded dispositions: hosted artifact transport was not executed locally, the workflow
still runs smoke on PR/push and memory on schedule/manual/opt-in, and missing artifacts still warn.
The free-port discovery/bind race, full supported-version matrix, custom runtime client mutations, external Redis
TLS/ACLs and unexecuted full-default/soak workloads remain limits, not silently closed findings.
This is a finding reconciliation with focused tests, not a new independent review of all P3 code.

## Original execution evidence (historical, before c16fdefa)

All logs are under ignored `build/p3-findings/`; no plan source, state or generated plan view was edited.

- `targeted-tests.log`: first targeted run of eleven named PHP test files. It exposed test setup errors corrected
  locally and two unrelated pre-existing doctor snapshots. Initial result: 113 tests, 104 passed, 6 failed, 3 errors.
- `corrected-tests.log`: `php -d memory_limit=1G vendor/bin/pest` on BoundedMemoTest, EnvelopeIntegrityTest,
  RedisFailureTest and RedisStoreTest, with `--colors=never`: 61 tests passed after correcting those setup errors.
- `additional-tests.log`: targeted object-field and doctor production/development tests.
- `cache-regressions-before.log`: same four namespace/race examples using the baseline class through PHP
  auto_prepend_file: three failures, one pass (the already-correct missing-key behavior).
- `cache-regressions-after.log`: the four examples pass with the final runtime implementation.
- `doctor-baseline.log`: the two full doctor snapshot failures reproduced with baseline CacheIntegrity, loaded
  after Composer's autoloader. Differences are MorphIdentity inventory/findings; P3 cache snapshot passes.
- `memory-check.log`, `worker-cli.log`, `worker-outcome.log`: fixture self-check commands
  `php tests/Fixtures/Workers/test-memory-check.php`, `bash tests/Fixtures/Workers/test-cli.sh`, and
  `php tests/Fixtures/Workers/test-verify.php` passed.
- `memory-regression-before.log` and `memory-regression-after.log`: the same 10,000-measurement early-plateau
  series passed the baseline checker (exit 0, reported growth 0) and was rejected by the final checker
  (exit 1, growth 11,534,336 bytes exceeding 10,485,760). Rejection is the expected negative result.
- `worker-self-test.log`: `bash bin/worker-smoke.sh self-test` passed 10 HTTP and 10 queue lifecycles against a
  disposable archive consumer; archive hash, dependency versions and server/job logs are retained in its report directory.
- `worker-memory.log`: `bash bin/worker-smoke.sh memory --requests=5000 --jobs=5000` passed 5,200 HTTP and 5,200
  queue lifecycles (200 warmup per process). HTTP RSS growth was 2,199,552 bytes and queue growth 2,142,208 bytes,
  each below the 8,388,608-byte limit. The full 10,000/default and 100,000/soak workloads were not run.
- `pint.log`: `php vendor/bin/pint --dirty` passed; `bash -n bin/worker-smoke.sh tests/Fixtures/Workers/test-cli.sh`
  passed. `final-style.log` records the subsequent style check of the two tests receiving additional cases;
  `diff-check.log` records a clean `git diff --check`. Acceptance V1 and whole test suites were not run;
  the conductor owns them.

No claim is made about a forged Allow bypass, complete multi-version qualification, live hosted Actions behavior,
Redis TLS/ACL configuration, or a complete P3 security review.
