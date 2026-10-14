# Compatibility drill (I18, D26)

`bash bin/compat-drill.sh` (PHP 8.4) at HEAD `70c5d153d408670015fe646e29761472d0b4e91e` (after the review fix bb94cea6: DDL index column order): every required break is rejected with its rule id by `php bin/api-compat.php --against=auto` against the clone-only tag v1.0.0, the control stays compatible. Exit 0, 20 s; first recorded at b34524a3, re-run at 70c5d153 after the comparator fix (identical table). Log: `logs/compat-drill-70c5d153.log` (each case lists the comparator lines).

| Case | Break | Expected rule | Exit | Verdict |
|:--|:--|:--|:--|:--|
| D1 | method boot() added to the spi-implement interface AzGuard\Contracts\Plugins\Plugin | implement.method_added | 1 | ok |
| D2 | parameter $subject of AzGuard\AzGuard::check() renamed to $who | parameter.renamed | 1 | ok |
| D3 | case Optional added to the closed enum AzGuard\Tenancy\TenantMode | enum.closed_case_added | 1 | ok |
| D4 | payload key role of role.granted renamed to role_key | format.removed | 1 | ok |
| D5 | core migration 2026_10_01_000000_create_azguard_storage renamed | format.removed | 1 | ok |
| D6 | public method isUnbounded() added to the final api class AzGuard\Changes\GrantFilter | - | 0 | ok |

Baseline `HEAD` = `70c5d153d408670015fe646e29761472d0b4e91e` tagged v1.0.0 in a throwaway clone; main repository tags unchanged.

Targets: the named examples of p4-execution#breaks fit (Plugin is `spi-implement` interface, `AzGuard\AzGuard` is a final `api` class with `namedArguments` true, `TenantMode` is `enum: closed`, `GrantFilter` is a final `api` class). D1 also adds `boot()` to the package's own Plugin implementers (AuditPlugin, ScopesPlugin, ObservedPlugin) so that the manifest generator can load them; D4 renames `role` in `RoleGranted::data()`, which the writer-mode run turns into the `events` and `audit-rows` fixtures; D5 renames the core migration file and regenerates `resources`.
