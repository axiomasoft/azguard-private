"""Builds the knowledge documents type-map, target-structure, identities and upgrade-map from target-map.json and
identities.json. Usage: python3 gen_docs.py <dir-with-json> <out-dir>"""
import json
import os
import sys
from collections import defaultdict

src, out = sys.argv[1], sys.argv[2]
rows = json.load(open(os.path.join(src, 'target-map.json')))['rows']
ids = json.load(open(os.path.join(src, 'identities.json')))


def short(fq):
    return fq.replace('AzGuard\\', '', 1) if fq else '—'


def esc(s):
    return (s or '').replace('|', '\\|')


def doc(sections):
    return [{'id': i, 'title': t, 'text': x.strip()} for i, t, x in sections]


# ---------------------------------------------------------------- type-map
zones = defaultdict(list)
for r in rows:
    fq = r['current'] or r['target'].split(' + ')[0]
    parts = fq.split('\\')
    zone = parts[1] if parts[1] != 'Filament' else 'Filament'
    if r['current'] is None:
        zone = 'New'
    zones[zone].append(r)

order = ['(root)', 'Kernel', 'Contracts', 'Context', 'Tenancy', 'Panels', 'Catalog', 'Roles', 'Permissions', 'Policies',
         'Authorization', 'Changes', 'Storage', 'Sources', 'Scopes', 'Plugins', 'Directories', 'Schema', 'Events',
         'Exceptions', 'Diagnostics', 'Configuration', 'Laravel', 'Concerns', 'Attributes', 'Support', 'Facades', 'Testing',
         'Filament', 'New']
zones['(root)'] = zones.pop('AzGuardManager', []) + zones.pop('AzGuardServiceProvider', [])
tm = [('rules', 'How to read the type map', f"""
One row per production type of the 0.7.0 baseline ({sum(1 for r in rows if r['current'])} types: core and Filament) and
per type introduced by the transition ({sum(1 for r in rows if r['current'] is None)} new). Prefix `AzGuard\\` is
omitted. Columns:

- **target** — FQCN after the transition; `+` separates the parts of a split; `—` means retired.
- **owner** — `core`, `scopes`, `audit`, `devtools`, `filament`, `split` or `retired`.
- **marker** — `Api`, `Spi(Implement)`, `Spi(Call)` or `internal` (`#api-compatibility` of the architecture).
- **enum** — `closed`/`open` classification for enums.
- **action/phase** — `keep` (FQCN unchanged, marker added in P2), `move`, `relocate` (file moves, FQCN kept), `rename`,
  `split`, `close+move` (published at 0.7.0, now internal), `retire`, `new`; the phase that performs it.
- **was** — publication at 0.7.0: `api`/`spi` by tag, `(location)` when published only by directory, `internal`
  by tag, `unmarked`.

Rules that generate most rows: published types keep their FQCN; internal types move to `<Zone>\\Internal\\…`
(`Sources\\<Adapter>\\Internal`, Filament under `Filament\\Internal`, Filament resource pages stay by framework
convention); everything else is an explicit decision with its reason. The machine form of this table and its
generator are in `artifacts/inventory/` (`target-map.json`, `target_map.py`, `scan.py`, `types-0.7.0.json`).
Totals: {dict(sorted({k: sum(1 for r in rows if r['owner'] == k) for k in set(r['owner'] for r in rows)}.items()))}.
""")]
for z in order:
    rs = zones.get(z)
    if not rs:
        continue
    lines = ['| current | target | owner | marker | enum | action / phase | was | reason |', '|:--|:--|:--|:--|:--|:--|:--|:--|']
    for r in sorted(rs, key=lambda r: r['current'] or r['target']):
        lines.append('| ' + ' | '.join([
            '`' + esc(short(r['current'])) + '`' if r['current'] else '—',
            '`' + esc(' + '.join(short(x.strip()) for x in r['target'].split('+'))) + '`' if r['target'] else '—',
            r['owner'], r['marker'] or '—', r['enum'] or '', f"{r['action']} / {r['phase']}", r['current_marker'] or 'new',
            esc(r['reason'])]) + ' |')
    tm.append(('zone-' + z.strip('()').lower(), f'Zone {z}' if z != 'New' else 'Types introduced by the transition',
               f"{len(rs)} rows.\n\n" + '\n'.join(lines)))

# ---------------------------------------------------------------- target-structure
by_owner = defaultdict(list)
for r in rows:
    if r['owner'] == 'retired':
        continue
    targets = [x.strip() for x in r['target'].split('+')]
    for t in targets:
        owner = r['owner'] if r['owner'] != 'split' else ('scopes' if t.startswith('AzGuard\\Scopes\\') else 'core')
        marker = r['marker'] if len(targets) == 1 else 'internal'
        if len(targets) > 1 and any(n['target'] == t for n in rows if n['current'] is None):
            continue  # listed by its own "new" row
        by_owner[owner].append((t, marker, r['kind']))


def tree(owner, root):
    groups = defaultdict(list)
    for t, m, k in sorted(set(by_owner[owner])):
        ns, name = t.rsplit('\\', 1)
        groups[ns].append((name, m, k))
    lines = []
    for ns in sorted(groups):
        rel = ns[len(root):].replace('\\', '/') if ns.startswith(root.rstrip('\\')) else ns
        rel = rel.strip('/') or '.'
        items = ', '.join(f"{n}{'' if m == 'internal' else ' [' + m.replace('Spi(Implement)', 'SpiI').replace('Spi(Call)', 'SpiC') + ']'}"
                          for n, m, k in groups[ns])
        lines.append(f'{rel + "/":45} {items}')
    return '\n'.join(lines)


ts = [('rules', 'Structure rules', r"""
These rules are the reference for every move in P2/P3; a deviation is a plan amendment.

1. **Owners and roots.** `AzGuard\` → `packages/core/src` (core); `AzGuard\Scopes\` → `packages/core/modules/scopes/src`;
   `AzGuard\Audit\` → `packages/core/modules/audit/src`; `AzGuard\DevTools\` → `packages/core/modules/devtools/src`;
   `AzGuard\Filament\` → `packages/filament/src`. Composer resolves the longest prefix; `src/Scopes`, `src/Audit`,
   `src/DevTools` and `src/Plugins` must not exist (I5).
2. **Publication is the attribute, location follows it.** Every `#[Api]`/`#[Spi]` type lives outside `\Internal\`;
   every internal type lives under `<Zone>\Internal\` (adapters: `Sources\<Adapter>\Internal`; Filament:
   `Filament\Internal\<Area>`). The single exception: Filament resource pages stay at
   `Resources\<Resource>\Pages\…` by framework convention and carry `@internal`.
3. **Stable FQCNs.** Published types keep their 0.7.0 FQCN unless the type map lists a move. Kernel values, events,
   exceptions, enums and `Storage\Schema\StorageSchema` never move (serialized payloads, catch targets, published
   migrations). Exceptions stay in one `Exceptions\` namespace although CoreX/Chatom place them per domain: they are
   catch targets of installed applications.
4. **Contracts.** Extension and consumer interfaces live in `Contracts\<Domain>\`; module-owned contracts in
   `<Module>\Contracts\`. Value types crossing a contract live with their domain (`Changes\ChangeRecord`,
   `Context\AssignmentScopeRuntime`), not in a generic values namespace.
5. **Composition roots** are the only classes at a root: `AzGuard\AzGuard`, `AzGuardServiceProvider`,
   `Audit\AuditServiceProvider`, `DevTools\DevToolsServiceProvider`, `Filament\AzGuardFilamentServiceProvider`, and the
   module plugins `Scopes\ScopesPlugin`, `Audit\AuditPlugin`.
6. **Resources belong to their owner.** Config sections, migrations, stubs, translations, views and publish tags sit
   in the owner's tree (listed per package below and in `knowledge/identities`).
7. **Tests mirror owners** (`#tests`); a module's tests never import another module.
"""),
      ('core', 'Package axiomasoft/azguard: core tree', f"""
`packages/core/composer.json` (target):

```json
{{
  "name": "axiomasoft/azguard",
  "require": {{ "php": "^8.3", "illuminate/*": "^11.0|^12.0|^13.0 (unchanged list)" }},
  "autoload": {{ "psr-4": {{
      "AzGuard\\\\": "src/",
      "AzGuard\\\\Scopes\\\\": "modules/scopes/src/",
      "AzGuard\\\\Audit\\\\": "modules/audit/src/",
      "AzGuard\\\\DevTools\\\\": "modules/devtools/src/" }} }},
  "extra": {{ "laravel": {{ "providers": [
      "AzGuard\\\\AzGuardServiceProvider",
      "AzGuard\\\\Audit\\\\AuditServiceProvider",
      "AzGuard\\\\DevTools\\\\DevToolsServiceProvider" ] }} }}
}}
```

Files outside `src/`: `config/azguard.php` (one package file; `scaffold` section owned by DevTools),
`database/migrations/2026_10_01_000000_create_azguard_storage.php`,
`database/migrations/2026_10_09_000000_upgrade_azguard_storage_to_schema_2.php` (bodies unchanged, `StorageSchema`
now six tables), `stubs/storage-migration.stub`, `resources/lang/{{en,ru}}/{{http,roles}}.php` (namespace `azguard`),
`api-manifest.json` (core + modules, generated).

`AzGuardServiceProvider` registers: config merge and typed config, storages, catalog cache, panel registry,
scoped request services, `ConfigSections`, migrations (`loadMigrationsFrom` + `publishes` tag `azguard-migrations`),
config publish tag `azguard-config`, translations, configured panel providers, middleware aliases `azguard.panel` and
`azguard.can`, the `Gate::before` bridge when `gate.enabled`, core console commands (console only), the expired-grant
schedule, Laravel optimize/about/queue hooks, registry freeze after boot. It references no module class.

Directory → types (`[Api]`, `[SpiI]` = Spi Implement, `[SpiC]` = Spi Call; unmarked = internal):

```text
{tree('core', 'AzGuard')}
```
"""),
      ('scopes', 'Internal module AzGuard\\Scopes', f"""
Root `packages/core/modules/scopes/src`, no provider, no config section, no table, no command. Activation:
`->plugins([ScopesPlugin::inherit(Project::class)->requireMembership()])`. Depends on core contracts and values only.

```text
{tree('scopes', 'AzGuard\\Scopes')}
```

Module tests: `tests/Modules/Scopes/` (scope properties, eligibility, directories, visibility parity, module absent).
Contract suite `Scopes\\Testing\\AssignmentScopeFilterContractTests` for third-party filters.
"""),
      ('audit', 'Internal module AzGuard\\Audit', f"""
Root `packages/core/modules/audit/src`; resources `modules/audit/database/migrations/2026_10_20_000000_create_azguard_audit_log.php`
(published with plain `publishes()` under tag `azguard-audit-migrations`, not auto-loaded) and
`modules/audit/stubs/audit-migration.stub` (per-storage migrations). `AuditServiceProvider` registers the publish
tag and, in console, `azguard:audit:prune` and `azguard:audit:migration`; nothing else.

```text
{tree('audit', 'AzGuard\\Audit')}
```

Module tests: `tests/Modules/Audit/` (journal rows, rollback/retry/nested, event-id equality, adoption per driver,
prune concurrency, doctor shape check).
"""),
      ('devtools', 'Internal module AzGuard\\DevTools', f"""
Root `packages/core/modules/devtools/src`; stubs `modules/devtools/stubs/` (`abilities, change-pipe, models-migration,
panel-models, panel-provider, permission, plugin, policy-method, policy, restriction, role, source-grants,
source-permissions, source-policies, source-roles, source`), published by `azguard:stubs` to `stubs/azguard`.
`DevToolsServiceProvider` (console only) registers `azguard:install`, `azguard:stubs`, `azguard:make:*` and owns
the `scaffold` section (`namespace`, `path`). Generated code uses only `[Api]`/`[Spi]` symbols (I4); the plugin stub
implements `Plugin` directly and the pipe stub implements `ChangePipe`.

```text
{tree('devtools', 'AzGuard\\DevTools')}
```

Module tests: `tests/Modules/DevTools/` (generator output compiles against the supported surface, install flow).
"""),
      ('filament', 'Package axiomasoft/azguard-filament', f"""
`packages/filament/composer.json` keeps `axiomasoft/azguard: self.version` and `filament/filament: ^5.8.4`; PSR-4
`AzGuard\\Filament\\` → `src/`; provider `AzGuardFilamentServiceProvider`; config `config/azguard-filament.php`
(tag `azguard-filament-config`); views namespace `azguard-filament`; command `azguard:filament:generate`.
Filament uses no module class and no core internal: `PanelSchema::writer()` replaces `DatabaseSource::isDynamic()`,
`PanelAccess::scope()` replaces `CurrentContext::get()`, `GateBridge::toGateResult()` is a supported adapter method.

```text
{tree('filament', 'AzGuard\\Filament')}
```
"""),
      ('tests', 'Test suite ownership', r"""
| Current location | Target | Owner |
|:--|:--|:--|
| `tests/Arch` | `tests/Arch` (+ ownership/attribute/autoload scans) | core, covers all roots |
| `tests/Unit`, `tests/Feature/{Authorization,Catalog,Changes,Concerns,Configuration,Console,Diagnostics,Directories,Events,Facade,Gate,Http,Laravel,Panels,Permissions,Roles,Schema,Sources,Storage,…}` | unchanged paths; imports follow the type map | core |
| `tests/Feature/Scopes` (scope behavior part), `tests/Fixtures/Scopes`, behavior part of `Authorization/Properties/ScopePropertiesTest` | `tests/Modules/Scopes/` (the algebra part of the properties stays in core with a no-declaration variant) | scopes |
| `tests/Feature/Plugins/Audit`, `tests/Contracts/Plugins/AuditPluginContractTest.php`, `tests/Feature/Console/AuditPruneCommandTest.php` | `tests/Modules/Audit/` | audit |
| `tests/Feature/Console/Generators`, `tests/Feature/Console/InstallCommandTest.php` | `tests/Modules/DevTools/` | devtools |
| `tests/Feature/Filament`, `tests/Fixtures/Filament` | unchanged | filament |
| `tests/Engines`, `tests/Benchmarks`, `bench/` | unchanged; Audit concurrency added to engines | core + audit |
| `tests/Contracts/*` | core suites stay; module suites move with their module | per owner |
| `tests/Fixtures/Modules/{Blog,Shop}` | unchanged (host-application modules, not AzGuard modules) | core |

P1 records the exact file list per owner as part of its evidence; a test that imports two modules is split.
""")]

# ---------------------------------------------------------------- identities
def table(header, data):
    return '\n'.join(['| ' + ' | '.join(header) + ' |', '|' + ':--|' * len(header)] + ['| ' + ' | '.join(esc(str(c)) for c in d) + ' |' for d in data])


cmd_owner = {'azguard:audit:prune': 'audit', 'azguard:install': 'devtools', 'azguard:stubs': 'devtools'}
cmds = [(f"`{c['name']}`", cmd_owner.get(c['name'], 'devtools' if c['name'].startswith('azguard:make:') else 'core'),
         ' '.join(c['inputs']) or '—', 'unchanged') for c in ids['commands']]
cmds += [('`azguard:audit:migration`', 'audit', 'storage', 'new (P3)'), ('`azguard:filament:generate`', 'filament', '(see Filament command)', 'unchanged')]
exc = [(f"`{e['class']}`", e['parent'] or '—', f"`{e['code']}`" if e['code'] else ('abstract' if e['abstract'] else '—'),
        'retired (P2)' if e['class'] == 'PluginDependencyMissingException' else 'frozen') for e in ids['exceptions']]
checks = []
for c in ids['doctor_checks']:
    owner = 'audit' if c['class'] == 'AuditTableExists' else 'retired' if c['class'] == 'GateModeCheck' else 'core + scopes (split)' if c['class'] == 'MembershipConfigured' else 'core'
    checks.append((f"`{c['class']}`", f"`{c['key']}`", ', '.join(c['finding_ids']) or '(uses key)', owner))
idn = [('rules', 'Identity inventory rules', """
Every identity below is frozen at the candidate: changing one after 1.0 is a major-version decision (I17). "owner" is
the owner after P3. The inventory is taken from code at the baseline; P1 turns it into snapshot fixtures.
"""),
       ('commands', 'Console commands', table(['command', 'owner', 'arguments/options at 0.7.0', 'status'], cmds) +
        "\n\nExit codes (all commands, through `CommandSupport::attempt()`): `0` success, `2` (`INVALID`) input/panel/tenant/identity errors, `1` (`FAILURE`) other AzGuard and unexpected errors."),
       ('config', 'Configuration keys', table(['key', 'owner', 'status'], [
           ('`panels.providers`', 'core', 'frozen'), ('`defaults.models.{role_grant,permission_grant,permission}`', 'core', 'frozen'),
           ('`defaults.resource_prefix`', 'core', 'frozen'), ('`defaults.gate.mode`', 'core', 'retired (P2)'),
           ('`defaults.cache.{store,ttl,generation}`', 'core', 'frozen'), ('`defaults.consistency.{reads,state_refresh}`', 'core', 'frozen'),
           ('`defaults.trace_decisions`', 'core', 'frozen'), ('`defaults.tenants.resolvers`', 'core', 'frozen'),
           ('`defaults.scopes.resolvers`', 'core', 'frozen'), ('`gate.enabled`', 'core', 'frozen'),
           ('`decision_sets.max_subjects`', 'core', 'frozen'), ('`schedule.{enabled,prune_expired}`', 'core', 'frozen'),
           ('`catalog.{build_id,cache_path}`', 'core', 'frozen'), ('`storages.<name>.{connection,table_prefix,host_keys}`', 'core', 'frozen'),
           ('`ids.host_keys`', 'core', 'frozen'), ('`sources.<name>`', 'core', 'frozen'),
           ('`discovery.{permissions,policies,roles,scopes,abilities,queries,shared}`', 'core', 'frozen'),
           ('`scaffold.{namespace,path}`', 'devtools (registered section)', 'frozen'),
           ('`azguard-filament.*` (`abilities, authority, definitions, enforce, exclude, guard_panel, manages, pages, resources, widgets`)', 'filament', 'frozen')]) +
        "\n\nEnvironment names: `AZGUARD_DB_CONNECTION`, `AZGUARD_BUILD_ID` (frozen)."),
       ('resources', 'Publish tags, migrations, stubs, aliases, namespaces', table(['identity', 'value', 'owner', 'status'], [
           ('publish tag', '`azguard-config`', 'core', 'frozen'), ('publish tag', '`azguard-migrations`', 'core', 'frozen'),
           ('publish tag', '`azguard-audit-migrations`', 'audit', 'new (P3)'), ('publish tag', '`azguard-filament-config`', 'filament', 'frozen'),
           ('stub publish path', '`stubs/azguard`', 'devtools', 'frozen'),
           ('migration', '`2026_10_01_000000_create_azguard_storage`', 'core', 'basename frozen; six tables after P3'),
           ('migration', '`2026_10_09_000000_upgrade_azguard_storage_to_schema_2`', 'core', 'basename frozen'),
           ('migration', '`2026_10_20_000000_create_azguard_audit_log`', 'audit', 'new (P3), create-or-adopt'),
           ('generated migration', '`<date>_create_azguard_<storage>_storage.php`', 'core', 'frozen'),
           ('generated migration', '`<date>_create_azguard_<storage>_audit_log.php`', 'audit', 'new (P3)'),
           ('middleware alias', '`azguard.panel`', 'core', 'frozen'), ('middleware alias', '`azguard.can`', 'core', 'frozen'),
           ('container alias', '`azguard`', 'core', 'frozen'), ('translation namespace', '`azguard`', 'core', 'frozen'),
           ('view namespace', '`azguard-filament`', 'filament', 'frozen'), ('queue context key', '`azguard.panel` (hidden context)', 'core', 'frozen'),
           ('plugin id', '`azguard/audit`', 'audit', 'frozen'), ('plugin id', '`azguard/scopes`', 'scopes', 'new (P2)'),
           ('plugin id prefix', '`azguard/*` reserved', 'core', 'frozen'), ('system actor type', '`azguard:system`', 'core', 'frozen'),
           ('storage_state.schema', '`version 2, identity_codec, storage_id, prefix, host_keys`', 'core', 'frozen'),
           ('Laravel providers', '`AzGuard\\AzGuardServiceProvider`, `AzGuard\\Audit\\AuditServiceProvider`, `AzGuard\\DevTools\\DevToolsServiceProvider`, `AzGuard\\Filament\\AzGuardFilamentServiceProvider`', 'per package', 'frozen FQCNs')])),
       ('events', 'Event types', table(['enum case', 'type string', 'class', 'status'],
                                       [(e['case'], f"`{e['type']}`", e['case'] if e['case'] != 'PanelTouched' else 'PanelStateTouched', 'frozen; `EventType` is open') for e in ids['event_types']]) +
        "\n\nEnvelope and payload keys of `AccessEvent::toArray()` (`event_id, type, occurred_at, panel, tenant, actor, correlation_id, state, …data`) are frozen, including the `state` object keys (`knowledge/contracts#tokens`)."),
       ('exceptions', 'Exceptions and codes', table(['class', 'parent', 'code', 'status'], exc) +
        "\n\nClasses and codes are frozen; codes are `snake_case`; abstract parents stay catch targets."),
       ('doctor', 'Doctor checks and finding ids', table(['check class (0.7.0)', 'key', 'finding ids emitted', 'owner after P3'], checks) +
        "\n\nNew module checks: `Scopes\\Internal\\Checks\\ScopeMembershipConfigured` (key `scopes.membership`, finding id `membership.configured` kept), `Audit\\Internal\\Checks\\AuditTableExists` (key and finding id `audit.table`, adds shape findings). Doctor JSON field names and severities are frozen.")]

# ---------------------------------------------------------------- upgrade-map
up_rows = []
for r in rows:
    if not r['current']:
        continue
    was = r['current_marker'] or ''
    public = was.startswith(('api', 'spi'))
    if r['action'] in ('keep', 'relocate') and not (public and r['marker'] == 'internal'):
        continue
    if not public and r['action'] not in ('retire', 'rename'):
        continue
    up_rows.append((f"`{short(r['current'])}`", '—' if not r['target'] else '`' + ' + '.join(short(x.strip()) for x in r['target'].split('+')) + '`',
                    r['action'], r['phase'], r['reason']))
um = [('rules', 'How the upgrade map is used', """
One map from 0.7.0 to the candidate, written in P2 and completed in P3 (D4). It lists every change of a supported
symbol, member, DSL, configuration, command, event, schema behavior and default. Internal FQCN moves are not
listed (no promise). The guide in `UPGRADING.md` is generated from this section set in P4.
"""),
      ('types', 'Supported types that move, close or retire', table(['0.7.0', 'target', 'action', 'phase', 'reason'], up_rows)),
      ('members', 'Members and DSL', table(['0.7.0', 'target', 'phase'], [
          ('`AzGuardManager::check/authorize(mixed $subject, …)`', '`AzGuard\\AzGuard::check/authorize(Model|Authenticatable|SubjectRef $subject, …)`', 'P2'),
          ('`PanelBuilder::scopes(AssignmentScopePolicy)`', '`->plugins([ScopesPlugin::inherit|isolated|required(...)])` or `assignmentScopes(AssignmentScopeDeclaration)`', 'P2'),
          ('`PanelBuilder::gate(GateMode)`', 'removed (one behavior)', 'P2'),
          ('`PanelBuilder::before/after/changing` with duck-typed objects or name-injected closures', 'named contracts or closures matching the contract method', 'P2'),
          ('`Plugin::boot()`, `BasePlugin`', 'removed; implement `Plugin` (an existing `boot()` is never called)', 'P2'),
          ('`DependsOnPlugins::requires()`, `PluginContext::dependencies()`', 'removed; requirements implied by slots', 'P2'),
          ('`ChangeJournal::append()` from a pipe', '`ChangeParticipant` + `OwnedRowsWriter`', 'P2'),
          ('`AuditPlugin::retentionIn(PanelRecipe)`', '`$panel->options(AuditOptions::class)?->retentionDays`', 'P2'),
          ('`Panel::writer(): ?StoresGrants`', '`Panel::writer(): ?WriterSchema`; `PanelSchema::writer()`', 'P2'),
          ('`Panel::{changing,before,after,restrictions,grantConditions,doctorChecks,attachedSources,scopes,scopeDefinition,scopeDefinitions,scopeResolvers,tenantResolvers,resourceScopes}()`', '`@internal`', 'P2'),
          ('`TenantPolicy::mode(): string`, `AssignmentScopePolicy::mode(): string`', '`TenantMode`, `AssignmentScopeMode`', 'P2'),
          ('`StateToken`/`CodeStateToken` public properties', 'opaque: `panel()`, `equals()`, `toString()`, `fromString()`; payload keys unchanged', 'P2'),
          ('`Scopes\\CurrentContext::get()`', '`PanelAccess::scope()` / `AzGuard::currentScope()`', 'P2'),
          ('models using `ContextAware` without an interface', 'implement `Contracts\\Scopes\\MapsAssignmentScope` (the trait does)', 'P2'),
          ('grant fields named `azg_*`', 'rejected (reserved prefix); rename the field', 'P2')])),
      ('config-cli', 'Configuration, CLI and schema behavior', table(['0.7.0', 'target', 'phase'], [
          ('`defaults.gate.mode`', 'remove the key from a published config', 'P2'),
          ('`StorageSchema::create/drop` (also via published migrations)', 'six tables; `audit_log` owned by Audit', 'P3'),
          ('fresh install with `AuditPlugin`', '`php artisan vendor:publish --tag=azguard-audit-migrations && php artisan migrate`; named storages: `azguard:audit:migration <storage>`', 'P3'),
          ('existing `audit_log`', 'no action; adopted when the Audit migration is published', 'P3'),
          ('`azguard:storage:migration <name>`', 'creates six tables (no audit table)', 'P3'),
          ('`azguard:install --panel`', 'unchanged (owned by DevTools)', 'P3'),
          ('doctor `gate.mode` finding', 'retired', 'P2'),
          ('doctor `storage.migrated`', 'checks six core tables; `audit.table` covers the journal', 'P3')]))]

for name, secs, title in (('type-map', tm, 'Type map: every production type, its target owner, FQCN, marker and action'),
                          ('target-structure', ts, 'Target package and module structure'),
                          ('identities', idn, 'Frozen identities: commands, config, resources, events, exceptions, doctor'),
                          ('upgrade-map', um, '0.7.0 to 1.0-candidate upgrade map (seed)')):
    json.dump({'title': title, 'sections': doc(secs)}, open(os.path.join(out, name + '.json'), 'w'), ensure_ascii=False, indent=1)
    print(name, sum(len(s[2]) for s in secs))
