# 05 — Публичный PHP API (нормативные сигнатуры)

Решения: [D05](02-decisions.md#d05)–[D11](02-decisions.md#d11), [D22](02-decisions.md#d22), [D27](02-decisions.md#d27)–[D29](02-decisions.md#d29),
[D31](02-decisions.md#d31), [D37](02-decisions.md#d37), [D45](02-decisions.md#d45)–[D50](02-decisions.md#d50).
Сигнатуры нормативны по именам, типам и смыслу; порядок необязательных параметров можно уточнить в спецификации
пункта плана. Всё ниже — `@api`, если не сказано иное. Контракты расширения (плагины, шаги пайплайнов) — в [06](06-extension-points.md).

## 0. Как это выглядит целиком

```php
// Панель админки: сотрудники, свои модели, аудит, подтверждения
final class AdminPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('admin')->label('Админка')
            ->subjects(guard: 'admin', models: [Admin::class])
            ->permissions(OrderPermission::class, UserPermission::class)
            ->roles(SupportRole::class, ManagerRole::class)
            ->storage('backoffice')                                   // именованное хранилище из конфига
            ->models(assignment: AdminRoleAssignment::class)          // своя модель с department_id
            ->decisionAttributes(assignment: ['weekdays'])            // поле участвует в решении
            ->restrict(OfficeHoursRestriction::class)
            ->plugins([AuditPlugin::make(), ApprovalPlugin::make()->forRoles('admin:manager')]);
    }
}

// Панель сайта: покупатели, права внутри магазина
final class SitePanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('site')
            ->subjects(guard: 'web', models: [Customer::class])
            ->permissions(ShopPermission::class)
            ->roles(BuyerRole::class, StoreOwnerRole::class)
            ->contexts(ContextPolicy::inherit('store')->requireMembership(StoreMembership::class))
            ->contextResolvers(RouteParameterResolver::for('store'))
            ->cache(ttl: 3600);
    }
}

// Модуль Blog дополняет админку
AzGuard::configurePanel('admin', fn (PanelBuilder $p) => $p->plugin(BlogAccessPlugin::make()->keyPrefix('blog')));

// Проверки
$admin->hasPermission(OrderPermission::Refund);                         // enum подключён к одной панели → admin
AzGuard::check($customer, 'site.orders.cancel', context: $store);
Gate::allows('admin.orders.refund');                                     // панель из ключа
AzGuard::panel('admin')->decideMany($requests);

// Изменения
AzGuard::panel('admin')->manage()->actingAs($vera)
    ->assignRole($anna, 'admin:support', expiresAt: now()->addMonth(), attributes: ['department_id' => 7]);
AzGuard::panel('site')->manage()->asSystem('checkout')
    ->grantPermission($customer, 'site.reviews.create', context: $store);
```

## 1. Фасад

```php
namespace AzGuard\Facades;

/**
 * @method static PanelAuthorizer panel(string $id)
 * @method static array<string, Panel> panels()
 * @method static Panel|null currentPanel()
 * @method static void registerPanel(class-string<PanelProvider> $provider)             // до заморозки
 * @method static void configurePanel(string $id, Closure(PanelBuilder): mixed $callback) // до заморозки
 * @method static bool check(mixed $subject, PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null, ?string $panel = null)
 * @method static void authorize(mixed $subject, PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null, ?string $panel = null)
 * @method static Decision decide(AccessRequest $request)
 * @method static mixed withinContext(ContextRef|Model $context, Closure $callback)
 * @method static ContextRef|null currentContext()
 */
final class AzGuard extends Facade
{
    public static function fake(): AzGuardFake;
}
```

`$subject` — `Model|Authenticatable|SubjectRef` (приводит `SubjectResolver` панели). Глобальные `check/authorize/decide`
находят панель по ключу (D05) и делегируют `PanelAuthorizer`.

## 2. Значения ядра (`AzGuard\Kernel\…`)

```php
final readonly class PermissionKey implements Stringable, JsonSerializable
{
    public static function from(PermissionKey|string|UnitEnum $permission, ?string $panel = null): self;
    //   строка — только полный ключ; enum/класс — локальная часть + панель (явная или единственная, иначе AmbiguousPanelException)
    public static function in(string $panel, string $local): self;
    public function panel(): string;
    public function local(): string;          // 'orders.refund'
    public function value(): string;          // 'admin.orders.refund'
}

final readonly class PermissionPattern      // только в выдачах: 'admin.orders.*', 'admin.**', или точный ключ
{
    public static function from(PermissionKey|string|UnitEnum $pattern, ?string $panel = null): self;
    public function panel(): string;
    public function covers(PermissionKey $key): bool;
    public function isExact(): bool;
    public function isPanelWide(): bool;
}

final readonly class RoleKey { public static function from(string $value): self; public static function of(string $panel, string $key): self; public function panel(): string; public function key(): string; }
final readonly class SubjectRef { public static function of(string $type, int|string $id): self; public function type(): string; public function id(): string; public function equals(self $o): bool; }
final readonly class ContextRef { public static function of(string $type, int|string $id): self; public static function global(): self; public function isGlobal(): bool; public function key(): string; public function equals(self $o): bool; }
final readonly class AnyContext { public static function all(): self; }     // «во всех контекстах» — только для отзыва

final readonly class Actor
{
    public static function subject(SubjectRef $ref): self;
    public static function system(string $reason): self;
    public function isSystem(): bool;
    public function ref(): ActorRef;
}
final readonly class ActorRef { public const string SYSTEM_TYPE = 'azguard:system'; public function __construct(public ?string $type, public ?string $id, public ?string $reason = null) {} }

final readonly class AccessRequest
{
    public static function for(SubjectRef $subject, PermissionKey $permission): self;
    public function in(?ContextRef $context): self;       // null = текущий контекст панели или глобальный
    public function about(?object $resource): self;       // для ограничений
    public function traced(bool $trace = true): self;
}

enum Effect: string { case Allow = 'allow'; case Deny = 'deny'; case NotApplicable = 'not_applicable'; }
enum DecisionReason: string
{
    case Granted = 'granted'; case Superadmin = 'superadmin'; case NotGranted = 'not_granted';
    case NotApplicable = 'not_applicable'; case ContextRequired = 'context_required';
    case ContextNotAccepted = 'context_not_accepted'; case RestrictionDenied = 'restriction_denied';
    case RestrictionError = 'restriction_error'; case SourceError = 'source_error';
}

final readonly class Decision
{
    public Effect $effect; public DecisionReason $reason; public StateToken $state;
    public ?string $component;                                    // ключ ограничения или источника при отказе/ошибке
    /** @var list<Contribution> */ public array $contributions;   // при trace
    public function allowed(): bool;
    public function toGateResult(): ?bool;
}

final readonly class Contribution
{
    public PermissionPattern $pattern; public string $source;     // 'azguard/roles'
    public ?RoleKey $role; public ContextRef $context; public ?DateTimeImmutable $expiresAt;
    public ?string $grantId;                                       // id строки выдачи
    /** @return array<string, mixed> только decisionAttributes */ public function attributes(): array;
}

final readonly class StateToken { public string $panel; public int $revision; public int $generation; public string $policyFingerprint; }
final readonly class DecisionSet implements Countable, IteratorAggregate { public function get(int $i): Decision; public function allowedIndexes(): array; public StateToken $state; }
final readonly class PermissionSet { public function patterns(): array; public function covers(PermissionKey $key): bool; public function validUntil(): ?DateTimeImmutable; }
```

`Kernel\` не зависит от Laravel; `ContextRef` из модели строит Laravel-слой (`Contexts::fromModel($model)`), и все
методы с `ContextRef|Model` принимают модель напрямую.

## 3. Панели

```php
namespace AzGuard\Panels;

abstract class PanelProvider extends \Illuminate\Support\ServiceProvider   // как в Filament
{
    abstract public function panel(PanelBuilder $panel): PanelBuilder;
}

final class PanelBuilder
{
    // идентичность
    public function id(string $id): static;                       // ^[a-z0-9][a-z0-9-]{0,63}$
    public function label(string $label): static;
    public function description(?string $description): static;
    // каталог и роли
    public function permissions(string ...$enumsOrClasses): static;
    public function catalogBuilders(string|PermissionCatalogBuilder ...$builders): static;
    public function grantSources(string|GrantSource ...$sources): static;
    public function roles(string ...$roleDefinitions): static;
    public function databaseRoles(bool $allowed = true): static;
    // субъекты и контексты
    public function subjects(?string $guard = null, array $models = [], ?string $resolver = null, ?string $directory = null): static;
    public function contexts(ContextPolicy $policy): static;
    public function contextResolvers(string|ContextResolver ...$resolvers): static;
    public function membership(string|ContextMembership $membership): static;
    // хранилище и модели
    public function storage(string|Storage $storage): static;                 // имя из конфига или Storage::own(...)
    public function models(?string $role = null, ?string $rolePermission = null, ?string $assignment = null, ?string $directGrant = null): static;
    public function decisionAttributes(array $assignment = [], array $directGrant = []): static;
    // проверка
    public function restrict(string|Restriction ...$restrictions): static;
    public function prepare(string|PreparesAccess ...$pipes): static;
    public function observe(string|ObservesAccess ...$observers): static;
    public function gate(GateMode $mode = GateMode::Authoritative, SuperadminScope $superadminScope = SuperadminScope::Owned): static;
    public function superadmin(string|SuperadminPolicy|null $policy = null, bool $bypassRestrictions = false): static;
    // изменения
    public function delegation(string|DelegationPolicy $policy): static;
    public function administeredBy(string $panelId): static;       // кто управляет правами этой панели (D23); по умолчанию — она сама
    public function onChange(string|object $pipe, ChangeStage $stage): static;   // Validate | Intercept | Record | Notify
    // кэш и консистентность
    public function cache(?string $store = null, ?int $ttl = null, ?int $generation = null): static;
    public function consistency(Reads $reads = Reads::Primary, StateRefresh $refresh = StateRefresh::Request): static;
    // плагины
    public function plugin(Plugin $plugin): static;
    public function plugins(array $plugins): static;
    public function withoutPlugin(string $pluginId): static;
    public function hasPlugin(string $pluginId): bool;
    public function getPlugin(string $pluginId): Plugin;
    // диагностика
    public function doctorChecks(string|DoctorCheck ...$checks): static;
    // оформление для UI
    public function presentation(array $options): static;
}

final readonly class Panel                                    // результат сборки, после заморозки неизменяем
{
    public function id(): string;
    public function label(): string;
    public function settings(): PanelSettings;                   // эффективные значения (D45), с источником каждого
    public function storage(): Storage;
    public function contextPolicy(): ContextPolicy;
    /** @return list<string> */ public function pluginIds(): array;
    public function plugin(string $id): Plugin;
}

interface PanelRegistry   // AzGuard\Contracts\Panels\PanelRegistry
{
    public function get(string $id): Panel;                      // @throws UnknownPanelException
    public function find(string $id): ?Panel;
    /** @return array<string, Panel> */ public function all(): array;
    public function register(string $providerClass): void;       // до заморозки; дубликат id → DuplicatePanelException
    public function replace(string $providerClass): void;        // до заморозки
    public function configure(string $id, Closure $callback): void; // до заморозки; неизвестная панель → ошибка при сборке
    public function isFrozen(): bool;
}
```

## 4. Хранилище и модели

```php
namespace AzGuard\Storage;

final readonly class Storage
{
    public static function named(string $name): self;                         // из azguard.storages.{name}
    public static function own(string $prefix, ?string $connection = null, ?string $hostKeys = null): self;
    public function name(): string;
    public function connection(): ?string;
    public function tablePrefix(): string;
    public function hostKeys(): string;
}
```

Базовые модели (`Storage\Models\Role`, `RolePermission`, `RoleAssignment`, `DirectGrant`) — `@api` для чтения и
наследования. Идентификационные колонки и методы (`panel`, `key`, `subject_*`, `context_*`, `role_id`, `permission`,
`expires_at`, `granted_by_*`) — `final` аксессоры; наследник добавляет поля, касты, связи, scopes и может объявить:

```php
public static function azguardRules(): array;          // правила валидации своих полей для шага «Проверка»
```

Экземпляры моделей панели создаёт и находит только `Storage` панели (статические запросы к моделям AzGuard вне
`Storage\` запрещены arch-тестом; для чтения хостом — `AzGuard::panel($id)->for($s)->assignments()` и
`->storage()->query(RoleAssignment::class)`, который подставляет таблицу/соединение хранилища).

## 5. `PanelAuthorizer` и глобальный `Authorizer`

```php
namespace AzGuard\Contracts\Authorization;

interface Authorizer                                   // глобальный: находит панель по ключу
{
    public function decide(AccessRequest $request): Decision;
    public function allows(AccessRequest $request): bool;
}

interface PanelAuthorizer extends Authorizer           // AzGuard::panel('admin')
{
    public function definition(): Panel;
    public function check(mixed $subject, PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null): bool;
    /** @param iterable<AccessRequest> $requests */
    public function decideMany(iterable $requests): DecisionSet;
    public function explain(AccessRequest $request): Explanation;
    public function for(mixed $subject): SubjectAccess;
    public function manage(): AccessManager;
    public function catalog(): PermissionCatalog;
    public function visibility(): Visibility;
    public function state(): StateToken;
}
```

Ключ чужой панели в `PanelAuthorizer` → `PanelMismatchException` (явная ошибка, не молчаливый отказ).

## 6. Handle субъекта

```php
final readonly class SubjectAccess     // AzGuard::panel('admin')->for($user)
{
    public function in(ContextRef|Model|null $context): self;
    public function can(PermissionKey|string|UnitEnum $permission, ?object $resource = null): bool;
    public function decide(PermissionKey|string|UnitEnum $permission, ?object $resource = null): Decision;
    public function permissions(): PermissionSet;
    /** @param list<PermissionKey|string|UnitEnum> $permissions @return array<string, bool> */
    public function abilities(array $permissions): array;          // для фронтенда
    /** @return list<RoleKey> */ public function roles(): array;
    public function hasRole(RoleKey|string $role): bool;
    public function isSuperadmin(): bool;
    /** @return Collection<int, RoleAssignment> модели панели, со своими полями */ public function assignments(): Collection;
    /** @return Collection<int, DirectGrant> */ public function directGrants(): Collection;
    public function ref(): SubjectRef;
}
```

## 7. `AccessManager` — единственный вход для изменений

```php
namespace AzGuard\Contracts\Administration;

interface AccessManager                    // AzGuard::panel('admin')->manage()
{
    public function actingAs(Model|Authenticatable|SubjectRef $actor): static;
    public function asSystem(string $reason): static;
    public function withReason(string $reason): static;
    public function withCorrelationId(string $id): static;

    public function assignRole(mixed $subject, RoleKey|string|class-string $role, ContextRef|Model|null $context = null, ?DateTimeInterface $expiresAt = null, array $attributes = []): ChangeResult;
    public function revokeRole(mixed $subject, RoleKey|string|class-string $role, ContextRef|Model|AnyContext|null $context = null): ChangeResult;
    /** @param list<RoleKey|string> $roles */
    public function syncRoles(mixed $subject, array $roles, ContextRef|Model|null $context = null): ChangeResult;

    public function grantPermission(mixed $subject, PermissionPattern|string|UnitEnum $pattern, ContextRef|Model|null $context = null, ?DateTimeInterface $expiresAt = null, array $attributes = []): ChangeResult;
    public function revokePermission(mixed $subject, PermissionPattern|string|UnitEnum $pattern, ContextRef|Model|AnyContext|null $context = null): ChangeResult;
    public function revokePermissions(mixed $subject, ContextRef|Model|AnyContext|null $context = null): ChangeResult;

    public function createRole(string $key, ?string $label = null, int $rank = 0, array $attributes = []): ChangeResult;
    public function updateRole(RoleKey|string $role, array $changes): ChangeResult;              // label, description, rank, is_superadmin, свои поля
    public function deleteRole(RoleKey|string $role): ChangeResult;
    public function setRolePermissions(RoleKey|string $role, PermissionSelection $selection): ChangeResult;

    public function apply(PendingChange $pending): ChangeResult;                                 // применить отложенное (подтверждение)
    public function pruneExpired(?DateTimeInterface $before = null): ChangeResult;               // только system
    public function resetState(): ChangeResult;                                                   // только system
}

final readonly class ChangeResult
{
    public ChangeStatus $status;               // Applied | Pending | Unchanged
    public ?PendingChange $pending;            // при Pending — что ждёт подтверждения (создал плагин)
    public ?Model $record;                     // затронутая модель панели (Applied)
    public StateToken $state;
}
```

- Без `actingAs()`/`asSystem()` → `MissingActorException`.
- Каждая операция — пайплайн изменений (D49): полномочия → проверка (в т. ч. `attributes` по `azguardRules()` и
  шагам плагинов; неизвестное поле → ошибка) → перехват → запись одной транзакцией → журнал → уведомления.
- `PermissionSelection::replace(array $patterns, ?string $expectedFingerprint)` / `::toggle(string $pattern, bool $present, ?string $expectedFingerprint)`
  — защита от устаревшей формы (`StaleSelectionException`), как в нынешнем синхронизаторе.

## 8. Каталог

```php
interface PermissionCatalog     // AzGuard\Contracts\Catalog
{
    /** @return list<PermissionDefinition> */ public function all(): array;
    public function find(PermissionKey $key): ?PermissionDefinition;
    public function owns(PermissionKey $key): bool;
    /** @return array<string, list<PermissionDefinition>> */ public function groups(): array;
    public function fingerprint(): string;
}

final readonly class PermissionDefinition     // AzGuard\Catalog
{
    public PermissionKey $key; public ?string $label; public ?string $group; public ?string $description;
    public bool $dynamic; public string $contributedBy;   // 'azguard/enum', 'acme/blog', 'azguard/filament'
    /** @var array<string, scalar> */ public array $meta;
}
```

`#[Describe(label:, group:, description:)]` — на enum case. Трейт `BelongsToPanels` на enum: `->in('admin'): PermissionKey`.

## 9. Host-модель и видимость

```php
interface AzGuardSubject   // AzGuard\Contracts
{
    public function hasPermission(PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?string $panel = null, ?object $resource = null): bool;
    public function hasRole(RoleKey|string $role, ContextRef|Model|null $context = null): bool;
    public function permissions(string $panel, ContextRef|Model|null $context = null): PermissionSet;
    public function isSuperadmin(string $panel): bool;
    public function azguardRef(): SubjectRef;
}

trait HasAzGuard { /* реализует AzGuardSubject через AzGuard::panel(...)->for($this) */ }

trait ContextAware   // на модели-контексте (Project, Store)
{
    public function scopeVisibleTo(Builder $query, mixed $subject, PermissionKey|string|UnitEnum $permission): void;
    public function azguardContext(): ContextRef;
}
```

`hasPermission()` не глотает ошибки конфигурации (было `checkPermission()` с `catch Throwable`).

## 10. Исключения

| Класс | Код | Родитель | Когда |
|---|---|---|---|
| `InvalidConfigurationException` | `invalid_configuration` | `ConfigurationException` | boot-проверки |
| `DuplicatePanelException` / `UnknownPanelException` / `RegistryFrozenException` | `duplicate_panel` / `unknown_panel` / `registry_frozen` | `DefinitionException` | реестр панелей |
| `AmbiguousPanelException` | `ambiguous_panel` | `DefinitionException` | enum в нескольких панелях без указания панели |
| `PanelMismatchException` | `panel_mismatch` | `DefinitionException` | ключ чужой панели в `PanelAuthorizer` |
| `DuplicatePermissionException` / `DuplicateRoleException` | `duplicate_permission` / `duplicate_role` | `DefinitionException` | коллизии вкладов (с именами плагинов) |
| `RoleDefinitionException` | `role_definition_invalid` | `DefinitionException` | sync/запись code-роли |
| `MissingPluginDependencyException` / `PluginConflictException` | `plugin_dependency_missing` / `plugin_conflict` | `PluginException` | сборка панели |
| `InvalidPanelIdException`, `InvalidPermissionKeyException`, `UnqualifiedPermissionException`, `InvalidRoleKeyException`, `InvalidContextException` | `invalid_panel_id`, `invalid_permission_key`, `unqualified_permission`, `invalid_role_key`, `invalid_context` | `InvalidIdentityException` | грамматика |
| `StorageMismatchException` | `storage_mismatch` | `StorageException` | модель панели не совпадает с хранилищем |
| `UnsupportedDirectWriteException` | `unsupported_direct_write` | `StorageException` | запись модели вне пайплайна |
| `UnknownPermissionException`, `UnknownRoleException`, `UnsupportedContextException`, `ImmutableRoleException`, `StaleSelectionException`, `MissingActorException`, `InvalidChangeAttributesException` | `unknown_permission`, `unknown_role`, `unsupported_context`, `immutable_role`, `stale_selection`, `missing_actor`, `invalid_change_attributes` | `AccessManagementException` | пайплайн изменений |
| `AccessManagementDeniedException` | `delegation_denied` | `Illuminate\Auth\Access\AuthorizationException` | полномочия (403) |
| `GrantSourceException` | `source_failed` | `AuthorizationEngineException` | ошибка источника (наружу) |

Отказ доступа — `Illuminate\Auth\Access\AuthorizationException`; `$e->response()->code()` = `DecisionReason::value`.
