You are an independent, read-only code reviewer. You can read everything and run read-only commands and tests, but the kernel refuses writes to the repository; do not try to modify files. Inspect, verify, report, stop.

Repository: /home/vostrikov/projects/packages/azguard (PHP 8.3+, Laravel package, Pest tests).
Item under review: plan item P5.3 "RoleCatalog, scoped GrantManager, PermissionManager" of plan 2026.10.01-№1-AZGUARD-V1.
Change under review: the uncommitted working tree on top of HEAD efd618b. The full diff (new files included, without the generated packages/core/api-manifest.json) is in /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/aac9fa01-ee12-41cb-83af-a6ac6eea1b68/scratchpad/review/change.diff; the files in the repository are the post-change state. Ignore files under plans/.

Item specification (scope, rules, guidance, validation): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/aac9fa01-ee12-41cb-83af-a6ac6eea1b68/scratchpad/review/item.json
Accepted decisions D14, D18, D20 and the normative P5 change model (Validation, Writes, Composite, Invariants, Lock-pairs): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/aac9fa01-ee12-41cb-83af-a6ac6eea1b68/scratchpad/review/decisions.md
Normative design dossier, relevant parts:
- audits/2026-09-29-audit/opus/05-php-api.md §2 (managers) and §12 (GrantDetails/PermissionDetails)
- audits/2026-09-29-audit/opus/19-oop-and-permission-authority.md §5, §6 (definitions, FormerKeys, removed keys)
- audits/2026-09-29-audit/opus/20-process-map.md rows F11–F17
- audits/2026-09-29-audit/opus/14-verification.md rows V10, V71, V82, V89, V91, V92, V113; 17-crm-acceptance-tests.md rows R15, R25, R29, R37, R42, R65, R66

Main code (new): packages/core/src/Contracts/Roles/RoleCatalog.php, packages/core/src/Contracts/Changes/{GrantManager,PermissionManager}.php,
packages/core/src/Catalog/CodeRoleCatalog.php, packages/core/src/Changes/{GrantFilter,GrantPage,PanelManagers,ScopedGrantManager,ScopedPermissionManager,RoleKeyMigration}.php,
packages/core/src/Sources/Database/{GrantInspection,GrantRows}.php.
Main code (changed): packages/core/src/Changes/{Change,ChangeType,ChangeValidator,ChangePipeline,ChangeEventPublisher}.php,
packages/core/src/Sources/Database/{DatabaseSource,GrantWriter,LockedReads}.php, packages/core/src/Events/RoleGrantUpdated.php, packages/core/src/Schema/SchemaBuilder.php (roles()).
Tests: tests/Feature/Changes/Managers/*, tests/Acceptance/Crm/ManagersTest.php, tests/Engines/ManagerRaceTest.php (+ tests/Engines/Support/change-worker.php ops), tests/Arch/ZonesArchTest.php, tests/Feature/Events/CatalogTest.php.
Useful context: packages/core/src/Storage/{Storage,StorageMutation,StorageReadSession,GrantFields}.php, packages/core/src/Catalog/{PanelCatalog,RoleCompiler}.php, packages/core/src/Authorization (how Access qualifies stored grants), tests/Fixtures/Changes/ChangeWorld.php, tests/Fixtures/Crm/*.

Review focus, highest first:
1. Security / partition (hard risk): can any GrantManager method read, change or delete a grant of another panel, tenant or origin (IDOR R37)? Does revokeMany refuse the whole operation before any write when one id is foreign? Can a page cursor be moved to another partition, filter or build, and does a forged cursor ever leave the partition? Is panel/tenant/origin fixed by PanelManagers::for only?
2. Correctness of the FormerKeys migration (D20 §6, change model Composite): only from locked stored rows, destination registered and grantable with from in formerKeys; historical id/scope/subject/origin/expiry/fields preserved; collision = existing to-row kept, expiry max with NULL winning, to-fields kept, source deleted; pipes may cancel but not change; no live F2/F5/F6/F7/F8; ordinary grant/update get no bypass; dry-run (planRoleKeyMigration / RoleKeyMigration::plan) without writes, version bump, pipes, journal or events; per-tenant mutations; events/audit carry old and new role keys (RoleGrantUpdated.previousRole, RoleRevoked of the source).
3. Orphan classification (GrantInspection): does Orphaned/Active/Expired match what Access actually qualifies (unknown/former/NotGrantable roles, unregistered or unbound context type, scope-required role without context, global-tenant roles, exact permission missing or PolicyOnly, pattern covering no Grants key, roles-only writer)? Any row that gives authority but is listed Orphaned, or gives none but is listed Active? SQL NULL pitfalls inside whereNot().
4. Concurrency (hard risk): update ∥ update with the same fingerprint, migration ∥ grant(from), revokeMany ∥ migration — is the panel lock taken first and is every decision made under it? Fingerprint equality between GrantRows on a read session and LockedReads under the lock.
5. Public API / zones: names vs D18 and 05 §2, @api/@internal tags, readonly/final, Contracts and Sources referencing only the exempt Changes value classes (AZGUARD_CHANGE_VALUES).
6. Test adequacy: do the tests prove V10/V71/V82/V89/V91/V92/V113 and R15/R25/R29/R37/R42/R65/R66 claims with positive and negative controls; vacuous or tautological assertions.

Already known and logged by the executor (report only if you find them wrong or worse than stated):
- GrantFilter.state values are string constants on GrantFilter (no new enum name outside the glossary); Orphaned takes precedence over Expired.
- A roles-only DatabaseSource now applies RevokePermission (only revocations) so permission rows stored before the switch can be cleaned up.
- The FormerKeys migration runs one mutation per tenant; a re-run continues an interrupted one. A NotGrantable destination is refused.
- Migration keeps the stored actor columns and meta untouched; only role and updated_at change on a move.
- GrantManager reads (page/find) use a primary read session fenced by equal state tokens, not the panel lock.

You may run targeted tests, for example: php -d memory_limit=1G vendor/bin/pest tests/Feature/Changes/Managers --compact (SQLite in memory). The executor's checks were green: targeted Pest, arch, engine races on PostgreSQL 16/MySQL 8/MariaDB 10.11, full composer test, Pint, PHPStan, type coverage, api-manifest --check, git diff --check.
Severity follows demonstrated impact only. Every finding needs file:line evidence and a reproduction: a failing test or command output you ran, or, if you could not run it, an exact input/state -> wrong output trace. Do not report style nits or speculative "could be cleaner" items.

Final answer: ONLY a JSON object, no prose around it:
{"verdict": "GREEN" | "CHANGES_REQUESTED",
 "findings": [{"id": "F1", "severity": "critical|major|minor", "file": "path", "line": 123,
   "summary": "one sentence", "failure_scenario": "concrete input/state -> wrong result", "reproduction": "test/command and its output, or trace", "suggested_fix": "short"}],
 "checked": ["short list of what you verified"]}
