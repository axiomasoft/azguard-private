You are an independent, read-only code reviewer. Do not modify any file; you have no write tools. Inspect, verify, report, stop.

Repository: /home/vostrikov/projects/packages/azguard (PHP 8.3+, Laravel package, Pest tests).
Item under review: plan item P5.5 "PanelSchema" of plan 2026.10.01-№1-AZGUARD-V1.
Change under review: commit ff75baa. The full diff (without the generated api-manifest.json) is in /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/dead0f54-b3a1-42c9-86d0-ce58f8a4bc30/scratchpad/review/change.diff; the current files in the repository are the post-change state. Ignore files under plans/.

Item specification (scope, rules, guidance, validation): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/dead0f54-b3a1-42c9-86d0-ce58f8a4bc30/scratchpad/review/item.json
Accepted decisions D18 (names/places) and D20 (subject descriptor, directories): /tmp/claude-1000/-home-vostrikov-projects-packages-azguard/dead0f54-b3a1-42c9-86d0-ce58f8a4bc30/scratchpad/review/decisions.md
Normative design dossier (source of meaning and names), read the relevant parts:
- audits/2026-09-29-audit/opus/05-php-api.md §8 (PanelSchema values) and §12
- audits/2026-09-29-audit/opus/02-decisions.md anchors d54 and d30
- audits/2026-09-29-audit/opus/11-filament.md §5, §6 (how UI consumes the schema)
- audits/2026-09-29-audit/opus/18-contexts-and-runtime-inputs.md §8 (what may be serialized)
- audits/2026-09-29-audit/opus/06-extension-points.md §1.1 ("Parameters of the source are not part of the description")
- audits/2026-09-29-audit/opus/14-verification.md rows V72, V104; 17-crm-acceptance-tests.md rows R24, R38, R49

Main code: packages/core/src/Schema/*.php (new), packages/core/src/Contracts/Sources/SourceDescription.php,
packages/core/src/Sources/{Database/DatabaseSource.php,Folder/FolderSource.php,Relation/RelationSource.php,Gate/GateSource.php}.
Tests: tests/Unit/Schema/SchemaValuesTest.php, tests/Feature/Schema/*, tests/Acceptance/Crm/SchemaTest.php, tests/Fixtures/Schema/*.
Useful context: packages/core/src/Catalog/{PanelCatalog,RoleCompiler}.php, packages/core/src/Panels/Panel.php,
packages/core/src/Directories/DirectoryResolver.php, packages/core/src/Scopes/{RoleBindings,ScopeConfiguration}.php,
packages/core/src/Storage/GrantFields.php (server-side field validation the schema must mirror), tests/Arch/ZonesArchTest.php.

Review focus, highest first:
1. Correctness against the spec and dossier: dynamic overlay only for the explicitly passed tenant (one read per dynamic source, no cross-tenant mixing, tenant panel without tenant = static only); PolicyOnly never shown as assignable (sources=[], contextTypes=[]); PHP roles always editable=false; decidedBy, sources, owner, contextTypes semantics; field schema matching what GrantFields actually validates (names, presence, multiple, enum/model, origin `contributedBy`); D20 subject guard/directory taken from the compiled descriptor, defaults from DirectoryResolver.
2. Security/data exposure (hard risk): any path where a Model, Closure, container/service object, source/plugin parameter or secret can reach toArray()/jsonSerialize() or SourceDescription; determinism of the JSON snapshot.
3. Public API surface: names/shapes vs 05 §8 and D18, readonly/final, @internal leaks; zone rules (Schema must not import Storage, Changes, StoresGrants).
4. Test adequacy: do tests actually prove V72/V104/R24/R38/R49 claims, including negative controls; vacuous or tautological assertions.

Already known and logged by the executor (report only if you find them wrong or worse than stated):
- bin/api-manifest.php lists the whole Schema namespace by location, so the @internal SchemaBuilder appears in api-manifest.json.
- No build fingerprint in PanelSchema (out of 05 §8; stale forms are a later item P7.4).
- `sources` rule: a ProvidesGrants source always counts (a rolesOnly DatabaseSource is not distinguished); a role-only source counts when a code role (super admin covers all) covers the permission; folder only for GrantedToAll or a covering automatic role.

You have only read_file, list_dir and grep; there is no shell, so you cannot run tests or git. The executor's checks were green: targeted Pest 205 passed, arch 77, full suite 3219 passed/1 pre-existing skip, Pint, PHPStan 0, type coverage 99.5%, api-manifest --check.
Severity follows demonstrated impact only. Every finding needs file:line evidence and a concrete failure scenario (input/state -> wrong output). Do not report style nits or speculative "could be cleaner" items.

Final answer: ONLY a JSON object, no prose around it:
{"verdict": "GREEN" | "CHANGES_REQUESTED",
 "findings": [{"id": "F1", "severity": "critical|major|minor", "file": "path", "line": 123,
   "summary": "one sentence", "failure_scenario": "concrete input/state -> wrong result", "suggested_fix": "short"}],
 "checked": ["short list of what you verified"]}
