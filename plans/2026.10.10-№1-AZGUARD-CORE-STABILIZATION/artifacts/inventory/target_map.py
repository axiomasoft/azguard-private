"""Target placement of every production type (AzGuard architecture revision 2, knowledge/type-map).

Input: the raw scan (scan.py output). Output: target-map.json with one row per current type plus new types.
Rules are declared once below; every exception to a rule is an explicit override with a reason.
Usage: python3 target_map.py <types.json> <target-map.json>
"""
import json
import sys

API, IMPL, CALL, INTERNAL = 'Api', 'Spi(Implement)', 'Spi(Call)', 'internal'

# Owner roots and their target source directories.
ROOTS = {
    'core': ('AzGuard\\', 'packages/core/src/'),
    'scopes': ('AzGuard\\Scopes\\', 'packages/core/modules/scopes/src/'),
    'audit': ('AzGuard\\Audit\\', 'packages/core/modules/audit/src/'),
    'devtools': ('AzGuard\\DevTools\\', 'packages/core/modules/devtools/src/'),
    'filament': ('AzGuard\\Filament\\', 'packages/filament/src/'),
}

CLOSED_ENUMS = {'BeforeResult', 'Effect', 'PermissionAuthority', 'Reads', 'StateRefresh', 'FieldTarget', 'Severity',
                'AssignmentScopePhase', 'AssignmentScopeMode', 'TenantMode', 'Volatility', 'SpiKind', 'ChangeStatus',
                'EffectKind', 'CacheState', 'FilamentSurface', 'ContractPermission', 'OtherPermission'}
OPEN_ENUMS = {'DecisionReason', 'FailureKind', 'EventType', 'ChangeType', 'FilamentDefinitions'}

# Marker of public types whose kind is not the zone default.
MARKER = {
    # consumer interfaces implemented only by core
    'AzGuard\\Contracts\\PanelAccess': API, 'AzGuard\\Contracts\\Catalog\\PermissionCatalog': API,
    'AzGuard\\Contracts\\Roles\\RoleCatalog': API, 'AzGuard\\Contracts\\Changes\\GrantManager': API,
    'AzGuard\\Contracts\\Changes\\PermissionManager': API, 'AzGuard\\Contracts\\Panels\\PanelRegistry': API,
    'AzGuard\\Contracts\\Authorization\\EvaluationContext': CALL,
    # values/capabilities serving extension implementers
    'AzGuard\\Contracts\\Sources\\AssignmentScopeSelection': CALL, 'AzGuard\\Contracts\\Sources\\Volatility': CALL,
    'AzGuard\\Contracts\\Sources\\SourceDescription': API, 'AzGuard\\Contracts\\Scopes\\ResolvedAssignmentScope': CALL,
    'AzGuard\\Kernel\\Decision\\AccessPredicate': CALL, 'AzGuard\\Kernel\\Decision\\RestrictionResult': CALL,
    'AzGuard\\Kernel\\Decision\\BeforeResult': CALL, 'AzGuard\\Changes\\Change': CALL, 'AzGuard\\Changes\\ChangeContext': CALL,
    'AzGuard\\Diagnostics\\DoctorContext': CALL, 'AzGuard\\Plugins\\PluginContext': CALL,
    # implementer/extension bases
    'AzGuard\\Contracts\\AzGuardSubject': IMPL, 'AzGuard\\Roles\\BaseRole': IMPL, 'AzGuard\\Roles\\GrantedAutomatically': IMPL,
    'AzGuard\\Panels\\PanelProvider': IMPL, 'AzGuard\\Storage\\Models\\Permission': IMPL,
    'AzGuard\\Storage\\Models\\PermissionGrant': IMPL, 'AzGuard\\Storage\\Models\\RoleGrant': IMPL,
    'AzGuard\\Scopes\\BaseAssignmentScope': IMPL,
    'AzGuard\\Filament\\Contracts\\FilamentFormExtension': IMPL,
    # contract-test suites are used by extension authors
    'AzGuard\\Testing\\Contracts\\AssignmentScopeResolverContractTests': CALL, 'AzGuard\\Testing\\Contracts\\HookContractTests': CALL,
    'AzGuard\\Testing\\Contracts\\PluginContractTests': CALL, 'AzGuard\\Testing\\Contracts\\RestrictionContractTests': CALL,
    'AzGuard\\Testing\\Contracts\\SourceContractTests': CALL, 'AzGuard\\Testing\\Contracts\\SubjectResolverContractTests': CALL,
    # composition roots: FQCN is an identity (provider discovery, dont-discover lists)
    'AzGuard\\AzGuardServiceProvider': API, 'AzGuard\\Filament\\AzGuardFilamentServiceProvider': API,
}

# Published at 0.7.0 but closed (no supported use outside core) — upgrade-map entries.
CLOSE = {
    'AzGuard\\Directories\\ModelSubjectDirectory': 'default implementation; consumers receive the SubjectDirectory contract',
    'AzGuard\\Directories\\ModelTenantDirectory': 'default implementation; consumers receive the TenantDirectory contract',
    'AzGuard\\Kernel\\Grammar\\PatternMatcher': 'pure helper without a demonstrated supported use (no Filament/consumer use)',
    'AzGuard\\Storage\\Storage': 'replaced by the Storage\\StorageDescriptor value; mutation and sessions stay internal',
    'AzGuard\\Storage\\Schema\\HostKeyColumns': 'replaced by the public Storage\\Schema\\IdentityColumns helper',
}

# Explicit placement decisions: current FQCN -> (owner, target FQCN or None, marker, action, phase, reason).
S = 'AzGuard\\Scopes\\'
O = {
    # entry point
    'AzGuard\\AzGuardManager': ('core', 'AzGuard\\AzGuard', API, 'rename', 'P2', 'typed application entry point (D4, api-compatibility)'),
    # retired
    'AzGuard\\Contracts\\Plugins\\DependsOnPlugins': ('retired', None, None, 'retire', 'P2', 'sibling plugin-id dependency (D8, principle 4)'),
    'AzGuard\\Plugins\\BasePlugin': ('retired', None, None, 'retire', 'P2', 'only declared the removed boot() (D8)'),
    'AzGuard\\Panels\\GateMode': ('retired', None, None, 'retire', 'P2', 'single-case enum (api-compatibility)'),
    'AzGuard\\Diagnostics\\Checks\\GateModeCheck': ('retired', None, None, 'retire', 'P2', 'checks the retired GateMode; finding id gate.mode retired with it'),
    'AzGuard\\Contracts\\Sources\\StoresGrants': ('retired', None, None, 'retire', 'P2', 'unimplementable writer SPI (D10); DatabaseSource is the writer'),
    'AzGuard\\Exceptions\\PluginDependencyMissingException': ('retired', None, None, 'retire', 'P2', 'raised only for DependsOnPlugins (D8)'),
    # Context and Tenancy (scope algebra and tenant isolation in core)
    S + 'AssignmentScopePhase': ('core', 'AzGuard\\Context\\AssignmentScopePhase', API, 'move', 'P2', 'context value used by writes, directories and Filament (D7)'),
    S + 'AssignmentScopeRuntime': ('core', 'AzGuard\\Context\\AssignmentScopeRuntime', CALL, 'move', 'P2', 'evaluator input value (scope-boundary)'),
    S + 'CurrentContext': ('core', 'AzGuard\\Context\\Internal\\CurrentContext', INTERNAL, 'move', 'P2', 'mutable store; reads through PanelAccess::scope() and AzGuard::currentScope()'),
    S + 'WithinContext': ('core', 'AzGuard\\Context\\Internal\\WithinContext', INTERNAL, 'move', 'P2', 'reached through AzGuard::withinScope()'),
    S + 'TenantPolicy': ('core', 'AzGuard\\Tenancy\\TenantPolicy', API, 'move', 'P2', 'tenant isolation is core; mode becomes TenantMode'),
    S + 'ModelTenantDefinition': ('core', 'AzGuard\\Tenancy\\ModelTenantDefinition', API, 'move', 'P2', 'tenant definition'),
    'AzGuard\\Contracts\\Scopes\\TenantDirectory': ('core', 'AzGuard\\Contracts\\Tenancy\\TenantDirectory', IMPL, 'move', 'P2', 'tenancy contract'),
    'AzGuard\\Contracts\\Scopes\\TenantMembership': ('core', 'AzGuard\\Contracts\\Tenancy\\TenantMembership', IMPL, 'move', 'P2', 'tenancy contract'),
    'AzGuard\\Contracts\\Scopes\\TenantResolver': ('core', 'AzGuard\\Contracts\\Tenancy\\TenantResolver', IMPL, 'move', 'P2', 'tenancy contract (Filament implements it)'),
    S + 'MembershipRestriction': ('split', 'AzGuard\\Tenancy\\Internal\\TenantMembershipRestriction + AzGuard\\Scopes\\Internal\\ScopeMembershipRestriction', INTERNAL, 'split', 'P2/P3', 'tenant membership core, scope membership module'),
    S + 'ModelIdentity': ('split', 'AzGuard\\Tenancy\\Internal\\TenantIdentity + AzGuard\\Context\\Internal\\ContextIdentity', INTERNAL, 'split', 'P2', 'tenant mapping vs context mapping; keys via Support\\ModelKey'),
    S + 'ScopeConfiguration': ('split', 'AzGuard\\Panels\\Internal\\CanonicalMetadata + AzGuard\\Scopes\\Internal\\ScopeConfiguration', INTERNAL, 'split', 'P2/P3', 'generic canonical fingerprint metadata core; filter/binding validation module'),
    S + 'Query\\PredicateBuilder': ('core', 'AzGuard\\Authorization\\Internal\\Query\\PredicateBuilder', INTERNAL, 'move', 'P3', 'core guarded candidate query handed to evaluators'),
    S + 'Query\\PredicateQuery': ('core', 'AzGuard\\Authorization\\Internal\\Query\\PredicateQuery', INTERNAL, 'move', 'P3', 'core guarded candidate query'),
    S + 'Query\\QueryGuard': ('core', 'AzGuard\\Authorization\\Internal\\Query\\QueryGuard', INTERNAL, 'move', 'P3', 'narrowing-only guard'),
    'AzGuard\\Authorization\\Query\\VisibilityScope': ('split', 'AzGuard\\Authorization\\Internal\\Query\\ResourceContextMapping + AzGuard\\Scopes\\Internal\\Query\\ScopeVisibility', INTERNAL, 'split', 'P2/P3', 'structural mapping core; eligibleContexts module (R1)'),
    'AzGuard\\Diagnostics\\Checks\\MembershipConfigured': ('split', 'AzGuard\\Diagnostics\\Internal\\Checks\\TenantMembershipConfigured + AzGuard\\Scopes\\Internal\\Checks\\ScopeMembershipConfigured', INTERNAL, 'split', 'P2/P3', 'finding id membership.configured kept by both parts'),
    # Scopes module (FQCN under AzGuard\Scopes is kept where the class stays in the module)
    S + 'AssignmentScopePolicy': ('scopes', 'AzGuard\\Scopes\\ScopesPlugin', API, 'rename', 'P2', 'the policy DSL becomes the plugin that contributes the core declaration'),
    S + 'AssignmentScopeSettings': ('scopes', S + 'AssignmentScopeSettings', API, 'relocate', 'P3', 'module-owned settings; FQCN unchanged'),
    S + 'BaseAssignmentScope': ('scopes', S + 'BaseAssignmentScope', IMPL, 'relocate', 'P3', 'module base definition; FQCN unchanged'),
    S + 'ModelAssignmentScopeDefinition': ('scopes', S + 'ModelAssignmentScopeDefinition', API, 'relocate', 'P3', 'module definition; FQCN unchanged'),
    S + 'ContextAware': ('scopes', S + 'ContextAware', API, 'relocate', 'P3', 'implements core Contracts\\Scopes\\MapsAssignmentScope; FQCN unchanged'),
    S + 'RoleBindings': ('scopes', S + 'Internal\\RoleBindings', INTERNAL, 'relocate', 'P3', 'binding refinement is module behavior'),
    S + 'Query\\EligibilityBuilder': ('scopes', S + 'Internal\\Query\\EligibilityBuilder', INTERNAL, 'relocate', 'P3', 'filter evaluation'),
    'AzGuard\\Authorization\\ScopeEligibility': ('scopes', S + 'Internal\\ScopeEligibility', INTERNAL, 'relocate', 'P2/P3', 'becomes the body of the module evaluator'),
    'AzGuard\\Directories\\QueryScopeDirectory': ('scopes', S + 'Internal\\QueryScopeDirectory', INTERNAL, 'relocate', 'P3', 'default directory of model-backed aliases; closed (consumers receive AssignmentScopeDirectory)'),
    'AzGuard\\Contracts\\Scopes\\AssignmentScopeAccessAdapter': ('scopes', S + 'Contracts\\AssignmentScopeAccessAdapter', IMPL, 'move', 'P3', 'module contract'),
    'AzGuard\\Contracts\\Scopes\\AssignmentScopeFilter': ('scopes', S + 'Contracts\\AssignmentScopeFilter', IMPL, 'move', 'P3', 'module contract'),
    'AzGuard\\Contracts\\Scopes\\AssignmentScopeMembership': ('scopes', S + 'Contracts\\AssignmentScopeMembership', IMPL, 'move', 'P3', 'module contract'),
    'AzGuard\\Contracts\\Scopes\\ConfigurableAssignmentScopeDefinition': ('scopes', S + 'Contracts\\ConfigurableAssignmentScopeDefinition', IMPL, 'move', 'P3', 'module contract'),
    # Audit module
    'AzGuard\\Plugins\\Audit\\AuditPlugin': ('audit', 'AzGuard\\Audit\\AuditPlugin', API, 'move', 'P3', 'module plugin; retentionIn(PanelRecipe) removed in P2'),
    'AzGuard\\Plugins\\Audit\\AuditTableExists': ('audit', 'AzGuard\\Audit\\Internal\\Checks\\AuditTableExists', INTERNAL, 'move', 'P3', 'key audit.table; adds shape verification'),
    'AzGuard\\Plugins\\Audit\\RecordChange': ('audit', 'AzGuard\\Audit\\Internal\\JournalParticipant', INTERNAL, 'rename', 'P2/P3', 'change pipe becomes a ChangeParticipant'),
    'AzGuard\\Changes\\ChangeJournal': ('audit', 'AzGuard\\Audit\\Internal\\JournalRow', INTERNAL, 'split', 'P2/P3', 'row encoding moves; the frame/appender sink is retired (R6)'),
    'AzGuard\\Laravel\\Console\\Commands\\AuditPruneCommand': ('audit', 'AzGuard\\Audit\\Internal\\Console\\AuditPruneCommand', INTERNAL, 'move', 'P3', 'azguard:audit:prune; reads AuditOptions'),
    # DevTools module
    'AzGuard\\Laravel\\Console\\Commands\\InstallCommand': ('devtools', 'AzGuard\\DevTools\\Internal\\Console\\InstallCommand', INTERNAL, 'move', 'P3', 'onboarding scaffolding; azguard:install unchanged (R9)'),
    'AzGuard\\Laravel\\Console\\Commands\\StubsCommand': ('devtools', 'AzGuard\\DevTools\\Internal\\Console\\StubsCommand', INTERNAL, 'move', 'P3', 'azguard:stubs'),
    'AzGuard\\Laravel\\Console\\Concerns\\InteractsWithAzGuard': ('split', 'AzGuard\\Laravel\\Console\\CommandSupport + AzGuard\\Laravel\\Internal\\Console\\GrantCommandSupport', INTERNAL, 'split', 'P2', 'exit-code mapping and panel selection shared by module commands (one source of truth)'),
    'AzGuard\\Laravel\\Console\\Concerns\\InvalidCommandInput': ('core', 'AzGuard\\Laravel\\Console\\InvalidCommandInput', CALL, 'move', 'P2', 'caught by CommandSupport::attempt(); module commands throw it'),
    # Storage helpers
    'AzGuard\\Storage\\Schema\\HostKeyColumns': ('core', 'AzGuard\\Storage\\Internal\\Schema\\HostKeyColumns', INTERNAL, 'move', 'P3', 'wrapped by public IdentityColumns'),
    # Kernel helper
    'AzGuard\\Kernel\\Support\\Narrow': ('core', 'AzGuard\\Kernel\\Internal\\Narrow', INTERNAL, 'move', 'P3', 'internal helper; modules validate with their own code or supported constructors'),
    'AzGuard\\Kernel\\Grammar\\PatternMatcher': ('core', 'AzGuard\\Kernel\\Internal\\PatternMatcher', INTERNAL, 'move', 'P3', 'closed helper'),
    'AzGuard\\Attributes\\Concerns\\DescribesPermissionCheck': ('core', 'AzGuard\\Attributes\\Internal\\DescribesPermissionCheck', INTERNAL, 'move', 'P3', 'internal trait of the route attributes'),
}

# Make commands and scaffolding to DevTools
for t in ['MakeCommand', 'MakeModelsCommand', 'MakePanelCommand', 'MakePermissionCommand', 'MakePipeCommand', 'MakePluginCommand',
          'MakePolicyCommand', 'MakeRestrictionCommand', 'MakeRoleCommand', 'MakeSourceCommand']:
    O['AzGuard\\Laravel\\Console\\Commands\\Make\\' + t] = ('devtools', 'AzGuard\\DevTools\\Internal\\Console\\Make\\' + t, INTERNAL, 'move', 'P3', 'generator command; name unchanged')
for t in ['EnumSource', 'GeneratedFile', 'Layout', 'Place', 'PolicyFile', 'ProviderRegistration', 'StubStore']:
    O['AzGuard\\Laravel\\Console\\Scaffold\\' + t] = ('devtools', 'AzGuard\\DevTools\\Internal\\Scaffold\\' + t, INTERNAL, 'move', 'P3', 'scaffolding')

# Filament: framework-convention resource pages keep their place (marked internal).
FILAMENT_KEEP_INTERNAL = {'Resources\\PermissionGrantResource\\Pages\\ListPermissionGrants', 'Resources\\PermissionResource\\Pages\\ListPermissions',
                          'Resources\\RoleGrantResource\\Pages\\ListRoleGrants', 'Resources\\RoleResource\\Pages\\ListRoles',
                          'Resources\\RoleResource\\Pages\\ViewRole'}

NEW = [
    # (owner, fqcn, kind, marker, phase, reason)
    ('core', 'AzGuard\\Attributes\\Api', 'class', API, 'P2', 'publication marker (D9)'),
    ('core', 'AzGuard\\Attributes\\Spi', 'class', API, 'P2', 'publication marker (D9)'),
    ('core', 'AzGuard\\Attributes\\SpiKind', 'enum', API, 'P2', 'Implement | Call (closed)'),
    ('core', 'AzGuard\\Context\\AssignmentScopeMode', 'enum', API, 'P2', 'None | Inherit | Isolated | Required (closed)'),
    ('core', 'AzGuard\\Context\\AssignmentScopeDeclaration', 'class', API, 'P2', 'the core scope declaration value'),
    ('core', 'AzGuard\\Tenancy\\TenantMode', 'enum', API, 'P2', 'None | Required (closed)'),
    ('core', 'AzGuard\\Contracts\\Scopes\\AssignmentScopeEvaluator', 'interface', IMPL, 'P2', 'eligibility of verified scopes'),
    ('core', 'AzGuard\\Contracts\\Scopes\\QueryableAssignmentScopeEvaluator', 'interface', IMPL, 'P2', 'exact eligible-context query'),
    ('core', 'AzGuard\\Contracts\\Scopes\\MapsAssignmentScope', 'interface', IMPL, 'P2', 'typed resource→context mapping (replaces method_exists probe)'),
    ('core', 'AzGuard\\Contracts\\Authorization\\BeforeHook', 'interface', IMPL, 'P2', 'named contract of the before slot'),
    ('core', 'AzGuard\\Contracts\\Authorization\\AfterHook', 'interface', IMPL, 'P2', 'named contract of the after slot'),
    ('core', 'AzGuard\\Contracts\\Changes\\ChangePipe', 'interface', IMPL, 'P2', 'named contract of the changing slot'),
    ('core', 'AzGuard\\Contracts\\Changes\\ChangeParticipant', 'interface', IMPL, 'P2', 'transactional participant (D8)'),
    ('core', 'AzGuard\\Contracts\\Changes\\OwnedRowsWriter', 'interface', CALL, 'P2', 'append-only capability handed to participants'),
    ('core', 'AzGuard\\Changes\\ChangeRecord', 'class', CALL, 'P2', 'one effective change with its core-built event'),
    ('core', 'AzGuard\\Changes\\Internal\\OperationRows', 'class', INTERNAL, 'P2', 'OwnedRowsWriter implementation bound to one operation'),
    ('core', 'AzGuard\\Storage\\StorageDescriptor', 'class', API, 'P2', 'readonly storage id/connection/prefix/host keys'),
    ('core', 'AzGuard\\Storage\\Schema\\IdentityColumns', 'class', CALL, 'P2', 'identity columns and pair constraints for owned tables'),
    ('core', 'AzGuard\\Schema\\WriterSchema', 'class', API, 'P2', 'writer capabilities of a panel (D10)'),
    ('core', 'AzGuard\\Configuration\\ConfigSections', 'class', CALL, 'P2', 'owner-registered config root sections'),
    ('core', 'AzGuard\\Laravel\\Console\\CommandSupport', 'trait', CALL, 'P2', 'shared exit-code mapping and panel selection'),
    ('core', 'AzGuard\\Laravel\\Internal\\Console\\GrantCommandSupport', 'trait', INTERNAL, 'P2', 'remaining helpers of core grant commands'),
    ('core', 'AzGuard\\Panels\\Internal\\CanonicalMetadata', 'class', INTERNAL, 'P2', 'canonical fingerprint metadata of declarations and options'),
    ('core', 'AzGuard\\Authorization\\Internal\\Query\\ResourceContextMapping', 'class', INTERNAL, 'P2', 'structural part of VisibilityScope'),
    ('core', 'AzGuard\\Tenancy\\Internal\\TenantMembershipRestriction', 'class', INTERNAL, 'P2', 'tenant part of MembershipRestriction'),
    ('core', 'AzGuard\\Tenancy\\Internal\\TenantIdentity', 'class', INTERNAL, 'P2', 'tenant part of ModelIdentity'),
    ('core', 'AzGuard\\Context\\Internal\\ContextIdentity', 'class', INTERNAL, 'P2', 'context part of ModelIdentity'),
    ('core', 'AzGuard\\Diagnostics\\Internal\\Checks\\TenantMembershipConfigured', 'class', INTERNAL, 'P2', 'tenant part of MembershipConfigured'),
    ('core', 'AzGuard\\Testing\\Contracts\\ChangeParticipantContractTests', 'trait', CALL, 'P2', 'contract suite of the new SPI'),
    ('core', 'AzGuard\\Testing\\Contracts\\AssignmentScopeEvaluatorContractTests', 'trait', CALL, 'P2', 'contract suite of the new SPI'),
    ('scopes', 'AzGuard\\Scopes\\Internal\\ScopeEvaluator', 'class', INTERNAL, 'P2', 'implements AssignmentScopeEvaluator + QueryableAssignmentScopeEvaluator'),
    ('scopes', 'AzGuard\\Scopes\\Internal\\ScopesOptions', 'class', INTERNAL, 'P2', 'typed module options (membership, adapters, directories)'),
    ('scopes', 'AzGuard\\Scopes\\Internal\\ScopeMembershipRestriction', 'class', INTERNAL, 'P2', 'scope part of MembershipRestriction'),
    ('scopes', 'AzGuard\\Scopes\\Internal\\Query\\ScopeVisibility', 'class', INTERNAL, 'P2', 'scope part of VisibilityScope'),
    ('scopes', 'AzGuard\\Scopes\\Internal\\Checks\\ScopeMembershipConfigured', 'class', INTERNAL, 'P2', 'scope part of MembershipConfigured'),
    ('scopes', 'AzGuard\\Scopes\\Testing\\AssignmentScopeFilterContractTests', 'trait', CALL, 'P3', 'module contract suite'),
    ('audit', 'AzGuard\\Audit\\AuditOptions', 'class', API, 'P2', 'typed options: retentionDays'),
    ('audit', 'AzGuard\\Audit\\AuditServiceProvider', 'class', API, 'P3', 'composition root (FQCN is an identity)'),
    ('audit', 'AzGuard\\Audit\\Internal\\AuditSchema', 'class', INTERNAL, 'P3', 'audit DDL, create-or-adopt with shape check'),
    ('audit', 'AzGuard\\Audit\\Internal\\JournalPruner', 'class', INTERNAL, 'P3', 'retention deletes in own short transactions'),
    ('audit', 'AzGuard\\Audit\\Internal\\Console\\AuditMigrationCommand', 'class', INTERNAL, 'P3', 'azguard:audit:migration {storage}'),
    ('devtools', 'AzGuard\\DevTools\\DevToolsServiceProvider', 'class', API, 'P3', 'composition root (FQCN is an identity)'),
    ('devtools', 'AzGuard\\DevTools\\Internal\\ScaffoldConfig', 'class', INTERNAL, 'P3', 'owner of the scaffold config section'),
]


def internal_target(fq: str) -> str:
    """AzGuard\\<Zone>[\\<Adapter>]\\<rest> -> ...\\Internal\\<rest>. Sources keep per-adapter Internal; Laravel and
    Diagnostics keep their sub-structure under Internal."""
    parts = fq.split('\\')
    if parts[1] == 'Filament':
        zone = parts[:3] if len(parts) > 3 else parts[:2]
        rest = parts[3:] if len(parts) > 3 else parts[2:]
        if parts[2] in ('Commands',):
            return '\\'.join(parts[:2] + ['Internal', 'Console'] + parts[3:])
        return '\\'.join(parts[:2] + ['Internal'] + parts[2:])
    if len(parts) == 2:
        return fq
    zone_len = 3 if parts[1] == 'Sources' and len(parts) > 3 else 2
    if 'Internal' in parts:
        return fq
    return '\\'.join(parts[:zone_len] + ['Internal'] + parts[zone_len:])


def path_of(owner: str, fq: str) -> str:
    prefix, directory = ROOTS[owner]
    if not fq.startswith(prefix):
        prefix, directory = ROOTS['core']
    return directory + fq[len(prefix):].replace('\\', '/') + '.php'


def current_marker(t: dict) -> str:
    if t['manifest']:
        return t['manifest']['stability'] + ('(location)' if t['manifest']['via'] == 'location' else '')
    return 'internal' if 'internal' in t['tags'] else 'unmarked'


def default_marker(t: dict) -> str:
    fq = t['fqcn']
    if fq in MARKER:
        return MARKER[fq]
    if not t['manifest']:
        return INTERNAL
    if t['manifest']['stability'] == 'spi':
        return IMPL if t['kind'] in ('interface', 'trait') or 'abstract' in t['modifiers'] else CALL
    return API


def main() -> None:
    types = json.load(open(sys.argv[1]))['types']
    # The conditional declaration of the route attribute is not seen by the scanner.
    if not any(t['fqcn'] == 'AzGuard\\Attributes\\CheckPermission' for t in types):
        types.append({'fqcn': 'AzGuard\\Attributes\\CheckPermission', 'package': 'core', 'path': 'packages/core/src/Attributes/CheckPermission.php',
                      'kind': 'class', 'modifiers': ['final'], 'lines': 85, 'tags': ['api'],
                      'manifest': {'stability': 'api', 'via': 'tag'}, 'used_by': {'core': 1, 'filament': 0, 'tests': 0}})
    rows = []
    for t in sorted(types, key=lambda x: x['fqcn']):
        fq = t['fqcn']
        short = fq.split('\\')[-1]
        enum = ('closed' if short in CLOSED_ENUMS else 'open' if short in OPEN_ENUMS else 'unclassified') if t['kind'] == 'enum' and fq not in O or (t['kind'] == 'enum' and O.get(fq, (None,))[0] != 'retired') else None
        if fq in O:
            owner, target, marker, action, phase, reason = O[fq]
        else:
            marker = default_marker(t)
            owner = 'filament' if t['package'] == 'filament' else 'core'
            if fq in CLOSE:
                marker = INTERNAL
            if marker == INTERNAL and not (owner == 'filament' and fq.split('Filament\\', 1)[1] in FILAMENT_KEEP_INTERNAL):
                target = internal_target(fq)
                action = 'move' if target != fq else 'keep'
                phase = 'P3' if target != fq else '—'
                reason = CLOSE.get(fq, 'implementation detail; namespace states the boundary')
                if fq in CLOSE:
                    action = 'close+move'
            else:
                target, action, phase = fq, 'keep', '—'
                reason = 'framework convention (Filament resource page); marked @internal' if marker == INTERNAL else 'supported surface; FQCN unchanged'
        target_owner = owner
        if owner == 'split':
            path = ' + '.join(path_of(('scopes' if x.strip().startswith('AzGuard\\Scopes\\') else 'core'), x.strip()) for x in target.split('+'))
        elif owner == 'retired':
            path = None
        else:
            path = path_of(owner, target)
        rows.append({'current': fq, 'current_path': t['path'], 'kind': t['kind'], 'lines': t['lines'],
                     'current_marker': current_marker(t), 'owner': target_owner, 'target': target, 'target_path': path,
                     'marker': marker, 'enum': enum, 'action': action, 'phase': phase, 'reason': reason,
                     'used_by': t['used_by']})
    for owner, fq, kind, marker, phase, reason in NEW:
        short = fq.split('\\')[-1]
        enum = ('closed' if short in CLOSED_ENUMS else 'open') if kind == 'enum' else None
        rows.append({'current': None, 'current_path': None, 'kind': kind, 'lines': None, 'current_marker': None, 'owner': owner,
                     'target': fq, 'target_path': path_of(owner, fq), 'marker': marker, 'enum': enum, 'action': 'new',
                     'phase': phase, 'reason': reason, 'used_by': None})
    json.dump({'baseline': '09612160c98ca6133c894a8f90f5653b9dac6b8f', 'rows': rows}, open(sys.argv[2], 'w'), indent=1, ensure_ascii=False)
    from collections import Counter
    print(len(rows), Counter(r['owner'] for r in rows), Counter(r['action'] for r in rows), Counter(r['marker'] for r in rows))


if __name__ == '__main__':
    main()
