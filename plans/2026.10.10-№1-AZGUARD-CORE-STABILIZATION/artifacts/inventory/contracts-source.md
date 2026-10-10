@@ rules | How to read this document
Normative reference for P2. Every signature below is the target shape; a phase-design or implementation session may
change a name, a parameter or a type only through an explicit plan amendment (decision + `#review` entry), never
silently. PHP shown is declaration-only: attributes, parameter names, types, nullability, defaults and documented
generics are part of the contract. Behavior notes state who validates and how failure surfaces; they bind as much as
the signatures. All new types are `final` unless marked abstract or interface; all values are `readonly`.

Conventions used everywhere:

- `#[Api(since: '1.0')]`, `#[Spi(kind: SpiKind::Implement|Call, since: '1.0')]` on every supported type; existing
  0.7.0 types get `since: '0.7'` when their shape is unchanged, `'1.0'` otherwise.
- Core calls `Implement` methods positionally (I20). Parameter names of `Api` callables are covered.
- `class-string<T>` and `list<T>` generics in PHPDoc are part of the contract.
- A "handler" accepted by a slot is `T|class-string<T>`; class-strings are resolved per operation through the container.

@@ markers | Publication attributes
```php
namespace AzGuard\Attributes;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
#[Api(since: '1.0')]
final readonly class Api
{
    public function __construct(public string $since) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since) {}
}

#[Api(since: '1.0')]
enum SpiKind: string            // closed
{
    case Implement = 'implement';   // implemented or extended by applications/extensions
    case Call = 'call';             // used only by extension code; core supplies the implementation
}
```
`@internal` on a member excludes it. A type without either attribute is internal regardless of its namespace; a type
under `\Internal\` must not carry a publication attribute (I4 check).

@@ entry | Application entry point and facade
`AzGuard\AzGuard` replaces `AzGuardManager`; the container alias `azguard` and the facade accessor stay.

```php
namespace AzGuard;

#[Api(since: '1.0')]
final class AzGuard
{
    /** @internal built by the container */
    public function __construct(Application $app) {}

    public function panel(string $id): PanelAccess;
    /** @return array<string, Panel> */
    public function panels(): array;
    public function currentPanel(): ?Panel;
    public function sources(): SourceManager;

    /** @param class-string<PanelProvider> $provider */
    public function registerPanel(string $provider): void;
    /** @param Closure(PanelBuilder): mixed $callback */
    public function configurePanel(string $id, Closure $callback): void;
    /** @param Closure(PanelBuilder): mixed $callback */
    public function configurePanels(Closure $callback): void;

    public function check(Model|Authenticatable|SubjectRef $subject, string|UnitEnum $permission,
        Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;
    public function authorize(Model|Authenticatable|SubjectRef $subject, string|UnitEnum $permission,
        Model|AssignmentScopeRef|null $on = null, ?string $guard = null): void;

    /** @template T @param Closure(): T $callback @return T */
    public function withinScope(Model|AssignmentScopeRef $context, Closure $callback): mixed;
    public function currentScope(): ?AssignmentScopeRef;
    /** @template T @param Closure(): T $callback @return T */
    public function actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure $callback): mixed;

    public function storage(string $id = 'default'): StorageDescriptor;     // new
    /** @return list<StorageDescriptor> */
    public function storages(): array;                                      // new
    public function fake(): AzGuardFake;
}
```
`check()`/`authorize()` replace `mixed $subject` by the union above (the only signature narrowing; anything else was
already rejected at runtime). The facade's `@method static` block is generated from this class and tested for equality
(manifest records facade methods). `AzGuardFake` mirrors the same public methods.

@@ plugins | Plugin SPI
```php
namespace AzGuard\Contracts\Plugins;

#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface Plugin
{
    /** Stable vendor/name id; azguard/* is reserved for this package. */
    public function id(): string;
    /** Declares contributions only: no I/O, no current user/tenant/request/clock, no global listener. */
    public function register(PanelBuilder $panel, PluginContext $context): void;
}
```
```php
namespace AzGuard\Plugins;

#[Spi(kind: SpiKind::Call, since: '0.7')]
final readonly class PluginContext
{
    /** @internal built by the panel compiler */
    public function __construct(string $panelId, string $pluginId, string $buildId) {}
    public function panelId(): string;
    public function pluginId(): string;
    public function buildId(): string;
    // dependencies() removed (D8)
}
```
Removed: `Plugin::boot()`, `Plugins\BasePlugin`, `Contracts\Plugins\DependsOnPlugins`,
`Exceptions\PluginDependencyMissingException`. Kept: nested `plugins([...])` from `register()`, duplicate-id
`PluginConflictException`, `withoutPlugins()` on provider/configure layers only.

@@ panel-builder | PanelBuilder changes (consumer and extension DSL)
Unchanged methods keep their signatures (`id, label, description, default, resourcePrefix, for, middleware, entry,
onDenied, requireRouteChecks, permissions, roles, discover, policies, presentation, fields, cache, consistency,
tenants, tenantResolvers, scopeResolvers, resourceScopes, plugins, withoutPlugins, restrictions, grantConditions,
doctorChecks`). Changed and new members:

```php
namespace AzGuard\Panels;

#[Api(since: '0.7')]
final class PanelBuilder
{
    /** @param list<BeforeHook|class-string<BeforeHook>|Closure(AccessRequest, EvaluationContext): BeforeResult> $hooks */
    public function before(array|BeforeHook|Closure|string $hooks): static;
    /** @param list<AfterHook|class-string<AfterHook>|Closure(AccessRequest, EvaluationContext, Decision): void> $hooks */
    public function after(array|AfterHook|Closure|string $hooks): static;
    /** @param list<ChangePipe|class-string<ChangePipe>|Closure(Change, Closure(Change): ChangeResult): ChangeResult> $pipes */
    public function changing(array $pipes): static;
    /** @param list<ChangeParticipant|class-string<ChangeParticipant>> $participants */
    public function participants(array $participants): static;                       // new
    public function assignmentScopes(AssignmentScopeDeclaration $declaration): static;   // new, replaces scopes()
    /** Typed readonly module options, one object per class per panel. */
    public function options(object $options): static;                                 // new
    public function panelId(): string;                                                  // new read accessor
    // removed: gate(GateMode), scopes(AssignmentScopePolicy)
}
```
Closure arguments are checked at compile time against the named contract's method (parameter count, types, return
type); a mismatch is `DefinitionException`. `options()` rejects an object that is not `readonly`, a second object of
the same class on the same layer, and values containing models, builders, requests, containers or bound closures.

@@ panel | Panel changes
```php
namespace AzGuard\Panels;

#[Api(since: '0.7')]
final readonly class Panel
{
    /** @template T of object @param class-string<T> $class @return T|null */
    public function options(string $class): ?object;                     // new
    public function scopeDeclaration(): ?AssignmentScopeDeclaration;      // new; null means AssignmentScopeMode::None
    public function tenants(): TenantPolicy;                              // FQCN of TenantPolicy moves to Tenancy
    public function writer(): ?WriterSchema;                              // was ?StoresGrants
    // @internal from now on: changing(), before(), after(), restrictions(), grantConditions(), doctorChecks(),
    //   attachedSources(), scopes(), scopeDefinition(), scopeDefinitions(), scopeResolvers(), tenantResolvers(),
    //   resourceScopes() — raw compiled lists are not a supported surface
}
```

@@ hooks | Named contracts of existing slots
```php
namespace AzGuard\Contracts\Authorization;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface BeforeHook
{
    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult;
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AfterHook
{
    public function after(AccessRequest $request, EvaluationContext $context, Decision $decision): void;
}
```
```php
namespace AzGuard\Contracts\Changes;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ChangePipe
{
    /** @param Closure(Change): ChangeResult $next */
    public function handle(Change $change, Closure $next): ChangeResult;
}
```
Semantics unchanged from 0.7.0: a before hook may only continue or deny (exception → failed decision, trace
`hook_error`); an after hook observes (exception reported, decision unchanged); a pipe may change `until`/fields or
cancel by throwing, must call `$next` once and return its result (`OnceTerminal`). SQL support for before hooks still
requires `FiltersAccessQueries` on the same object.

@@ participants | Transactional participants
```php
namespace AzGuard\Contracts\Changes;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ChangeParticipant
{
    /** Base names (without prefix) of the tables this participant appends to; validated at compilation. */
    /** @return list<non-empty-string> */
    public function tables(): array;
    /** @param non-empty-list<ChangeRecord> $records */
    public function record(array $records, OwnedRowsWriter $rows): void;
}

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OwnedRowsWriter
{
    public function storage(): StorageDescriptor;
    /** @param non-empty-list<array<string, scalar|null>> $rows */
    public function insert(string $table, array $rows): void;
}
```
```php
namespace AzGuard\Changes;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ChangeRecord
{
    /** @internal built by the write coordinator */
    public function __construct(
        public Change $change,          // the final, validated change
        public ChangeEffect $effect,    // one effect of it
        public AccessEvent $event,      // built once by core; the same object is dispatched after commit
    ) {}
}
```
Rules: invoked once per operation that produced at least one effect, after the last effect and before the
operation's transaction ends, in declaration order; `$records` keeps effect order. `tables()` may not name a core
table (`permissions`, `role_grants`, `permission_grants`, `panel_state`, `subject_revisions`, `storage_state`) and
two participants of one panel may not declare the same table. `insert()` writes on the operation's connection with
the storage prefix; rows are column maps with scalars only (JSON pre-encoded by the participant); a call after
`record()` returns, to an undeclared table or with a non-scalar value throws `UnsupportedDirectWriteException`. Any
exception rolls back the operation; a retry calls participants again. A panel without the core writer cannot
declare participants (`DefinitionException`).

@@ scopes | Assignment-scope algebra and evaluator
```php
namespace AzGuard\Context;

#[Api(since: '1.0')]
enum AssignmentScopeMode: string          // closed
{
    case None = 'none';
    case Inherit = 'inherit';
    case Isolated = 'isolated';
    case Required = 'required';
}

#[Api(since: '1.0')]
final readonly class AssignmentScopeDeclaration
{
    /**
     * @param list<AssignmentScopeDefinition> $definitions                    unique aliases
     * @param array<string, class-string<AssignmentScopeDirectory>> $directories keyed by alias
     * @param class-string<AssignmentScopeEvaluator>|null $evaluator
     */
    public function __construct(
        public AssignmentScopeMode $mode,
        public array $definitions,
        public array $directories = [],
        public ?string $evaluator = null,
    ) {}
}

#[Api(since: '0.7')]                       // moved from AzGuard\Scopes
enum AssignmentScopePhase: string { case Access = 'access'; case Assignment = 'assignment';
    case Revocation = 'revocation'; case Inspection = 'inspection'; }      // closed

#[Spi(kind: SpiKind::Call, since: '0.7')] // moved from AzGuard\Scopes; fields unchanged
final readonly class AssignmentScopeRuntime { /* panel, scope, subject, user, role, grant, actor, actorModel, now, phase, proposed */ }
```
```php
namespace AzGuard\Contracts\Scopes;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeEvaluator
{
    /** Compile time, once per declared definition and per role binding of that alias; throws DefinitionException. */
    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void;
    /** Eligibility of a structurally verified scope; true never overrides tenant, identity or mode checks. */
    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool;
    /**
     * @param list<array{AssignmentScopeRuntime, ResolvedAssignmentScope}> $checks
     * @return list<bool> one answer per check, same order
     */
    public function allowsMany(array $checks): array;
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QueryableAssignmentScopeEvaluator extends AssignmentScopeEvaluator
{
    /**
     * Narrows a guarded candidate query over the definition's model to eligible contexts; returns false when this
     * runtime cannot be expressed exactly (core raises VisibilityNotSupportedException 'scope_query').
     * @param Builder<Model> $contexts guarded: only where-narrowing is accepted
     */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition,
        Builder $contexts): bool;
}

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MapsAssignmentScope                  // replaces the method_exists probe on resource models
{
    public function azguardContextType(): string;
    /** Null means the model itself is the context. */
    public function azguardContextRelation(): ?string;
}
```
Kept in core unchanged: `AssignmentScopeDefinition`, `QueryableAssignmentScopeDefinition`,
`ResolvedAssignmentScope`, `ProvidesAccessScope`, `ProvidesAssignmentScope`, `ResourceScopeResolver`,
`AssignmentScopeResolver`, `AssignmentScopeDirectory`. `BaseRole::scopes()` keeps
`list<AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>>`.

Core order for a non-global context (scalar, batch, write): mode → declared alias → `resolve()` → ref/tenant/record
verification → `allows()`/`allowsMany()`. A `allowsMany()` result with a different length fails the set. With no
evaluator in the declaration, eligibility is structural only and visibility is unsupported for scoped resources.

@@ scopes-plugin | ScopesPlugin DSL (module)
```php
namespace AzGuard\Scopes;

#[Api(since: '1.0')]
final class ScopesPlugin implements Plugin            // id(): 'azguard/scopes'
{
    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function inherit(AssignmentScopeDefinition|string ...$definitions): self;
    public static function isolated(AssignmentScopeDefinition|string ...$definitions): self;
    public static function required(AssignmentScopeDefinition|string ...$definitions): self;
    /** @param AssignmentScopeMembership|class-string<AssignmentScopeMembership> $membership */
    public function requireMembership(AssignmentScopeMembership|string $membership = AssignmentScopeMembership::class): self;
    /** @param AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter> $adapter */
    public function accessAdapter(string $type, AssignmentScopeAccessAdapter|string $adapter): self;
    public function register(PanelBuilder $panel, PluginContext $context): void;
}
```
`register()` contributes: `assignmentScopes(new AssignmentScopeDeclaration(mode, definitions, directories,
ScopeEvaluator::class))`, `options(new ScopesOptions(membership, adapters))`, the scope membership restriction and
`ScopeMembershipConfigured`. `AssignmentScopePolicy::none()` has no equivalent: no plugin means `None`. The static
factories and fluent methods keep the 0.7.0 parameter names.

@@ storage | Storage descriptor and identity columns
```php
namespace AzGuard\Storage;

#[Api(since: '1.0')]
final readonly class StorageDescriptor
{
    /** @internal built by core */
    public function __construct(
        public string $id,
        public ?string $connection,     // null: default connection
        public string $prefix,
        public string $hostKeys,        // string | bigint | uuid | ulid
    ) {}
    /** Canonical stored form of a host key for this storage. */
    public function canonicalKey(int|string $key): string|int;
}
```
```php
namespace AzGuard\Storage\Schema;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class IdentityColumns
{
    public static function identifier(Blueprint $table, string $column, int $length, StorageDescriptor $storage): ColumnDefinition;
    public static function hostKey(Blueprint $table, string $column, StorageDescriptor $storage): ColumnDefinition;
    /** tenant_* or context_* triple: key (200), type (128, null), id (host key, null) */
    public static function scope(Blueprint $table, string $scope, StorageDescriptor $storage): void;
    /** pair constraint (CHECK, or SQLite triggers) named {prefix}{short}_{scope}_pair */
    public static function scopeConstraint(StorageDescriptor $storage, string $table, string $short, string $scope): void;
}
```
`Storage\Schema\StorageSchema` keeps its FQCN and `create/drop/upgrade(string $storage): void` because published
migrations call it; after P3 it creates and drops the six core tables only.

@@ schema | Writer capabilities
```php
namespace AzGuard\Schema;

#[Api(since: '1.0')]
final readonly class WriterSchema
{
    public function __construct(
        public string $storage,              // storage id
        public bool $dynamicPermissions,     // replaces DatabaseSource::isDynamic() checks in UIs
        public bool $rolesOnly,
    ) {}
}
```
`PanelSchema::writer(): ?WriterSchema` is added; `null` means a read-only panel.

@@ config | Configuration sections and console support
```php
namespace AzGuard\Configuration;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigSections
{
    /** Called from a module provider's register(); the owner validates azguard.<root>. */
    public function own(string $root, Closure $validate): void;   // Closure(array<string, mixed>): void
}
```
```php
namespace AzGuard\Laravel\Console;

#[Spi(kind: SpiKind::Call, since: '1.0')]
trait CommandSupport          // @phpstan-require-extends Illuminate\Console\Command
{
    /** INVALID for input/panel/tenant/identity errors, FAILURE for other AzGuard and unexpected errors. */
    protected function attempt(Closure $body): int;
    protected function selectPanel(): Panel;
    protected function stringOption(string $name): ?string;
    protected function stringArgument(string $name): string;
    protected function dateOption(string $name): ?DateTimeImmutable;
    protected function confirmIrreversible(string $what, bool $force): void;
    /** @param array<string, mixed> $data */
    protected function printJson(array $data): void;
}

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class InvalidCommandInput extends RuntimeException {}
```

@@ audit | Audit module types
```php
namespace AzGuard\Audit;

#[Api(since: '1.0')]
final class AuditPlugin implements Plugin             // id(): 'azguard/audit'
{
    public static function make(int $retentionDays = 90): self;
    public function retention(int $days): static;
    public function retentionDays(): int;
    public function register(PanelBuilder $panel, PluginContext $context): void;
    // retentionIn(PanelRecipe) removed
}

#[Api(since: '1.0')]
final readonly class AuditOptions { public function __construct(public int $retentionDays) {} }
```
`register()` contributes `participants([JournalParticipant::class])`, `options(new AuditOptions($days))` and
`doctorChecks([AuditTableExists::class])`. `JournalParticipant::tables()` is `['audit_log']`; each record becomes one
row with the existing 14 columns, `payload` = JSON of `AccessEvent::toArray()`.

@@ tokens | State tokens and event payloads
`StateToken` and `CodeStateToken` become opaque: public promoted properties turn private; supported members are
`panel(): string`, `equals(self): bool`, `toString(): string` (versioned `st1.`/`ct1.` prefix + base64url JSON) and
`static fromString(string): self`. The event/audit payload keeps the exact 0.7.0 `state` object keys
(`kind, storage, panel, incarnation, version, generation, fingerprint` / `kind, panel, build_id, fingerprint`), so
journal rows written before and after the transition have one shape.

@@ tenancy | Tenancy enum
```php
namespace AzGuard\Tenancy;

#[Api(since: '1.0')]
enum TenantMode: string { case None = 'none'; case Required = 'required'; }     // closed
```
`TenantPolicy::mode()` returns `TenantMode`; the factories `none()`, `required()`, `requireMembership()`,
`allowGlobalRoles()` keep their names and parameters.
