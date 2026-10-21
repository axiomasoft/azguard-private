# Filament catalog validation F1 follow-up

This bounded follow-up corrects native bulk-hook coverage in FilamentDiscovery after
the original repair in commit `fd6e606d9a24ac91f96f8e1a47dcbc7348665bdf`.
The original repair and its history remain intact; see
[the original diagnostic repair](insiders-catalog-validation-fix.md).

## Confirmed failure

The coordinator's `azguard-F1-confirmed.md` and `azguard-F1-pest.before.log` under
`../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/` (relative to
the package root) establish F1: an ungranted user's
`hasPermission('admin.project.delete', 'admin')` was false, the native
`canDeleteAny()` was true through a replaced bulk response hook, and strict
catalog validation incorrectly exited 0 with no warnings. The proper Pest
reproducer failed with 3 assertions. The earlier triage Docker permission failure
was an infrastructure failure and did not disprove F1.

The local regression matrix reproduced the same false pass for all six bulk-hook
overrides before the production change: 6 failures, 18 assertions, exit 1.
Each fixture first establishes that its permission is ungranted while its native
bulk `can*Any()` allows access, then expects strict validation to reject that
binding. See [bulk-before.txt](insiders-catalog-validation-F1-evidence/bulk-before.txt).

## Correction and coverage

FilamentDiscovery now derives every policy method sharing a permission slug from
the existing `FilamentActions::methodsFor()` / `MAP`. A slug qualifies as a native
binding only when every corresponding `can*` and `get*AuthorizationResponse`
hook retains Filament's implementation. This covers `delete/deleteAny`,
`restore/restoreAny`, and `forceDelete/forceDeleteAny`, preserving the native
`update` to `Edit` hook naming. There is no second production action list.

The six new fixtures independently replace the bulk response and direct
`can*Any()` hook for each of the three shared slugs. The matrix verifies one
warning for the affected slug, strict exit 1, non-strict exit 0 with the warning
retained, no errors, and unchanged catalog keys. The all-native test now exercises
both native hook forms for every canonical action with an ungranted and then
granted user. It also checks exact catalog keys and strict PASS.

Existing focused tests retain guarded resource/page/widget authorization,
genuine orphans, forged and unsupported definitions, disabled/policy-source
bindings, replaced hooks, stale/excluded subjects, absent runtime model binding,
and custom schema behavior. Separate core-only tests and the committed public API
snapshot pass. Catalog generation, runtime authorization, configuration, core
sources, public signatures, and dependencies are unchanged.

## Actual verification

The owned `insiders_qnn_azguard_pgsql` container was running on
`insiders_insiders_network`; `pg_isready` exited 0. The supplied bootstrap
validated the required environment and connected to `insiders_test`.
Reflection loaded FilamentDiscovery and FilamentActions from the owning
`packages/filament/src` sources and Filament Resource from the supplied
development vendor volume. See [preflight.txt](insiders-catalog-validation-F1-evidence/preflight.txt).
RefreshDatabase ran sequentially on that owned test instance only.

| Check | Exit and actual result | Evidence |
| --- | --- | --- |
| Six bulk cases before the fix | 1; 6 failed, 18 assertions | [bulk-before.txt](insiders-catalog-validation-F1-evidence/bulk-before.txt) |
| Final CatalogValidationTest file | 0; 18 passed, 183 assertions | [catalog-after.txt](insiders-catalog-validation-F1-evidence/catalog-after.txt) |
| Mandatory coordinator F1 proof runner after the fix | 0; 1 passed, 4 assertions | [coordinator-proof-after.txt](insiders-catalog-validation-F1-evidence/coordinator-proof-after.txt) |
| Core-only catalog validation and API boundary files | 0; 7 passed, 237 assertions | [core-api.txt](insiders-catalog-validation-F1-evidence/core-api.txt) |
| Pint check of all eight changed PHP files | 0 | [style-check.txt](insiders-catalog-validation-F1-evidence/style-check.txt) |
| Whitespace check | 0 | [diff-check.txt](insiders-catalog-validation-F1-evidence/diff-check.txt) |

Actual one-off Docker prefix:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app
```

The focused commands append these suffixes to that prefix, in table order
except for the coordinator proof and whitespace check shown separately below.
`BOOTSTRAP` means
`../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php`.

```text
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Feature/Filament/CatalogValidationTest.php --filter='shared permission warning' --colors=never > docs/insiders-catalog-validation-F1-evidence/bulk-before.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Feature/Filament/CatalogValidationTest.php --colors=never > docs/insiders-catalog-validation-F1-evidence/catalog-after.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Unit/Registry/CatalogValidateCommandTest.php tests/Unit/ApiBoundaryTest.php --colors=never > docs/insiders-catalog-validation-F1-evidence/core-api.txt 2>&1
-d auto_prepend_file=BOOTSTRAP vendor/bin/pint --test packages/filament/src/Permissions/FilamentDiscovery.php tests/Feature/Filament/CatalogValidationTest.php tests/Stubs/Filament/BypassDeleteAnyCanResource.php tests/Stubs/Filament/BypassDeleteAnyResponseResource.php tests/Stubs/Filament/BypassRestoreAnyCanResource.php tests/Stubs/Filament/BypassRestoreAnyResponseResource.php tests/Stubs/Filament/BypassForceDeleteAnyCanResource.php tests/Stubs/Filament/BypassForceDeleteAnyResponseResource.php > docs/insiders-catalog-validation-F1-evidence/style-check.txt 2>&1
```

The final CatalogValidationTest invocation followed the final source edit.
The mandatory coordinator proof used its supplied runner without changing its
command or fixture:

```bash
python3 /home/vostrikov/sibintek/projects/insiders/Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-run-F1-proof.py > docs/insiders-catalog-validation-F1-evidence/coordinator-proof-after.txt 2>&1
git diff --check > docs/insiders-catalog-validation-F1-evidence/diff-check.txt 2>&1
```

## Limits and remaining delivery

V1–V5 are withheld coordinator-owned acceptance checks and were not run here.
No full suite, coverage, mutation, or new static-analysis result is claimed.
No SQLite/main database, service lifecycle command, parent worktree write,
vendor/composer edit, commit, push, or deployment was used.

This correction makes the validator report a replaced bulk hook; it does not
change the allowing behavior of an application override. Structural hook
inspection also remains conservative for overrides that delegate to a parent.

The consumer's mirrored vendor delivery and actual linked-panel acceptance remain
with the coordinator, following the original document's delivery procedure.
Passing owning-source tests and the isolated coordinator reproducer do not prove
that the consumer has received the correction.
