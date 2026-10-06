# P4.21 — cache qualification

Executor: codex/gpt-6.1-sol/high; cwd azguard; approval never; danger-full-access.

Scope: core authorization/cache/transactions/catalog and CRM only. Owner asked no unrelated
package/fleet runs. Existing JSON-layout migration preserved. The stale installed Task adapter
references retired plan-work.py; lifecycle uses current Task plan.py.

Owning defect: R57 real Redis test was RED after changing deployment build_id; DB authority
fingerprint was identical. PanelRegistry::freeze now hashes buildId and compiled recipe/catalog
via IdentityCodec. The same-build panel test pins deployment identity; the real Redis test
proves a fresh fingerprint and reloaded grants. No public API signature/schema changed.

| Check | Evidence | Result |
|---|---|---|
| V2 | pgsql-accepted.log | 13 tests / 64 assertions / passed |
| V3 | mysql-accepted.log | 13 tests / 64 assertions / passed |
| V4 | mariadb-final-pass.log | 13 tests / 64 assertions / passed |
| V6 | replica-qualified.log | 1 tests / 15 assertions / passed |
| V7 | redis-qualified.log | 2 tests / 26 assertions / passed |
| V9 | core-regression-accepted.log | 441 tests / 3373 assertions / passed |
| V10 | arch.log | 72 tests / 314 assertions / passed |

Engines: {'pgsql': {'database': 'azguard_test', 'version': 'PostgreSQL 16.14 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit', 'port': 25432}, 'mysql': {'database': 'azguard_test', 'version': '8.4.10', 'port': 23306}, 'mariadb': {'database': 'azguard_test', 'version': '10.11.19-MariaDB-ubu2204', 'port': 23307}, 'redis': '7.4.9'}

Replica: {'commit_lsn': '0/317BE78', 'replay_lsn': '0/317BE78', 'controlled_window_ms': 7.647145, 'default_paused': 'allow', 'primary_paused': 'deny', 'default_caught_up': 'deny'}. Controlled pause duration is a test observation, not a latency/replication budget.

V18/R52: independent-process root revoke denies on Primary/check or new Primary/request; existing Primary/request remains warm until reset. V99: barriers after T_before, between direct/role reads, after grants and after dynamic Prepare discard whole mixed attempts; three changes -> ConsistencyError. Real old repeatable-read snapshot refuses direct source configuration and fails closed through decide. Joint root locks panel_state first; exact worker server lock wait proves revoke cannot commit before protected host write. MySQL uses data_lock_waits; MariaDB uses fresh InnoDB status because information-schema snapshots omitted the wait.

V100/R51/R57: memo/store expiry at now, build/generation and new incarnation with lower restored version reject old authority. Redis uses real serialization and per-run namespace cleanup, no flush. Local CRM mutation invalidates request memo; independent-request window is proven separately on all three engines.

V43 baseline: PHP 8.4.1, Laravel 13.33.0, PostgreSQL 16.14 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit; CPU Intel(R) Core(TM) i7-14700K. 200 samples and 20 warmups per row, hrtime and nearest-rank p95/p99; setup excluded. Membership adapter absent (0), live policy cost separate.

| Grants | Policy | Cache | p95 ms | p99 ms | SQL state/grants/policy/membership/other totals |
|---:|---|---|---:|---:|---|
| 1 | False | cold | 1.510 | 1.635 | {'state': 400, 'grants': 400, 'policy': 0, 'membership': 0, 'other': 400} |
| 1 | False | warm | 0.325 | 0.339 | {'state': 0, 'grants': 0, 'policy': 0, 'membership': 0, 'other': 200} |
| 1 | True | cold | 1.719 | 1.791 | {'state': 400, 'grants': 400, 'policy': 200, 'membership': 0, 'other': 400} |
| 1 | True | warm | 0.555 | 0.567 | {'state': 0, 'grants': 0, 'policy': 200, 'membership': 0, 'other': 200} |
| 10 | False | cold | 1.934 | 2.148 | {'state': 400, 'grants': 400, 'policy': 0, 'membership': 0, 'other': 400} |
| 10 | False | warm | 0.291 | 0.354 | {'state': 0, 'grants': 0, 'policy': 0, 'membership': 0, 'other': 200} |
| 10 | True | cold | 2.149 | 2.366 | {'state': 400, 'grants': 400, 'policy': 200, 'membership': 0, 'other': 400} |
| 10 | True | warm | 0.743 | 0.893 | {'state': 0, 'grants': 0, 'policy': 200, 'membership': 0, 'other': 200} |
| 100 | False | cold | 3.923 | 5.733 | {'state': 400, 'grants': 400, 'policy': 0, 'membership': 0, 'other': 400} |
| 100 | False | warm | 0.638 | 0.898 | {'state': 0, 'grants': 0, 'policy': 0, 'membership': 0, 'other': 200} |
| 100 | True | cold | 3.574 | 3.852 | {'state': 400, 'grants': 400, 'policy': 200, 'membership': 0, 'other': 400} |
| 100 | True | warm | 0.885 | 1.017 | {'state': 0, 'grants': 0, 'policy': 200, 'membership': 0, 'other': 200} |

D44 executable assertions: warm request = 0 state / 0 grant queries; new warm request ×10 = 1 state / 0 grants, policy ×10 = 10 live SQL when present. No invented latency budget.

Checks: scoped Pint/PHP lint; core-only type coverage 99.8%; owning PanelRegistry PHPStan 0 errors; unchanged API manifest check; git diff --check. PHPStan reported unavailable optional turbo extension (dynamic loading disabled) but actual analysis completed; warning retained in core-phpstan.log. Initial failed development attempts remain available and are not acceptance evidence. Full native Grok4.7/high round 1 GREEN (session e747665f-b7c1-42c5-b52c-b5eaae9ffa04), one nonblocking README correction. Executor negative probe then found benchmark unavailable DB cleanup returned 0 through Laravel exception handling. Boot and cleanup now fall inside explicit exception guards; the reserved non-listening loopback-port test proves exit 1, positive benchmark proves exit 0 with 12 valid rows. Final local native re-review GREEN in the same independent session (round 2), final candidate hashes stable. Full scope and bounded correction accepted; no repeat of unchanged package checks. See review-attestation.json and review-verdict.md.

Raw tool progress and patch-context bytes that contain intentional whitespace are preserved verbatim in `raw-evidence.zip`; `raw-evidence-manifest.json` records their names and SHA-256. The raw files remain locally available at the paths named in checks.json. Compact native verdicts and observed routes/tools are committed; verbose native thinking/debug streams remain local.
