**Verdict: RED.** The coordinator's fixes hold for the paths the new tests call, and the recorded gates are green. One write bypass remains: on an enforced resource, a Livewire call can still save an enabled inline column or run a raw column-action closure by sending the record key as a JSON number or by naming the arguments. The guard returns before it looks at the column.

Filament v5.8.4 and Livewire v4.4.6 in `vendor/composer/installed.json` are the API facts below. Artifacts in `plans/2026.10.01-№1-AZGUARD-V1/artifacts/P7-audit-fixes/` show Pest 353/353 for the Filament and CRM suites, full Pest 4612/4612, arch, Pint, PHPStan, API manifest, diff check, type coverage 99.5%, and consumer `--with-filament` on Laravel 11, 12, and 13 all exiting 0. I did not execute the probe; the failure is the call path in the locked vendor plus `RefusesEditableColumns`.

## G1 — inline column and column-action checks skip non-positional string arguments

`packages/filament/src/Authorization/RefusesEditableColumns.php:34` returns without `abort` unless `$params[0]` and `$params[1]` are both strings. Livewire does not checksum method calls (`vendor/livewire/livewire/src/Mechanisms/HandleComponents/HandleComponents.php:199-207` and `:543-583`). It passes `$call['params']` through to the hook and then to the method.

Two payloads that a user who can already open the list can post to `/livewire/update`:

- Positional JSON `[ "number", 1, "HACKED" ]`. Key `1` is an int, so the hook returns. `Illuminate\Container\BoundMethod` (no `strict_types`) then calls `updateTableColumnState(string $column, string $record, ...)`. PHP coerces `1` to `"1"`.
- Named JSON `{ "column": "number", "record": "1", "input": "HACKED" }`. There is no index `0`, so the hook returns. `Livewire\ImplicitlyBoundMethod` binds `column`, `record`, and `input` by name.

`vendor/filament/tables/src/Concerns/HasColumns.php:43-75` then writes when the column is editable, visible, and not `disabled()`. The same skip reaches `callTableColumnAction` (`HasColumns.php:16-40`), so a raw closure runs, and `callTableColumnMethod` (`HasColumns.php:81-120`), which does not consult `disabled()`.

Expected under I6 and the new enforcement: 403, and the visible row stays unchanged. Actual: the string positional tests in `tests/Feature/Filament/Gate/SurfacesTest.php:228` and `:273` still pass, and these two shapes never reach that check. A hidden row still fails to resolve. A panel that is not enforced, or that has no plugin, is unchanged because the hook returns at line 28.

This is the historical F2 hole for every record key the table already shows. It does not depend on a second resource, a missing panel, or an excluded class.

Minimal fix: on an enforced guarded table, resolve `column`/`name` and `record`/`recordKey` from names or positions, accept an int key by casting it, and abort 403 when the arguments cannot be resolved. Then apply the existing disabled, closure, and exposed-method rules. Do not `return` before that.

Probe:

```php
Livewire::test(ListOrders::class)->call('updateTableColumnState', 'number', 1, 'HACKED')->assertForbidden();
Livewire::test(ListOrders::class)->call('updateTableColumnState', column: 'number', record: '1', input: 'HACKED')->assertForbidden();
Livewire::test(ListOrders::class)->call('callTableColumnAction', 'number', 1)->assertForbidden();
```

Grant `orders.view_any` and `orders.view` only, with `TextInputColumn` or `TextColumn::action(closure)`. The row must stay `A-1`. Repeat the integer call on a disabled column and expect no write (vendor already no-ops `updateTableColumnState` when `disabled()`).

## What held

I1. Editors write through `PermissionManager`, `GrantManager`, and `actingAs`. `packages/filament/src` has no `DB::table` or storage-model writes.

I2. `AuthorizesActions` installs a default `authorize()` and `authorizeIndividualRecords()` for every action when the serving panel enforces (`AuthorizesActions.php:21-49`). `ActionCalling` checks that response again (`:64`). Custom bulk actions on a resource page have no Filament per-record resolver (`vendor/filament/filament/src/Resources/Pages/Page.php:326-333` returns null except for delete, force-delete, and restore), so the cursor walk at `AuthorizesActions.php:86-88` refuses the whole selection. Native bulk still filters per record. `authorizeIndividualRecords(false)` aborts at `:75`. A later `->authorize(...)` is host authority; `SurfacesTest.php:246-255` relies on that. `FilamentGate::before` returns only `false` or `null` (`FilamentGate.php:162-178`).

I3. Unknown abilities, missing subjects, and missing definitions deny when enforced (`FilamentGate.php:191-207`). Excluded classes and panels without the plugin fall through to Filament.

I4. `SetUpPanel` is persistent (`vendor/filament/filament/src/FilamentServiceProvider.php:106-115`). `EnterPanel` is registered persistent and repeated by class (`AzGuardFilamentServiceProvider.php:51-57`). `FilamentTenantResolver` reads `Filament::getTenant()` and does not invent a tenant.

I5. `TargetSelector::access` rechecks panel and tenant (`TargetSelector.php:127-145`). `GrantEditor::grant` re-describes subject, role or permission, context type, and context (`GrantEditor.php:262-280`) and writes with `actingAs`. Context search uses the target scope and declared proposed fields (`GrantEditor.php:204-218`, `SchemaFields::declared`).

I7. `AuthorizesExports` refuses a job that is not `PrepareCsvExport` or `AuthorizedPrepareCsvExport`, then stores panel, `{key}.view`, tenant, and model (`AuthorizesExports.php:34-53`). `AuthorizedExportCsv::visibleRecords` writes nothing when that authority does not match the job's current panel (`AuthorizedExportCsv.php:46-52`).

I8. `RoleResource` has no write page. Dynamic permission updates go through `PermissionManager`; the core validator rejects a static name. Non-grantable roles and PolicyOnly permissions are rejected by `ChangeValidator`. Raw field keys are still forwarded so the writer can reject them (historical F4, kept on purpose at `ListGrants.php:572-584` and `SchemaFields.php:79-84`).

I9. Plugin settings stay on the instance. `config/azguard-filament.php` is defaults only.

I10. Edit fingerprint is `#[Locked]` and `editingFingerprint` aborts if that form is not open (`ListGrants.php:79-80` and `:457-463`). Bulk revoke still passes raw ids into `revokeMany` (historical F5, `ListGrants.php:476-497`). `ListPermissions::write` no longer calls `resetTable()` (`ListPermissions.php:154-156`).

Also checked and not a bypass: relation-manager boot requires the trait, its three methods, and a related resource that uses `AuthorizesResource` (`AzGuardPlugin.php:548-570`); page and widget hydrate re-enters `canAccess` / `canView`; `reorderTable` goes through `canReorder()`; two resources of one model do not share a permission key (`FilamentKeys` uses the slug); the generator calls `azguard:make:permission` and only adds `#[ForFilament]`.

R25 and R34 no longer say `future: P7.4`. R63 (`tests/Acceptance/Crm/README.md:75`) still says `future: P7.4 UI write boundary`, and R38 already records that boundary. R53 (`README.md:65`) still says `future: P7.2 export consumer`, and R34 already records the Filament export job. Those tails are documentation, not an enforcement hole.

```json
{"verdict":"RED","findings":[{"id":"G1","severity":"major","confidence":"confirmed","location":"packages/filament/src/Authorization/RefusesEditableColumns.php:34","owning_item":"P7.2","failure_scenario":"A user who can open an enforced list, but not update the row, posts Livewire updateTableColumnState with the record key as a JSON number (params [\"number\", 1, \"HACKED\"]) or as named arguments ({\"column\":\"number\",\"record\":\"1\",\"input\":\"HACKED\"}). The hook returns because params[0]/params[1] are not both strings. BoundMethod then calls HasColumns::updateTableColumnState, which writes the visible row. The same skip runs a raw TextColumn action closure via callTableColumnAction and reaches callTableColumnMethod, which does not honor disabled(). Hidden rows still do not resolve. String positional calls stay forbidden.","basis":"I6 and the P7.2 enforcement that enabled editable columns and raw column closures must fail closed before Livewire invokes them. Livewire 4.4.6 does not checksum calls and accepts any params array. HasColumns.php has no strict_types; BoundMethod.php is the non-strict caller, so an int key coerces to string. ImplicitlyBoundMethod binds named column/record/input. The existing tests only pass positional strings.","suggested_fix":"On an enforced guarded table, resolve column/name and record/recordKey from either positions or names, cast an int key to string, and abort 403 if those arguments cannot be resolved. Then apply the disabled, closure, and exposed-method rules. Do not return before that.","probe":"With only orders.view_any and orders.view: Livewire::test(ListOrders::class)->call('updateTableColumnState','number',1,'HACKED')->assertForbidden(); the same call with named column/record/input; and call('callTableColumnAction','number',1) on a closure column. Expect 403 and number still A-1. A disabled column must still not write."},{"id":"G2","severity":"minor","confidence":"confirmed","location":"tests/Acceptance/Crm/README.md:75","owning_item":"P7.4","failure_scenario":"R63 still says future: P7.4 UI write boundary, while R38 already records the PermissionGrantResource PolicyOnly rejection. R53 at README.md:65 still says future: P7.2 export consumer, while R34 records the Filament export recheck. R25 and R34 themselves no longer carry the stale P7.4 tails.","basis":"Phase acceptance requires the CRM README to show P7 R-case status honestly. The tails point at work the phase already records as done. They do not open an enforcement path.","suggested_fix":"Replace those two futures with the existing P7 evidence and leave future: P8.7 where a consumer remainder is real.","probe":"Read README rows R53 and R63 after the edit and confirm neither still names P7.2 or P7.4 as future."}],"checked_invariants":["I1 held","I2 held","I3 held","I4 held","I5 held","I6 partial (G1)","I7 held","I8 held","I9 held","I10 held"],"questions":["DetachBulkAction and DissociateBulkAction are authorized as the ability detach or dissociate, which is not in the default abilities list, so an enforced panel denies them for everyone. Is that fail-closed intended, or should they map to an existing ability such as update?","R49 still says future: P7.4 export. The adopted P7.4 contract has no secret-bearing export. Should that tail stay, move to P8.7, or be removed?"]}
```