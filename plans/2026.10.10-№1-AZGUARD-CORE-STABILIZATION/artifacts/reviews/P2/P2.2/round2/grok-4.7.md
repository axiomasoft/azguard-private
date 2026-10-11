Verdict: RED

## Findings

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
|---|---|---|---|---|---|
| F3 | `tests/Arch/ApiUsage.php`, `tests/Arch/ApiManifestTest.php` | Re-review item 4: no product change since round 1 besides the two corrections and plan bookkeeping | major | Round 1 `git diff --stat` (stream record of that command) is 148 files, 1733 insertions, 1013 deletions. It lists `docs/upgrade.md` as `7 +` and `TargetSelector.php` as `16 +-`, and it has neither arch file. The current tree is 150 files, 1904 insertions, 1029 deletions. Numstat of the two files is `130 14` and `35 0`. mtimes are 2026-10-11 18:29:27Z and 18:29:50Z, after the correction files at 18:12:51Z and after `artifacts/evidence/P2.2/` at 18:21:41Z. `log.jsonl` at 18:30:12Z records the edit as a P2.1 round-3 action: I4 member typing for `T\|null` / `null\|T` / `(T\|null)`, closure and arrow-function variable scope, `$this` as the enclosing class, checked on the declaring type, plus a non-vacuity test. `i4.json` is still 38 to 27, 0 added, 11 removed, every remaining `owner_phase` `P3` (numstat `0 77`). The V2 evidence predates the scanner, so that run does not cover the checker in this candidate. | Keep the I4 scanner edit out of the P2.2 tree and re-run V2 on the tree P2.2 will commit. |

F1 and F2 hold. Detail is under Commands.

## Commands and observations

- `plan.py get PLAN1 P2.2` and `events PLAN1 --item P2.2`. HEAD `9fa3c99a`. Round-1 note at 18:14:43Z says both findings were corrected. A later log line at 18:30:12Z is the P2.1 round-3 note above (bookkeeping).
- Round-1 diffstat versus current numstat: `TargetSelector.php` moved from `16 +-` to `14 6` (stat `20`), which is the access-line and the signature becoming different from HEAD (`fixedTenant()?->id()` to `fixedTenantId()`, return `scope()->tenant->id()`). `docs/upgrade.md` moved from `7 +` to `9 0`, the regex bullet replaced by three lines. No other path from that 148-file stat changed its numstat.
- F1 probe, PHP 8.4.26, script under `/tmp` (removed after the run). Real `CurrentContext`, `ScopedPanelAccess::scope()`, and `TargetSelector::access()` via reflection. The old compared value is the HEAD expression: null when the panel is not the guard panel, otherwise `CurrentContext::get($panel)?->tenant?->id()`. 28 id cells, all identical: guard and non-guard; `TenantMode::None` and `Required`; unset, stored global, tenant int `7`, int `0`, string `'007'`, string `'abc'`, and tenant `7` with assignment scope `dept:3`. Guard + `None` + unset is `NULL`/`NULL` (the round-1 cell). Guard + `Required` + unset is `NULL`/`NULL` through `TenantRequiredException`. `'007'` stays `'007'`. The non-guard compared value stays null when that panel has a stored tenant. 224 `access()` calls plus the unknown-panel call: refusals are 403 with the same message, and the returned `ScopedPanelAccess` matches the HEAD construction (ambient on the guard panel, `inTenant(TenantRef::global())` on a non-guard panel without a tenant definition, `inTenant($ref)` when the directory describes the tenant). Origin `manual`. Guard + `None` + unset scope is `global id=NULL`. Guard + `Required` + unset scope throws `TenantRequiredException` on the returned access. `PROBE_OK`, failures 0.
- F2. `grep -F` / `git grep -nF` of `AzGuard\Contracts\Scopes\TenantDirectory`, `TenantMembership`, and `TenantResolver` matches the HEAD `use` lines (4, 22, and 23 hits, including archived plan snapshots). A one-line `use` file for each also matches, exit 0. The 0.7 section's only change from the round-1 hunk is that one regex bullet (`Tenant(Directory|Membership|Resolver)` to `Tenant$1`) becoming the three literal bullets. Each is `Find: <literal> → Replace: <literal>`. `CurrentContext`, `WithinContext`, `TenantIdentity`, `ContextIdentity`, and `ModelIdentity` are absent from the section. `php8.4 -l` on `TargetSelector.php`: no syntax errors.
- Callers. `git grep fixedTenant` in `*.php` outside `plans/` is only `fixedTenantId` at `TargetSelector.php` lines 134 and 168.
- `php8.4 -d memory_limit=1G vendor/bin/pest tests/Feature/Filament --filter=Target`: 9 passed, 83 assertions, exit 0, 3453 ms. The nine include all of `TargetSelectorTest` (offers panels, refuses `backoffice` / `readonly` / `unknown`, chooses the tenant and checks `access()`).
- `php8.4 bin/api-manifest.php --check`: exit 0.

## Limits

`composer test`, `composer analyse`, `composer lint:check`, and `composer refactor:check` were not re-run. The directory branch of `access()` was executed against one in-memory sqlite row (`probe_tenants` id 7) and a Filament facade whose `auth()->user()` is null. Fiber and queue tests were not re-run. The first probe attempt reported 21 errors on non-guard `Required` panels because `ModelTenantDefinition::resolve()` builds `query()` before `ModelKey::canHold()` and the process had no connection; the sqlite rerun is the result above.

## Model

Grok 4.7 (identity given for this session). A separate resolved build id is not exposed here.
