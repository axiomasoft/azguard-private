# Relocation rehearsal for Audit (I19, P4.4)

`bash bin/consumer-fixture.sh relocated-audit --report=plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/evidence/P4/relocation-table.md` (PHP 8.4, Laravel 13.x, SQLite) at HEAD `70c5d153d408670015fe646e29761472d0b4e91e`. Exit 0, 101 s (2026-10-14T12:29:57Z - 12:31:38Z). Log: `../P4.4/V1.log`.

The first run of the code of 277b4ec4 was preceded by the Grok 4.7 review, which found that the Audit-commands cell of this report was built with PHP backticks (shell_exec) and would have been empty; fixed in aa261647, this table is produced by the fixed script.

Relocated archives carry the next patch version (1.0.0 -> 1.0.1): Composer replaces an installed artifact package only for another version (recorded deviation of P4.4).

Result: before (full core 1.0.0 with modules/audit) and after (core 1.0.1 without modules/audit + synthetic axiomasoft/azguard-audit 1.0.1 with the same PSR-4 prefix and provider) keep the migration state, the journal rows (same event ids and payload hashes), the Audit command synopses, the published migration names and the doctor result; one autoload owner for `AzGuard\Audit\` moves from vendor/axiomasoft/azguard/modules/audit to vendor/axiomasoft/azguard-audit.

| Archive | sha256 |
|:--|:--|
| `build/dist/axiomasoft-azguard-1.0.0.zip` | `2828c364f5759d2fd58ebf5f3155e8cce37b415614489711ce09b5b0a8a27c86` |
| `build/dist-relocated/axiomasoft-azguard-1.0.1.zip` | `8e3d19bd895c6f96c6f2c67d50c8e405240c06b4e96ef69a6540d9e38db58fe1` |
| `build/dist-relocated/axiomasoft-azguard-audit-1.0.1.zip` | `7f78e18eaac75ba562db3b09dd01ad6cf31acfe0a4e82149aa79948b33dee780` |

| Fact | Before (core 1.0.0) | After (core 1.0.1 + azguard-audit 1.0.1) |
|:--|:--|:--|
| AuditPlugin file | `vendor/axiomasoft/azguard/modules/audit/src/AuditPlugin.php` | `vendor/axiomasoft/azguard-audit/src/AuditPlugin.php` |
| migrate:status | 0001_01_01_000000_create_users_table Ran;0001_01_01_000001_create_cache_table Ran;0001_01_01_000002_create_jobs_table Ran;2026_10_01_000000_create_azguard_storage Ran;2026_10_20_000000_create_azguard_audit_log Ran | 0001_01_01_000000_create_users_table Ran;0001_01_01_000001_create_cache_table Ran;0001_01_01_000002_create_jobs_table Ran;2026_10_01_000000_create_azguard_storage Ran;2026_10_20_000000_create_azguard_audit_log Ran |
| journal rows (count, sha256 of event id + payload) | 2: 440b857f15e4, 5aac6902805a | 2: 440b857f15e4, 5aac6902805a |
| Audit commands | `azguard:audit:migration <storage>` `azguard:audit:prune [--panel [PANEL]] [--before [BEFORE]] [--force]`  | `azguard:audit:migration <storage>` `azguard:audit:prune [--panel [PANEL]] [--before [BEFORE]] [--force]`  |
| published migrations | 0001_01_01_000000_create_users_table.php 0001_01_01_000001_create_cache_table.php 0001_01_01_000002_create_jobs_table.php 2026_10_20_000000_create_azguard_audit_log.php | 0001_01_01_000000_create_users_table.php 0001_01_01_000001_create_cache_table.php 0001_01_01_000002_create_jobs_table.php 2026_10_20_000000_create_azguard_audit_log.php |
| azguard:doctor --json exit / findings | 0 / storage.sqlite | 0 / storage.sqlite |

After: `migrate --pretend` has nothing to run, `vendor:publish --tag=azguard-audit-migrations` publishes no file, one more audited grant appends exactly one row, `azguard:audit:prune --force` exits 0, `AzGuard\Audit\` has one PSR-4 and classmap owner (vendor/axiomasoft/azguard-audit).
