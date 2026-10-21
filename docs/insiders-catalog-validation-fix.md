# Filament catalog validation diagnostic repair

This is an Unreleased bug fix in the owning AzGuard packages for Insiders phase02a V5. It changes the validator's evidence for an authorization binding. Catalog generation, permission keys, grants, native authorization and the required PermissionDefinition interface are unchanged. No release, consumer delivery, commit, push or deployment was performed.

## Diagnostic contract

Previously every catalog key without a discovered `#[GateAbility]` method produced a warning, including legitimate Filament permissions enforced at runtime. Strict validation therefore failed for a linked resource and guarded page/widget.

Core now collects exact keys from tagged internal RuntimePermissionBindings contributors in addition to the existing policy map. The optional Filament provider supplies one contributor. Core imports no Filament types; with no contributor the existing policy/enum checks apply unchanged. The tag and interface are internal integration details. The committed core API snapshot passes without regeneration.

The Filament contributor checks the configured AzGuard panel, current enforcement flags (`enforce=true`, `source != policy`), and live registration in a Filament panel linked by AzGuardPlugin:

- Resources must be discovered, non-excluded Resource subclasses, must not skip authorization, and must retain the native common authorization response, ability response and corresponding `can*` hooks. Only abilities mapped by FilamentActions qualify. The backing model/name must match the discovery map actually used by ResourceGate, including its last-wins rule.
- Pages/widgets must be registered, non-excluded Page/Widget subclasses whose effective `canAccess()`/`canView()` implementation comes from HasAzGuardPage/HasAzGuardWidget. Method source reflection recognizes inherited trait hooks and rejects a class override even if the class still uses the trait.
- Keys use the same PermissionSchema as the catalog and enforcement, including custom key template, case and page/widget abilities.

Definitions remain catalogued when enforcement is disabled or absent. Their warnings remain visible and fail strict mode unless an actual policy binding independently covers them. Plain pages, replaced hooks, stale orphans, forged simple/dynamic definitions, unsupported abilities and absent model bindings receive no blanket exemption. A real policy key missing from the catalog remains an error even without strict mode. Existing warning text, JSON fields and exits for genuine issues are preserved.

This is structural evidence for the package's known hooks, not execution of arbitrary consumer authorization code. An override that delegates to a parent may conservatively retain a warning; the validator does not prove arbitrary PHP equivalence. It does not authenticate a user or guarantee a grant. ResourceGate remains union-only. Filament's own strict authorization setting remains independent of this command's `--strict`; the existing AzGuardPlugin HTTP boot still configures resource policy-existence handling.

## Inspected runtime

Reflection was checked before tests. CatalogValidateCommand and FilamentDiscovery loaded from `/var/www/html/local-packages/azguard/packages/`, rather than stale package mirrors. Installed Filament Resource, Page and Widget loaded from the separate development vendor volume. See [reflection.txt](insiders-catalog-validation-evidence/reflection.txt).

The installed Resource `getAuthorizationResponse()` checks `shouldSkipAuthorization()` and otherwise calls Filament's authorization helper with the model and policy-existence flag. Page's default `canAccess()` and Widget's default `canView()` allow access, so discovering names alone is insufficient. Page mount calls `canAccess()` and aborts 403. The new regression exercises native resource/page/widget entry points with denied and granted users.

## Guarded verification

All PHP commands used the supplied one-off Docker prefix from `../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-command.txt`:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app
```

Each recorded test command below appends its suffix to that exact prefix. The `BOOTSTRAP` placeholder means `../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php`.

The guarded bootstrap validates the required environment, sets PHP server/environment values before autoload, connects only to `insiders_qnn_azguard_pgsql:5432/insiders_test`, and verifies current_database(). The container was inspected as running on insiders_insiders_network before tests. RefreshDatabase managed only that owned instance. No SQLite, parent production/shared test instance, service lifecycle, parent reset, dependency installation, composer manifest/lock edit or vendor edit was used. The supplied parent mount was used only for package-scoped writes and reading the named bootstrap.

| Run | Exit | Evidence |
| --- | --- | --- |
| Before fix: focused positive regression | 1: valid five-key catalog, strict exit 1 | [baseline.txt](insiders-catalog-validation-evidence/baseline.txt) |
| New Filament regression | 0: 12 tests, 48 assertions | [filament-regression.txt](insiders-catalog-validation-evidence/filament-regression.txt) |
| Core regression and API freeze | 0: 7 tests, 237 assertions | [core-api.txt](insiders-catalog-validation-evidence/core-api.txt) |
| Eight existing affected files | 0: 45 tests, 73 assertions | [existing-focused.txt](insiders-catalog-validation-evidence/existing-focused.txt) |

Actual test suffixes, in the table's order:

```text
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Feature/Filament/CatalogValidationTest.php --filter='strictly validates' --colors=never > docs/insiders-catalog-validation-evidence/baseline.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Feature/Filament/CatalogValidationTest.php --colors=never > docs/insiders-catalog-validation-evidence/filament-regression.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Unit/Registry/CatalogValidateCommandTest.php tests/Unit/ApiBoundaryTest.php --colors=never > docs/insiders-catalog-validation-evidence/core-api.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap BOOTSTRAP tests/Feature/Filament/ResourceEnforcementTest.php tests/Feature/Filament/PageWidgetEnforcementTest.php tests/Unit/Filament/AzGuardFilamentServiceProviderTest.php tests/Unit/Filament/FilamentPermissionCatalogBuilderTest.php tests/Unit/Filament/ResourceGateTest.php tests/Unit/Registry/CompositePermissionCatalogTest.php tests/Unit/Registry/EnumPermissionCatalogBuilderTest.php tests/Unit/Registry/PolicyAbilityCatalogBuilderTest.php --colors=never > docs/insiders-catalog-validation-evidence/existing-focused.txt 2>&1
```

An intermediate executor mistake started the core run before the preceding Filament run completed. Their RefreshDatabase operations collided on the owned instance, producing missing/duplicate table errors (both exited 2). Those logs are retained as [collision-filament.txt](insiders-catalog-validation-evidence/collision-filament.txt) and [collision-core-api.txt](insiders-catalog-validation-evidence/collision-core-api.txt). Strictly sequential reruns passed as listed above. These are environment collisions, not baseline product failures.

The only pre-fix product baseline was the new focused positive regression; no full-suite baseline was run. V1, V2, V4 and the complete Feature, Unit/Filament and Unit/Registry suites remain the conductor's checks and were deliberately not run by this executor. The reported red V3 was reproduced and corrected in the correction round below. Focused green tests do not claim every unrelated PostgreSQL suite or consumer integration is green.


Additional focused checks completed after the runs above:

- Plugin/Filament architecture: exit 0, 30 tests and 43 assertions; [plugin-boundary.txt](insiders-catalog-validation-evidence/plugin-boundary.txt).
- Pint formatted two style issues in the 11 changed PHP files, then its check-only run exited 0; [style-fix.txt](insiders-catalog-validation-evidence/style-fix.txt), [style-check.txt](insiders-catalog-validation-evidence/style-check.txt).
- `git diff --check` exited 0; [diff-check.txt](insiders-catalog-validation-evidence/diff-check.txt) is empty on success.
- Initial focused test total: 94 passed, 401 assertions across the four passing test invocations. No full-suite, coverage or mutation result is claimed. The subsequent V3 static-analysis result is recorded below.

Exact additional commands:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app -d memory_limit=1G vendor/bin/pest --bootstrap ../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php tests/Unit/Filament/AzGuardPluginTest.php tests/Unit/Filament/FilamentArchTest.php --colors=never > docs/insiders-catalog-validation-evidence/plugin-boundary.txt 2>&1
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app -d auto_prepend_file=../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php vendor/bin/pint packages/core/src/Commands/CatalogValidateCommand.php packages/core/src/Registry/Support/RuntimePermissionBindings.php packages/filament/src/AzGuardFilamentServiceProvider.php packages/filament/src/Permissions/FilamentDiscovery.php packages/filament/src/Permissions/FilamentRuntimePermissionBindings.php tests/Feature/Filament/CatalogValidationTest.php tests/Unit/Registry/CatalogValidateCommandTest.php tests/Stubs/Filament/CatalogProjectResource.php tests/Stubs/Filament/BypassCatalogProjectResource.php tests/Stubs/Filament/OverriddenSettingsPage.php tests/Stubs/Filament/OverriddenRevenueWidget.php > docs/insiders-catalog-validation-evidence/style-fix.txt 2>&1
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app -d auto_prepend_file=../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php vendor/bin/pint --test packages/core/src/Commands/CatalogValidateCommand.php packages/core/src/Registry/Support/RuntimePermissionBindings.php packages/filament/src/AzGuardFilamentServiceProvider.php packages/filament/src/Permissions/FilamentDiscovery.php packages/filament/src/Permissions/FilamentRuntimePermissionBindings.php tests/Feature/Filament/CatalogValidationTest.php tests/Unit/Registry/CatalogValidateCommandTest.php tests/Stubs/Filament/CatalogProjectResource.php tests/Stubs/Filament/BypassCatalogProjectResource.php tests/Stubs/Filament/OverriddenSettingsPage.php tests/Stubs/Filament/OverriddenRevenueWidget.php > docs/insiders-catalog-validation-evidence/style-check.txt 2>&1
git diff --check > docs/insiders-catalog-validation-evidence/diff-check.txt 2>&1
```

The final reflection evidence was captured with this guarded command:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app -r 'require '\''../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php'\''; echo '\''DB '\''.getenv('\''DB_HOST'\'').'\''/'\''.getenv('\''DB_DATABASE'\'').PHP_EOL; foreach ([AzGuard\Commands\CatalogValidateCommand::class, AzGuard\Filament\Permissions\FilamentDiscovery::class, AzGuard\Filament\Permissions\FilamentRuntimePermissionBindings::class, Filament\Resources\Resource::class, Filament\Pages\Page::class, Filament\Widgets\Widget::class] as $class) { $r=new ReflectionClass($class); echo $class.'\'' '\''.$r->getFileName().PHP_EOL; } foreach ([[Filament\Resources\Resource::class,'\''getAuthorizationResponse'\''],[Filament\Resources\Resource::class,'\''canViewAny'\''],[Filament\Pages\Page::class,'\''canAccess'\''],[Filament\Pages\Page::class,'\''mountCanAuthorizeAccess'\''],[Filament\Widgets\Widget::class,'\''canView'\'']] as [$class,$method]) { $r=new ReflectionMethod($class,$method); echo "$class::$method ".$r->getFileName().'\'':'\''.$r->getStartLine().PHP_EOL; echo implode('\'''\'',array_slice(file($r->getFileName()),$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1)); }' > docs/insiders-catalog-validation-evidence/reflection.txt 2>&1
```

## V3 correction round

The conductor reported one V3 error in `LoadAzGuardRoles`: Larastan inferred the framework's base authenticated User, which has no `roles()` relation. The middleware already checks `method_exists($user, 'roles')` on the concrete user before loading that optional relation. Re-running the exact supplied V3 command reproduced the single `larastan.relationExistence` diagnostic (exit 1).

The correction adds an identifier-specific inline PHPStan ignore at that guarded call, with its reason. It changes no executable code, authentication behavior, catalog checks, public interfaces, dependencies, or analyzer configuration/baseline. Other relation diagnostics remain enabled. The repeated V3 command exits 0 with no errors.

Before the focused tests, the guarded bootstrap connected to `insiders_qnn_azguard_pgsql/insiders_test`, and reflection confirmed that LoadAzGuardRoles, CatalogValidateCommand and FilamentRuntimePermissionBindings loaded from the owning `packages/` sources. Installed Filament Resource/Page/Widget loaded from the development vendor volume. See [correction-reflection.txt](insiders-catalog-validation-evidence/correction-reflection.txt). The owned PostgreSQL container was inspected as running on the supplied network. No service lifecycle or vendor change was performed.

| Correction check | Exit | Evidence |
| --- | --- | --- |
| Exact V3 reproduction before correction | 1: one relation diagnostic | [correction-v3-before.txt](insiders-catalog-validation-evidence/correction-v3-before.txt) |
| Exact V3 rerun after correction | 0: no errors | [correction-v3-after.txt](insiders-catalog-validation-evidence/correction-v3-after.txt) |
| Existing middleware tests | 0: 2 tests, 4 assertions | [correction-middleware.txt](insiders-catalog-validation-evidence/correction-middleware.txt) |
| Pint check of corrected PHP file | 0 | [correction-style.txt](insiders-catalog-validation-evidence/correction-style.txt) |

Actual command suffixes appended to the exact Docker prefix above (in table order):

```text
vendor/bin/phpstan analyse --memory-limit=1G --no-progress > docs/insiders-catalog-validation-evidence/correction-v3-before.txt 2>&1
vendor/bin/phpstan analyse --memory-limit=1G --no-progress > docs/insiders-catalog-validation-evidence/correction-v3-after.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap ../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php tests/Feature/LoadAzGuardRolesMiddlewareTest.php --colors=never > docs/insiders-catalog-validation-evidence/correction-middleware.txt 2>&1
-d auto_prepend_file=../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php vendor/bin/pint --test packages/core/src/Http/Middleware/LoadAzGuardRoles.php > docs/insiders-catalog-validation-evidence/correction-style.txt 2>&1
```

The correction's `git diff --check` output is recorded separately in [correction-diff-check.txt](insiders-catalog-validation-evidence/correction-diff-check.txt); the file is empty on success. Tests and checks ran sequentially. Only V3 was rerun among the acceptance checks, as explicitly permitted for a reported failure; the conductor still owns the final acceptance run and consumer delivery.

## Consumer mirrored-vendor delivery

The repair belongs in packages/core and packages/filament together. The consumer's mirrored vendor copy is a separate delivery artifact and has not been updated here. The coordinator must:

1. Refresh the consumer's installed core and Filament packages through its established local path-install/mirroring process, including the new internal types. Avoid a partial Filament-only copy: its contributor uses the new internal core interface.
2. Verify reflection in the consumer runtime for CatalogValidateCommand, RuntimePermissionBindings, FilamentDiscovery and FilamentRuntimePermissionBindings. If reflection points to vendor mirrors, verify those files match the owning sources. An unchanged package version does not prove delivery. Refresh long-lived processes and cached container/autoload state through the coordinator-owned workflow.
3. Run the existing phase02a V5 strict catalog check against the actual linked admin panel and inspect JSON warnings/errors. Preserve genuine orphan diagnostics; do not add fake policies or change permissions. Recheck real resource/page/widget access with the existing consumer authorization acceptance.
4. Record source-test and consumer-delivery results separately before the coordinator commits. This repair supplies no release or deployment claim.

