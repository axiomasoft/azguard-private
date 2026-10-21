# Built-in DoctorPage permission binding

The actual consumer preview after the diagnostic repair `fd6e606` and F1
`dbd66ee9` reported five unbound native subjects. The read-only input proof is
`../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-dev-preview-admin.json`
(relative to the package root). It contains no errors and warnings for
`admin.dashboard.view`, `admin.doctor_page.view`,
`admin.health_check_results.view`, `admin.account_widget.view` and
`admin.filament_info_widget.view`. That preview precedes this correction.

The owning final `AzGuard\Filament\Pages\DoctorPage` inherited Filament's
allowing `canAccess()` default despite advertising `admin.doctor_page.view`.
It now uses the existing `HasAzGuardPage` trait, which delegates to the existing
`PageWidgetAccessEvaluator`. There is no new permission, policy, page, catalog
exclusion or authorization mechanism. Its name, route, view, diagnostics and
request-scoped memoization are unchanged.

## Regression and compatibility

`CatalogValidationTest` reuses its native registered Filament panel and fully
rebuilds discovery, schema and catalog with DoctorPage included. The helper's
default exclusion remains unchanged for the old cases. The new cases rely on
the plugin's automatic registration of the actual built-in page.

The tests establish that the catalog contains exactly
`admin.doctor_page.view`, that an authenticated ungranted user is denied by
`DoctorPage::canAccess()` and Filament's mount hook, and that exact, `admin.**`
and `**` grants allow the same native entry points. Strict validation passes
with no warnings or errors and retains the exact catalog key. The diagnostic
payload, including errors, warnings and abilities, survives unchanged. The
existing DoctorPageMemoizationTest also passes, covering nonempty diagnostic
data, badge behavior and request-scoped recomputation.

This fixes access in enforced AzGuard-linked panels for users supporting
`hasPermission()`. Applications that previously relied on the page's open
default must grant its existing permission to the intended users. Existing
AdminRole `admin.**` and SuperAdmin `**` grants continue through the same
permission resolver; the package regressions prove those grant patterns with
test roles, not the consumer's concrete role classes. The evaluator still
resolves configured schemas and page abilities. Its allowing fallbacks remain:
no current/linked panel, disabled enforcement, policy source, missing user or
a user without `hasPermission()`. Focused native tests cover no-panel,
disabled-enforcement and policy-source behavior. Policy-source consumers retain
responsibility for page authorization.

## Actual focused verification

The existing owned `insiders_qnn_azguard_pgsql` container was running on
`insiders_insiders_network`; `pg_isready` exited 0 and the connected database
was `insiders_test` as `azguard_test`. The external bootstrap checks the exact
environment and connects to that database before autoloading the package.
Reflection confirmed owning-source DoctorPage and FilamentDiscovery, with
Filament Page from the ready development vendor volume. See
[preflight.txt](insiders-doctor-binding-evidence/preflight.txt).

Only the two targeted test files ran. RefreshDatabase used the guarded owned
PostgreSQL database sequentially. The parent repository was mounted read-only,
with a writable mount only for this owning package and the supplied dev vendor
volume. No dependency installation, vendor source edit or parent source write
was performed.

| Check | Actual result | Evidence |
| --- | --- | --- |
| Six new DoctorPage cases before the trait fix | Exit 1; 6 failed, 27 assertions; native canAccess incorrectly allowed | [doctor-before.txt](insiders-doctor-binding-evidence/doctor-before.txt) |
| CatalogValidationTest and DoctorPageMemoizationTest after the fix | Exit 0; 28 passed, 247 assertions | [focused-after.txt](insiders-doctor-binding-evidence/focused-after.txt) |
| Pint on both changed PHP files | Exit 0 | [style-check.txt](insiders-doctor-binding-evidence/style-check.txt) |
| Whitespace check | Exit 0; empty output | [diff-check.txt](insiders-doctor-binding-evidence/diff-check.txt) |

The actual one-off Docker command prefix was:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html:ro -v /home/vostrikov/sibintek/projects/insiders/local-packages/azguard:/var/www/html/local-packages/azguard -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app
```

Append these actual suffixes (before-fix, after-fix and style respectively):

```text
-d memory_limit=1G vendor/bin/pest --bootstrap ../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php tests/Feature/Filament/CatalogValidationTest.php --filter='built-in DoctorPage' --colors=never > docs/insiders-doctor-binding-evidence/doctor-before.txt 2>&1
-d memory_limit=1G vendor/bin/pest --bootstrap ../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php tests/Feature/Filament/CatalogValidationTest.php tests/Feature/Filament/DoctorPageMemoizationTest.php --colors=never > docs/insiders-doctor-binding-evidence/focused-after.txt 2>&1
-d auto_prepend_file=../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php vendor/bin/pint --test packages/filament/src/Pages/DoctorPage.php tests/Feature/Filament/CatalogValidationTest.php > docs/insiders-doctor-binding-evidence/style-check.txt 2>&1
```

Preflight used these read-only commands, followed by the Docker prefix with
the PHP suffix below; their combined output is in preflight.txt:

```bash
docker inspect --format '{{.Name}} {{.State.Status}} {{json .NetworkSettings.Networks}}' insiders_qnn_azguard_pgsql
docker exec insiders_qnn_azguard_pgsql pg_isready -U azguard_test -d insiders_test
docker exec insiders_qnn_azguard_pgsql psql -U azguard_test -d insiders_test -Atqc 'SELECT current_database(), current_user'
```

```text
-r 'require "../../Tasks/2026-10-20-insider-questionnaires/artifacts/execution/azguard-test-bootstrap.php"; echo "guarded ".getenv("PGSQL_HOST")."/".getenv("PGSQL_DATABASE").PHP_EOL; foreach ([AzGuard\Filament\Pages\DoctorPage::class, AzGuard\Filament\Permissions\FilamentDiscovery::class, Filament\Pages\Page::class] as $class) {echo $class." ".(new ReflectionClass($class))->getFileName().PHP_EOL;}'
```

The final repository whitespace command was:

```bash
git diff --check > docs/insiders-doctor-binding-evidence/diff-check.txt 2>&1
```

## V3 correction

The coordinator's V3 reported an unmatched `trait.unused` ignore for
`HasAzGuardPage`: the built-in DoctorPage now uses this trait. The authorized
correction removes only that obsolete entry and its comment from
`phpstan-baseline.neon`. Every other baseline entry and analysis option stays
unchanged, including unmatched-ignore reporting.

The same V3 command was rerun after the correction and exited 0 with
`[OK] No errors`. Its output is in
[phpstan-correction.txt](insiders-doctor-binding-evidence/phpstan-correction.txt).
The actual command, including output capture, was:

```bash
docker run --rm --network insiders_insiders_network --user 1000:33 --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=insiders_test -e DB_HOST=insiders_qnn_azguard_pgsql -e PGSQL_HOST=insiders_qnn_azguard_pgsql -e PGSQL_PORT=5432 -e PGSQL_DATABASE=insiders_test -e PGSQL_USERNAME=azguard_test -e PGSQL_PASSWORD=azguard_test -e CACHE_STORE=array -e SESSION_DRIVER=array -v /home/vostrikov/sibintek/projects/insiders:/var/www/html -v insiders_questionnaires_azguard_dev_vendor:/var/www/html/local-packages/azguard/vendor -w /var/www/html/local-packages/azguard insiders-app vendor/bin/phpstan analyse --memory-limit=1G --no-progress > docs/insiders-doctor-binding-evidence/phpstan-correction.txt 2>&1
```

This correction changed the baseline and verification documentation only;
the focused PHP test results above are retained from the preceding round.
No DB tests or full test suite were run in this correction round.

## Coordinator-owned acceptance and consumer delivery

V1, V2 and V4 commands remain withheld and were not run here. The coordinator
runs acceptance and delivers this
owning-source repair to the consumer's Composer dev mirrors, then verifies
the effective DoctorPage hook and strict diagnostics in the real admin panel.
The original preview JSON and package tests do not establish that delivery or
the final consumer strict PASS.

The other four consumer native defaults belong to parent02a and still require
its existing-trait bindings. This correction cannot make that consumer catalog
strictly pass by itself. Main02a remains stopped; no lifecycle management,
plan mutation, parent edit, commit, push or publication was performed here.
