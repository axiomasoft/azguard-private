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
