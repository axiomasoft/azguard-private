You are an independent, read-only code reviewer. You can read everything and run read-only commands and tests, but the kernel refuses writes to the repository; do not try to modify files. Inspect, verify, report, stop.

Repository: /home/vostrikov/projects/packages/azguard (PHP 8.3+, Laravel package, Pest tests).
Item under review: plan item P5.1 "Трейт HasAzGuard, SubjectAccess, SubjectPanels" of plan 2026.10.01-№1-AZGUARD-V1.
Change under review: the uncommitted working tree on top of HEAD 394ab99. The full diff (new files included, without the generated packages/core/api-manifest.json and plans/) is in /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/6b6f20fe-c19d-4e23-ab45-caaabea81d0d/scratchpad/review/change.diff; the files in the repository are the post-change state. Ignore files under plans/.

Item specification (scope, rules, guidance, validation): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/6b6f20fe-c19d-4e23-ab45-caaabea81d0d/scratchpad/review/item.json
Accepted decisions D18, D20 and the normative P5 change model (Protocol, Composite, Invariants, Acceptance map): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/6b6f20fe-c19d-4e23-ab45-caaabea81d0d/scratchpad/review/decisions.md
Normative design dossier, relevant parts:
- audits/2026-09-29-audit/opus/05-php-api.md §1 (trait, SubjectAccess, SubjectPanels) and §2 (fromOrigin, no hidden tenant aggregation)
- audits/2026-09-29-audit/opus/18-contexts-and-runtime-inputs.md §1 (guard selector vs Eloquent guard(array))
- audits/2026-09-29-audit/opus/09-authorization-semantics.md §1 (one panel rule) and §3 (tenant, context, resource)
- audits/2026-09-29-audit/opus/02-decisions.md D09, D10, D11, D57
- audits/2026-09-29-audit/opus/14-verification.md rows V12–V14, V16, V38, V39, V64, V70, V86, V87, V88, V103, V108; 17-crm-acceptance-tests.md rows R03, R04, R05

Main code (new): packages/core/src/Concerns/{HasAzGuard,SubjectAccess,SubjectPanels}.php, packages/core/src/Contracts/AzGuardSubject.php.
Main code (changed): packages/core/src/Panels/PanelResolver.php (select(), pattern(), panelsOf(), modelPanel(), role signals), packages/core/src/Authorization/Authorizer.php (roles(), permissionSet(), qualified()), packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php (qualify() split into walk() + qualifiedContributions()), packages/core/src/Authorization/Pipeline/Stages/BoundaryStage.php (resourceScope() public), packages/core/src/Sources/Database/{DatabaseSource,GrantInspection,GrantRows}.php (grantModels reader).
Tests: tests/Feature/Concerns/*, tests/Feature/Panels/{PanelSelectionMatrixTest,RoleSignalsTest}.php, tests/Acceptance/Crm/SubjectApiTest.php, tests/Regression/{P11,P13,P14}Test.php; fixtures tests/Fixtures/Concerns/*, tests/Fixtures/Crm/Models/User.php.
Useful context: packages/core/src/Changes/ChangePipeline.php (grant/revoke/sync/run/role/permission), packages/core/src/Scopes/CurrentContext.php, packages/core/src/Laravel/Gate/GateBridge.php, packages/core/src/Authorization/Pipeline/Stages/PrepareStage.php, tests/Fixtures/Crm/CrmWorld.php, tests/Arch/SourceConventionsTest.php (panel selection rule).

Review focus, highest first:
1. Security / panel selection (hard risk): does every trait, SubjectAccess and SubjectPanels entry pick its panel only through PanelResolver, with no second selection rule? Can any call evaluate or write a name, enum or role of another panel in the selected panel, or switch panel instead of raising ConflictingPanelException/AmbiguousPanelException? Is the qualify() refactor in AuthorityStage behaviour-preserving for decide()/isSuperAdmin() (trace order, denials, superadmin)?
2. Tenant boundary (hard risk): can permissionSet()/roleNames()/isSuperAdmin()/roleGrants()/changes aggregate or leak across tenants, or use a tenant the caller did not select? Is TenantRequiredException raised for a tenant panel without a tenant? Does a resource of another tenant ever widen a scope?
3. on: mapping: context model vs resource for checks, role lists and changes (writes refuse a resource), AnyAssignmentScope only for revoke/lists; parity of SubjectAccess::decide with Authorizer::decide for the same request.
4. permissionSet semantics (D18 §6): Grants keys only, PolicyOnly never, super admin = all Grants keys of the tenant catalog incl. dynamic overlay, validUntil = earliest relevant expiry; not a substitute for decide().
5. guard(array|string $guarded): native Eloquent behaviour for arrays (same model, named argument, mergeGuarded, fill), immutable SubjectAccess for strings, Auth/current panel/model untouched; model with own guard() (V108/R03).
6. Immutability (V87/R04/V38/V39/V103): final readonly wrappers, inTenant/fromOrigin return new objects, no captured ambient state; fromOrigin narrows only changes and stored-grant lists, not authorization (D20).
7. Test adequacy: do the tests prove V70, V64 rows, V87, V108, V12–V14/V16/V86/V88, R03–R05 and the D20 host-transaction claim with positive and negative controls; vacuous or tautological assertions.

Already known and logged by the executor (report only if you find them wrong or worse than stated):
- Lists, permission sets, isSuperAdmin, stored-grant lists and changes take the wrapper tenant, else the CurrentContext tenant of the panel (withinScope), else global for a panel without tenants, else TenantRequiredException; checks pass only the wrapper tenant and let the engine resolve the rest.
- A context model in a check is also passed as the resource only when the panel can place it (resourceScopes class, ProvidesAccessScope, or a panel without tenants); otherwise only the context ref is passed.
- Empty lists for has*Any/has*All are false; hasRole of an unknown role key is UnknownRoleException; a role class registered in no panel is UnknownRoleException; an enum role names no panel.
- permissionSet is exact Grants keys in byte order; a source error or changing read gives an empty set (as isSuperAdmin gives false).
- The AzGuardSubject contract omits guard(); SubjectPanels::permissions() skips tenant panels; roles() throws for a tenant panel without a current tenant.
- The V64 trait row observes the trait's private selector through Closure::bind; the SubjectAccess row observes Decision::$state->panel.
- tests/Fixtures/Crm/Models/User.php and phpstan.neon are changed outside the item's task files (logged).

You may run targeted tests, for example: php -d memory_limit=1G vendor/bin/pest tests/Feature/Concerns tests/Acceptance/Crm/SubjectApiTest.php --compact (SQLite in memory). The executor's checks were green: targeted Pest (1031), regression (110), arch (79), full composer test (3736 passed, 1 pre-existing skip), Pint, PHPStan, type coverage 99.5%, api-manifest --check, git diff --check.
Severity follows demonstrated impact only. Every finding needs file:line evidence and a reproduction: a failing test or command output you ran, or, if you could not run it, an exact input/state -> wrong output trace. Do not report style nits or speculative "could be cleaner" items.

Final answer: ONLY a JSON object, no prose around it:
{"verdict": "GREEN" | "CHANGES_REQUESTED",
 "findings": [{"id": "F1", "severity": "critical|major|minor", "file": "path", "line": 123,
   "summary": "one sentence", "failure_scenario": "concrete input/state -> wrong result", "reproduction": "test/command and its output, or trace", "suggested_fix": "short"}],
 "checked": ["short list of what you verified"]}
