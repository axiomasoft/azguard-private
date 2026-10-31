# P1 Grok disposition — 2026-10-31

This recheck covers the supplied P1 findings against `d99dea7f`, including fixes in
`c8ed34ca7316cf8da1cf9d60622bdb4bb715db8b` and previous P1 execution
`2e173123e4ca928f48649067a08b27e7906dfdd7`. Subsequent P2–P4 changes are preserved.
No P5 work, release, tag, baseline replacement or plan mutation is included.

## Inputs and coverage

Inputs are under
`plans/2026.10.30-№1-AZGUARD-AUDIT-HARDENING/artifacts/review/grok-phases/`:

- `P1.a.static.jsonl` and `P1.a.triage.md`: five findings, P1.1–P1.2.
- `P1.b.static.jsonl` and `P1.b.triage.md`: seventeen findings, P1.3–P1.5.
- `P1.gap.jsonl` and `P1.gap.triage.md`: four findings on P1.5.
- `P1.retry.jsonl`: blocked review, no code inspected, no clean verdict.
- `P1.conductor.raw-result.txt`: partial review with Redis fixture finding F1.

The maind failure prevented Grok's repository reads. Static reviews excluded
lockfiles; P1.b also explicitly lacked prompt lines 1392–2733. The gap review adds
a historical diff, not a complete independent repository review. Neither the empty
retry findings nor this executor recheck establishes full Grok coverage. Original
reports and triage are preserved without edits.

**Fixed** below means the named scenario is guarded in the current tree;
**rejected** means the finding assumes a guarantee the interface does not make.
Passing controlled fixtures are distinguished from pending real-container proof.

## P1.a

| Finding | Disposition and evidence |
| --- | --- |
| P1A-1: RC versus stable identity | Fixed in `c8ed34ca`: RELEASING steps 3–4 now use `2.0.0-rc.1` and distinguish the later stable tag. Its final version-map paragraph explains stable `2.0.0` development identities versus tag-stamped RC archives. Rejected the requirement that the development map itself use an RC. Root `minimum-stability` is actually `dev`; Filament requires `self.version`, both aliases are `2.0.x-dev`, and neither package embeds a version. This round removes DEVELOPMENT's remaining contradictory “1.0, the first public release” introduction. No RC installation/publication is claimed. |
| P1A-2: C/C+1 tag ambiguity | Clarified in `c8ed34ca`: `RELEASING.md:210` onward distinguishes candidate C for the RC from baseline-only C+1 for the first stable tag. Existing tags remain immutable. No tag was created. |
| P1A-3: ignored inventory | Accessibility wording fixed in `c8ed34ca`: CHANGELOG and RELEASING identify an ignored local artifact rather than a repository hyperlink. `build/release/publication-inventory.json` exists locally; fresh clones do not contain it. Retaining a copy with release approval evidence remains an obligation. Historical remote observations were not repeated or treated as proof of absence. |
| P1A-4: mutation decision | Original removal claim rejected by triage. Actual stale manual-only guidance was fixed in `c8ed34ca`: DEVELOPMENT distinguishes historical 1.0 deferral, the current critical release gate and optional full per-package mutation. No gate is weakened. |
| P1A-5: lock/platform/matrix | Locked-job runtime mismatch fixed in `c8ed34ca`: locked workflows use PHP 8.4, guarded by `tests/Arch/LockedWorkflowPhpTest.php` (3 passed, including negative fixtures). Root PHP `^8.3` is the manifest envelope, not a promise that this development lock installs on every supported runtime. `tests.yml` range jobs run `composer update`. Root `composer validate` passes; inspected lock entries include Laravel `v13.39.0`, pao `v1.1.5` and both local packages `2.0.0`. This is not a fresh dependency-resolution matrix. |

## P1.b

Preflight source references below are in `bin/qualification-preflight.sh`.
Named negative fixtures are in `bin/test-qualification-preflight.py` (30 passed).

| Finding | Disposition and evidence |
| --- | --- |
| P1B-1: remote Docker/local client mismatch | Fixed in `c8ed34ca`: `local_docker()` resolves context/host precedence and requires a local Unix socket before target clients. Remote context/host and context-precedence negatives pass. Forwarded Unix sockets remain part of the trusted local environment; arbitrary Docker proxies are not attested. |
| P1B-2: ignored Compose overrides | Fixed in `c8ed34ca`: explicit `-f docker-compose.yml`, exact config-files label and canonical service hash in `compose_container()`. Foreign-config and changed-hash negatives fail before clients. Successful reports retain project, container, file and hash. |
| P1B-3: dirty source/probe/lock passes | Fixed in `c8ed34ca`: dirt fails the worktree check and aggregate; paths are retained. `test_dirty_tree_is_failed_with_paths` passes. Lock validation/hash describes current bytes; source identity additionally requires the clean tracked tree. Dirty HEAD alone is not attested. |
| P1B-4: invented PHPUnit defaults | Fixed in `c8ed34ca`: parse `phpunit.xml`, require known testing/sqlite/memory defaults and reject unsafe aliases before server clients. `test_phpunit_target_drift_rejected` passes; current XML matches. Worker-name rewriting matches `tests/Support/TestDatabase.php:17`. |
| P1B-5: missing working_dir accepted | Fixed in `c8ed34ca`: require a nonempty working-directory label before resolving it. Missing-workdir negative passes. |
| P1B-6: persistent mounts masking tmpfs/PGDATA | Fixed in `c8ed34ca`: inspect ancestor/descendant mounts and PGDATA in addition to HostConfig tmpfs. Bind, nested-volume and PGDATA-outside-tmpfs negatives pass. Actual image/layout validation remains pending below. |
| P1B-7: arbitrary Redis persistence | Ownership boundary fixed in `c8ed34ca`: require project-owned `redis_data`; foreign-volume and bind negatives pass. Rejected the implied requirement that all Redis storage be ephemeral: the canonical stand deliberately retains this named volume. Only DB 15 and the dedicated namespace are authorized. |
| P1B-8: unrelated primary/standby | Fixed in `c8ed34ca`: equal PostgreSQL system identifiers, primary/standby recovery states and one streaming receiver pointing at the inspected primary. Other-cluster, other-upstream and non-streaming negatives pass. `test_standby_ledger_checked_separately` proves the standby port is inspected too. Real-container proof remains pending. |
| P1B-9: read-only runs SQLite up/connects | Help/docs clarified in `c8ed34ca`: inspection applies migrations to isolated SQLite memory and opens server/Redis read probes, without server migration. `bin/qualification-sqlite-migrations.php:29` onward overrides host targets and validates the isolated connection and PRAGMA database list. Positive, missing and throwing migration fixtures pass under hostile production variables. The flag does not promise zero connections or zero execution of repository PHP. |
| P1B-10: schema/column attestation | Rejected host-application-schema assumption. `tests/TestCase.php:108` defines qualification connections without custom search paths. Preflight only claims two ledger names and seven table names in that target's default schema. DEVELOPMENT excludes column-level equality and host custom connections. SELECT probes do not imply a server-side read-only transaction or qualify application schema drift. |
| P1B-11: boolean switches | Fixed in `c8ed34ca`: only unset/`0`/`1` accepted; invalid values fail environment before clients. `AUTHORITY_REPLICA_TEST=0` stays off, matching PHP's false string `0`. Both switch fixtures pass. |
| P1B-12: argv credentials | Rejected secret-exposure claim for public fixed disposable-stand credentials `azguard` and `authority_test`, already present in TestCase/fixtures. Configured server passwords are read via `getenv` inside PHP, never added to argv or report. No credentialed external service was used. |
| P1B-13: all tools required/stubs pass | Rejected as a defect: this inventories qualification prerequisites including release tools. An exit/version observation is not binary provenance or execution of each tool's suite. Controlled stubs test decisions, not real qualification. Missing tools remain failed/unavailable. |
| P1B-14: coverage PHP lacks lane extensions | Fixed by prior P1 execution `2e173123`: retain selected coverage extensions; require lane PDO and Redis when requested. Missing-PDO, missing-Redis and compatible-fallback fixtures pass. PATH PHP still runs target probes; the coverage binary is reported separately. |
| P1B-15: random empty Redis prefix | Rejected overclaim: report says this namespace is empty and tests check their own namespace at runtime. `RedisStoreTest.php` generates its own 24-hex prefix, checks emptiness and scopes cleanup. Preflight neither certifies every DB 15 key nor reserves its namespace for a later process. |
| P1B-16: truncated report write | Fixed in `c8ed34ca`: sibling temporary file, flush/fsync and `os.replace`, with cleanup on failure. `test_atomic_replace_failure_preserves_previous_report` passes and proves old report preservation on failed replacement. |
| P1B-17: generic references are not behavioral proof | Accepted evidence limitation, not a runtime bypass. Cells declare `basis=static_declaration`; generic references locate declaration tests rather than certify each source/operation. This round adds an automatic-role predicate-adapter variant and unchanged SQL/bindings for a declared selector returning null. Arbitrary custom-source semantics still require host contract/parity fixtures. |

## P1.gap and conductor

Gap findings have no original IDs; G1–G4 preserve their original order.

| Finding | Disposition and evidence |
| --- | --- |
| G1: live conditional versus synthetic unsupported | Rejected contract violation, agreeing with triage. `SourceDescription::$matrix` explicitly declares class capabilities, not evaluated panel roles. The special published profile describes a role without `SelectsAutomaticRoleScopes`; doctor reports that panel condition separately. `FolderSource::exactSelections()` requires the selector; `ReadAttempt::selections()` uses it. Passing tests compare live conditional with the special unsupported profile and require runtime refusal without SQL/bindings changes. |
| G2: conditional Filament/tenant cells | Rejected inferred conjunction. Cells state additional panel/plugin/writer/visibility conditions rather than promising lists or editing for every source. Scalar tenant inheritance does not require exact resource-list selection. No propagation of unsupported between independent declarations or schema change is warranted. |
| G3: synthetic profile/reference coverage/adapter | Evidence gap partly closed: `2e173123` added SQL/bindings checks for source_error and automatic-role refusal. This round tests a role with permissive `FiltersAccessQueries` but no selector and strengthens selector-returning-null checks. All 8 capability tests pass (309 assertions). Synthetic profiles remain explicit test-built declarations; file references remain references, not proof of arbitrary custom-source semantics. |
| G4: check default misses roles | Rejected: this internal pure helper cannot infer absent input. Production `run()` supplies catalog roles, filtering those implementing exact scope selection. Tests cover unsupported-role warnings and a declared selector with no warning but null runtime selection refusal. Legacy two-argument helper remains compatible. |
| Conductor F1: bad prefix reaches healthy Redis | Fixed in `c8ed34ca`: `redis_ok` guards `compose_container()` before PING. `test_redis_bad_prefix_never_pings` passes. The shell driver retains a healthy controlled-service malformed-prefix fixture. That full driver was not run here or inferred green from unit tests. |

## Other original uncertainties

- Manifests/lock now confirm pao `^1.1.5` / locked `v1.1.5`, Filament
  `self.version`, aligned aliases and no embedded versions. `composer validate`
  passes. No dependency update, fresh install or remote publication query ran.
  RC archives and version-range qualification remain release work. The root lock
  is excluded from archives by `.gitattributes`.
- Current PHPUnit defaults and TestCase worker naming were compared locally.
  Compose source defines healthchecks, bounded tmpfs for ordinary databases,
  Redis's named volume and separate replica volumes. This is source inspection
  plus controlled probes, not real image/layout qualification.
- The unauthenticated-cache observation is historical. Current
  `PermissionSetCache::get()` calls `SignedEnvelope::decode()` before payload
  decoding. Current security/consistency docs describe that after `2e173123`.
  Same-key replay until expiry and trusted source/key/backend limits remain
  stated. No cache implementation or another phase's qualification is included.
- Stable cache requires fences in `ReadAttempt`/`PermissionSetCache`, not merely
  a volatility label. Matrix declarations do not qualify arbitrary policies,
  membership, closures or adapters. Folder automatic support evolved after the
  static patch: the blanket claim about every automatic role is superseded by
  `SelectsAutomaticRoleScopes`. Catalog role shape is exercised by the passing
  doctor/overview tests; they do not prove every possible registry failure.
- PCOV `Off` is rejected by the enabled-value allowlist and
  `test_pcov_off_is_not_coverage`. Timeouts fail closed; no cold-host performance
  guarantee or full transitive dependency/security review is asserted.
- Plan acceptance and phase completeness were not reconstructed from plan
  sources. V1's command is withheld for the coordinator. This disposition covers
  supplied findings, not the missing complete independent Grok review.

## Observed checks

Logs are ignored local evidence in `build/p1-recheck/`; retain them with the
coordinator's receipt. Commands ran once, without full acceptance/package suites.
Pest commands explicitly set testing/sqlite/memory and unset replica/worker/URL
overrides. Pint ran before tests.

| Command | Result | Log |
| --- | --- | --- |
| `python3 bin/test-qualification-preflight.py` | exit 0, 30 tests | `preflight-unit.log` |
| `python3 bin/test-qualification-sqlite-migrations.py` | exit 0, positive and missing/throwing cases | `sqlite-migrations.log` |
| `vendor/bin/pest tests/Feature/Diagnostics/SourceCapabilitiesTest.php` | exit 0, 8 tests, 309 assertions | `source-capabilities.log` |
| `vendor/bin/pest tests/Arch/LockedWorkflowPhpTest.php` | exit 0, 3 tests, 5 assertions | `locked-workflows.log` |
| `composer validate --no-check-publish --no-interaction` | exit 0 | `composer-validate.log` |
| `vendor/bin/pint tests/Feature/Diagnostics/SourceCapabilitiesTest.php` | exit 0, ordered imports fixed | `pint.log` |
| `python3 bin/test-qualification-preflight-containers.py` | exit 1, environment failure before service startup | `containers.log` |
| `git diff --check` | exit 0 | `diff-check.log` |

Real-container proof stopped because Docker returned
`all predefined address pools have been fully subnetted` creating the fresh
project network. The finally block removed its three new volumes. Existing
containers/networks were left alone. No successful same-cluster, storage, Redis
or server migration proof follows from that attempt. Repeat after network-pool
capacity is repaired; V1 remains the coordinator's check. Local Read/Grep/Glob
tools were unavailable, so file reads used Python and edits used apply_patch.
