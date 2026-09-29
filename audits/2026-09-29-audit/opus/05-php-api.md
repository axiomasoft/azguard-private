# 05 — Публичный PHP API

Решения: [D05](02-decisions.md#d05)–[D11](02-decisions.md#d11), [D14](02-decisions.md#d14), [D19](02-decisions.md#d19),
[D22](02-decisions.md#d22), [D45](02-decisions.md#d45)–[D55](02-decisions.md#d55).
Имена, типы и смысл сигнатур обязательны; порядок необязательных параметров можно уточнить в спецификации пункта
плана. Всё ниже — `@api`, если не сказано иное. Контракты для расширения (источники, хуки, плагины) —
[06](06-extension-points.md).

## 0. Как это выглядит целиком

```php
// Личный кабинет: права из кода и политик, ничего не редактируется; панель по умолчанию для User
final class CabinetPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('cabinet')->label('Личный кабинет')->default()
            ->subjects(User::class, guard: 'web')
            ->middleware(['web', 'auth:web'])
            ->permissions(CabinetPermission::class)
            ->grantToAll(CabinetPermission::ProfileView, CabinetPermission::OrdersList)
            ->policies(OrderPolicy::class)                         // orders.view решает политика
            ->relation(Project::class, via: 'members', role: 'pivot.role')
            ->roles(ProjectEditorRole::class)                      // роль, которую даёт связь
            ->contexts(ContextPolicy::inherit(Project::class))
            ->database(false)                                      // ничего не редактируется
            ->superAdmin(false);
    }
}

// Кабинет продавца: автоматическая роль + доступ к своим магазинам
final class SellerPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('seller')
            ->subjects(User::class, guard: 'web')
            ->entry(SellerPermission::Access)                      // войти может только продавец
            ->permissions(SellerPermission::class)
            ->roles(SellerRole::class)                             // appliesTo(): $user->stores()->exists()
            ->relation(Store::class, via: 'staff', role: 'pivot.role')
            ->database()                                           // владелец магазина выдаёт доп. права
            ->contexts(ContextPolicy::inherit(Store::class)->requireMembership(StoreStaff::class));
    }
}

// Админка: роли и права в БД, редактируются в Filament
final class AdminPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('admin')
            ->subjects(User::class, guard: 'web')
            ->entry(AdminPermission::Access)
            ->permissions(AdminPermission::class, OrderPermission::class)
            ->roles(ManagerRole::class)                            // роль из кода, назначается вручную
            ->database()
            ->models(assignment: AdminRoleAssignment::class)       // своё поле department_id
            ->superAdmin(role: 'superadmin')
            ->plugin(AuditPlugin::make());
    }
}

// Проверки — панель по умолчанию (cabinet) или панель маршрута
$user->hasPermissionTo(CabinetPermission::OrdersView, on: $order);
$user->can('projects.edit', $project);
$user->inPanel('admin')->hasRole('manager');
$user->hasPermissionTo('admin:orders.refund');

// Изменения
$user->inPanel('seller')->assignRole('store-manager', on: $store);
$user->inPanel('admin')->assignRole('support', fields: ['department_id' => 7], expiresAt: now()->addMonth());
AzGuard::panel('admin')->roles()->create('support', label: 'Поддержка', permissions: [OrderPermission::View]);
```

## 1. Модель: трейт `HasAzGuard`

```php
namespace AzGuard\Concerns;

trait HasAzGuard            // реализует AzGuard\Contracts\AzGuardSubject; подходит любой модели
{
    // Права (панель по правилу D05)
    public function hasPermissionTo(string|UnitEnum $permission, Model|ContextRef|null $on = null): bool;
    /** @param list<string|UnitEnum> $permissions */
    public function hasAnyPermission(array $permissions, Model|ContextRef|null $on = null): bool;
    public function hasAllPermissions(array $permissions, Model|ContextRef|null $on = null): bool;
    public function getAllPermissions(Model|ContextRef|null $on = null): PermissionSet;
    /** @return Collection<int, string> */ public function getPermissionNames(Model|ContextRef|null $on = null): Collection;

    // Роли
    public function hasRole(string|UnitEnum|array $roles, Model|ContextRef|null $on = null): bool;   // массив = любая
    public function hasAnyRole(array $roles, Model|ContextRef|null $on = null): bool;
    public function hasAllRoles(array $roles, Model|ContextRef|null $on = null): bool;
    /** @return Collection<int, string> */ public function getRoleNames(Model|ContextRef|null $on = null): Collection;

    public function isSuperAdmin(): bool;

    // Изменения (механики с хранением в БД)
    public function assignRole(string|UnitEnum|array $roles, Model|ContextRef|null $on = null, ?DateTimeInterface $expiresAt = null, array $fields = []): ChangeResult;
    public function removeRole(string|UnitEnum $role, Model|ContextRef|AnyContext|null $on = null): ChangeResult;
    public function syncRoles(array $roles, Model|ContextRef|null $on = null): ChangeResult;
    public function givePermissionTo(string|UnitEnum|array $permissions, Model|ContextRef|null $on = null, ?DateTimeInterface $expiresAt = null, array $fields = []): ChangeResult;
    public function revokePermissionTo(string|UnitEnum $permission, Model|ContextRef|AnyContext|null $on = null): ChangeResult;
    public function syncPermissions(array $permissions, Model|ContextRef|null $on = null): ChangeResult;

    // Панели
    public function inPanel(string $panel): SubjectAccess;        // те же методы в указанной панели
    public function azguard(): SubjectPanels;                     // все панели субъекта
    public function azguardDefaultPanel(): ?string;               // можно переопределить; по умолчанию null → D11
    public function azguardRef(): SubjectRef;
}
```

- `$on` — сущность: контекст (тип объявлен панелью) или ресурс для политик и ограничений ([D16](02-decisions.md#d16)).
- Имя роли или права без панели относится к панели по правилу D05; полное имя (`admin:manager`) — к указанной.
- `$user->can('orders.view', $order)` работает через Gate ([D26](02-decisions.md#d26)).
- Ошибки конфигурации не глотаются: неизвестное право → `UnknownPermissionException`, неопределимая панель →
  `PanelNotResolvedException`.

```php
final readonly class SubjectAccess          // $user->inPanel('admin'), AzGuard::panel('admin')->for($user)
{
    // все методы трейта из групп «Права», «Роли», «Суперадмин», «Изменения» — в этой панели
    public function panel(): Panel;
    public function decide(string|UnitEnum $permission, Model|ContextRef|null $on = null): Decision;
    /** @return array<string, bool> */ public function abilities(array $permissions, Model|ContextRef|null $on = null): array;  // для фронтенда
    /** @return Collection<int, RoleAssignment> модели панели со своими полями */ public function assignments(Model|ContextRef|AnyContext|null $on = null): Collection;
    /** @return Collection<int, DirectPermission> */ public function directPermissions(Model|ContextRef|AnyContext|null $on = null): Collection;
}

final readonly class SubjectPanels          // $user->azguard()
{
    /** @return list<string> */ public function panels(): array;               // панели, принимающие модель
    public function default(): ?string;
    public function panel(string $id): SubjectAccess;
    /** @return array<string, PermissionSet> */ public function permissions(): array;  // по панелям
    /** @return array<string, list<string>> */ public function roles(): array;
}
```

## 2. Панель: `AzGuard::panel()`

```php
namespace AzGuard\Contracts;

interface PanelAccess                         // AzGuard::panel('admin')
{
    public function definition(): Panel;
    public function for(Model|Authenticatable|SubjectRef $subject): SubjectAccess;
    public function roles(): RoleManager;
    public function schema(): PanelSchema;
    public function decide(AccessRequest $request): Decision;
    /** @param iterable<AccessRequest> $requests */
    public function decideMany(iterable $requests): DecisionSet;
    public function explain(AccessRequest $request): Explanation;
    public function catalog(): PermissionCatalog;
    public function visibility(): Visibility;
    public function state(): StateToken;
    public function touch(): StateToken;       // новая версия вручную (данные автоматических ролей изменились)
}

interface RoleManager                         // только роли в БД; роли из кода — в коде
{
    /** @return list<RoleSchema> роли из кода и из БД */ public function all(): array;
    public function find(string $key): ?RoleSchema;
    public function create(string $key, ?string $label = null, array $permissions = [], array $fields = []): ChangeResult;
    public function update(string $key, array $changes): ChangeResult;                 // label, description, свои поля
    public function syncPermissions(string $key, array $permissions, ?string $expectedFingerprint = null): ChangeResult;
    public function delete(string $key): ChangeResult;                                 // + назначения этой роли, одной транзакцией
    public function renameKey(string $from, string $to): ChangeResult;                 // для ролей из кода (formerKeys)
}
```

`expectedFingerprint` защищает от сохранения устаревшей формы: если права роли успели поменять, → `StaleSelectionException`.

## 3. Фасад

```php
/**
 * @method static PanelAccess panel(string $id)
 * @method static array<string, Panel> panels()
 * @method static Panel|null currentPanel()
 * @method static void registerPanel(class-string<PanelProvider> $provider)                // до заморозки
 * @method static void configurePanel(string $id, Closure(PanelBuilder): mixed $callback)  // до заморозки
 * @method static void configurePanels(Closure(PanelBuilder): mixed $callback)            // для всех панелей
 * @method static bool check(mixed $subject, string|UnitEnum $permission, Model|ContextRef|null $on = null)
 * @method static void authorize(mixed $subject, string|UnitEnum $permission, Model|ContextRef|null $on = null)
 * @method static mixed withinContext(Model|ContextRef $context, Closure $callback)
 * @method static ContextRef|null currentContext()
 * @method static mixed actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure $callback)  // кто выдаёт; строка — system с причиной
 */
final class AzGuard extends Facade
{
    public static function fake(): AzGuardFake;
}
```

`$subject` — модель, `Authenticatable` или `SubjectRef`. `check`/`authorize` выбирают панель по правилу D05.
`actingAs()` необязателен: без него актор — текущий пользователь или system в консоли ([D23](02-decisions.md#d23)).
Пример: `AzGuard::actingAs('import: crm', fn () => $user->assignRole('manager'))`.

## 4. Описание панели: `PanelProvider` и `PanelBuilder`

```php
namespace AzGuard\Panels;

abstract class PanelProvider extends \Illuminate\Support\ServiceProvider   // как в Filament
{
    abstract public function panel(PanelBuilder $panel): PanelBuilder;
}

final class PanelBuilder
{
    // идентичность
    public function id(string $id): static;                               // ^[a-z0-9][a-z0-9-]{0,63}$
    public function label(string $label): static;
    public function description(?string $description): static;
    public function default(bool $default = true): static;                // панель по умолчанию для своих моделей
    // субъекты и вход
    public function subjects(string|array $models, ?string $guard = null, ?string $directory = null): static;
    public function middleware(array $middleware): static;                // что выполняется при входе в панель
    public function entry(string|UnitEnum|null $permission): static;       // право входа (суперадмин проходит)
    public function onDenied(Closure|string|null $response): static;       // 403 по умолчанию; редирект и т. п.
    // права
    public function permissions(string ...$enumsOrClasses): static;
    public function catalogBuilders(string|PermissionCatalogBuilder ...$builders): static;
    // механики (D52–D53)
    public function grantToAll(string|UnitEnum ...$permissions): static;
    public function roles(string ...$codeRoles): static;
    public function policies(string ...$policyClasses): static;
    public function discoverPolicies(string $path, ?string $namespace = null): static;
    public function gates(array $map): static;                             // [Perm::X => 'laravel-ability']
    public function database(bool $enabled = true, bool $roles = true, bool $directPermissions = true): static;
    public function relation(string $model, string $via, string $role, ?Closure $scope = null): static;
    public function source(string|GrantSource ...$sources): static;
    // контексты
    public function contexts(ContextPolicy $policy): static;
    public function contextResolvers(string|ContextResolver ...$resolvers): static;
    // суперадмин и хуки
    public function superAdmin(string|false|null $role = 'superadmin', Closure|string|null $when = null): static;
    public function before(Closure|string|BeforeHook ...$hooks): static;
    public function restrict(string|Restriction ...$restrictions): static;
    public function after(Closure|string|AfterHook ...$hooks): static;
    public function changing(Closure|string|ChangingHook ...$hooks): static;
    public function changed(Closure|string|ChangedHook ...$hooks): static;
    // хранилище и модели
    public function storage(string|Storage $storage): static;
    public function models(?string $role = null, ?string $rolePermission = null, ?string $assignment = null, ?string $directPermission = null): static;
    public function decisionFields(array $assignment = [], array $directPermission = []): static;
    public function fields(FieldTarget $target, Field ...$fields): static;   // поля от плагина (без своей модели)
    // Gate, кэш, консистентность
    public function gate(GateMode $mode = GateMode::Authoritative): static;
    public function cache(?string $store = null, ?int $ttl = null, ?int $generation = null): static;
    public function consistency(Reads $reads = Reads::Primary, StateRefresh $refresh = StateRefresh::Request): static;
    // плагины и прочее
    public function plugin(Plugin $plugin): static;
    public function plugins(array $plugins): static;
    public function withoutPlugin(string $pluginId): static;
    public function doctorChecks(string|DoctorCheck ...$checks): static;
    public function presentation(array $options): static;                // группы, иконки, подписи для UI
}

final readonly class Panel                    // после сборки неизменяем
{
    public function id(): string;
    public function label(): string;
    public function isDefault(): bool;
    /** @return list<class-string<Model>> */ public function subjectModels(): array;
    public function accepts(Model|SubjectRef $subject): bool;
    public function settings(): PanelSettings;                 // итоговые значения и откуда взято каждое
    public function storage(): Storage;
    public function contextPolicy(): ContextPolicy;
    public function isWritable(): bool;                        // есть механика, принимающая изменения
    /** @return list<string> */ public function pluginIds(): array;
}

interface PanelRegistry                       // AzGuard\Contracts\Panels
{
    public function get(string $id): Panel;                    // UnknownPanelException
    public function find(string $id): ?Panel;
    /** @return array<string, Panel> */ public function all(): array;
    /** @return list<Panel> */ public function forModel(string $modelClass): array;
    public function defaultFor(string $modelClass): ?Panel;
    public function register(string $providerClass): void;     // до заморозки
    public function replace(string $providerClass): void;
    public function configure(string $id, Closure $callback): void;
    public function configureAll(Closure $callback): void;
    public function isFrozen(): bool;
}
```

`PanelResolver` (internal) — единственная реализация правила выбора панели ([09 §1](09-authorization-semantics.md#1-как-выбирается-панель)).

## 5. Значения ядра (`AzGuard\Kernel\…`)

```php
final readonly class PermissionKey implements Stringable, JsonSerializable
{
    public static function of(string $panel, string $local): self;
    public static function parse(string $full): self;                  // 'admin:orders.view'
    public function panel(): string;
    public function local(): string;                                   // 'orders.view'
    public function full(): string;                                    // 'admin:orders.view'
}

final readonly class PermissionPattern                                 // только в выдачах: 'orders.*', 'orders.**' или точное имя
{
    public static function of(string $panel, string $local): self;
    public function covers(PermissionKey $key): bool;
    public function isExact(): bool;
}

final readonly class RoleKey { public static function of(string $panel, string $key): self; public static function parse(string $full): self; public function panel(): string; public function key(): string; }
final readonly class SubjectRef { public static function of(string $type, int|string $id): self; public function type(): string; public function id(): string; public function equals(self $o): bool; }
final readonly class ContextRef { public static function of(string $type, int|string $id): self; public static function global(): self; public function isGlobal(): bool; public function key(): string; }
final readonly class AnyContext { public static function all(): self; }    // «во всех сущностях» — только для отзыва

final readonly class ActorRef { public const string SYSTEM_TYPE = 'azguard:system'; public ?string $type; public ?string $id; public ?string $reason; }

final readonly class AccessRequest
{
    public static function for(SubjectRef $subject, PermissionKey $permission): self;
    public function on(?ContextRef $context, ?object $resource = null): self;
    public function traced(bool $trace = true): self;
}

enum Effect: string { case Allow = 'allow'; case Deny = 'deny'; case NotApplicable = 'not_applicable'; }

enum DecisionReason: string
{
    case Granted = 'granted';               case SuperAdmin = 'super_admin';        case Hook = 'hook';
    case Policy = 'policy';                 case NotGranted = 'not_granted';        case NotApplicable = 'not_applicable';
    case ContextRequired = 'context_required'; case ContextNotAccepted = 'context_not_accepted';
    case Restricted = 'restricted';         case SourceError = 'source_error';      case PolicyError = 'policy_error';
    case RestrictionError = 'restriction_error'; case HookError = 'hook_error';
}

final readonly class Decision
{
    public Effect $effect; public DecisionReason $reason; public StateToken $state;
    public ?string $component;                                     // ключ хука, политики, ограничения, источника
    /** @var list<Contribution> */ public array $contributions;    // при трассировке
    public function allowed(): bool;
    public function toGateResult(): ?bool;
}

final readonly class Contribution                                  // «право есть, потому что…»
{
    public PermissionPattern $pattern; public string $source;      // 'azguard/database', 'azguard/code', 'azguard/relations'
    public ?RoleKey $role; public ContextRef $context; public ?DateTimeImmutable $expiresAt;
    /** @return array<string, mixed> только decisionFields */ public function fields(): array;
}

final readonly class StateToken { public string $panel; public int $version; public int $generation; public string $fingerprint; }
final readonly class DecisionSet implements Countable, IteratorAggregate { public function get(int $i): Decision; public StateToken $state; }
final readonly class PermissionSet { public function patterns(): array; public function covers(PermissionKey $key): bool; public function validUntil(): ?DateTimeImmutable; }
```

`Kernel\` не зависит от Laravel. Модели в `ContextRef` переводит Laravel-слой; все методы с `Model|ContextRef` принимают
модель напрямую.

## 6. Роли в коде

```php
namespace AzGuard\Roles;

abstract class CodeRole
{
    abstract public function key(): string;                    // 'manager'
    public function label(): string;                           // по умолчанию из key()
    /** @return list<UnitEnum|string> */ abstract public function permissions(): array;   // enum или локальные имена, шаблоны 'orders.*'
    /** @return list<string> */ public function formerKeys(): array;                        // старые ключи после переименования
    public function assignable(): bool;                        // можно ли назначать вручную; false для автоматических
}

interface AssignedAutomatically                                // роль, которую получает каждый, кто подходит под правило
{
    public function appliesTo(Model $subject, ?ContextRef $context): bool;
}

final class SellerRole extends CodeRole implements AssignedAutomatically
{
    public function key(): string { return 'seller'; }
    public function permissions(): array { return [SellerPermission::Access, SellerPermission::ProductsManage]; }
    public function appliesTo(Model $subject, ?ContextRef $context): bool { return $subject->stores()->exists(); }
}
```

`appliesTo()` вызывается при сборе прав и кэшируется вместе с набором прав на время запроса (`Volatility::Request`).
Если результат зависит от данных, которые меняются редко, роль может объявить `Volatility::Stable`; тогда приложение
само сбрасывает версию панели при их изменении (`AzGuard::panel($id)->touch()`).

## 7. Политики

```php
namespace AzGuard\Policies;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Decides
{
    public function __construct(public UnitEnum|string $permission, public bool $assignable = false) {}
}

trait ConsultsGrants
{
    // спрашивает только механики, дающие права (без политик и хуков) — рекурсии нет
    protected function granted(string|UnitEnum $permission, Model $subject, Model|ContextRef|null $on = null): bool;
}
```

Метод политики получает субъекта и ресурс из `on:` (если есть) и возвращает `bool`, `Response` или `null` («не знаю»
→ решают механики). Пример — [D53](02-decisions.md#d53).

## 8. Схема панели

```php
namespace AzGuard\Schema;

final readonly class PanelSchema implements JsonSerializable
{
    public string $panel; public string $label; public bool $writable;
    /** @return list<PermissionSchema> */ public function permissions(): array;
    /** @return array<string, list<PermissionSchema>> */ public function groups(): array;
    /** @return list<RoleSchema> */ public function roles(): array;
    /** @return list<FieldSchema> */ public function fields(FieldTarget $target): array;   // Role | Assignment | DirectPermission
    /** @return list<ContextTypeSchema> */ public function contexts(): array;
    /** @return list<SubjectTypeSchema> */ public function subjects(): array;
    public function toArray(): array;
}

final readonly class PermissionSchema
{
    public PermissionKey $key; public string $label; public ?string $group; public ?string $description;
    public bool $assignable;                 // можно выдать вручную (роль в БД, прямое право)
    public ?string $decidedBy;               // 'policy:App\Policies\OrderPolicy@view', 'gate:beta-access' или null
    /** @var list<string> */ public array $sources;   // механики, которые могут дать право
    /** @var list<string> */ public array $contextTypes;
}

final readonly class RoleSchema
{
    public RoleKey $key; public string $label;
    public RoleOrigin $origin;               // Code | Database
    public bool $editable;                   // можно менять права (только Database)
    public bool $assignable;                 // можно назначать вручную
    public bool $automatic;                  // назначается правилом
    /** @var list<PermissionPattern> */ public array $permissions;
}

final readonly class FieldSchema
{
    public string $name; public string $label; public string $type;   // string|int|bool|date|enum|model
    public array $rules; public ?array $options; public bool $inMeta; public string $contributedBy;
}
```

## 9. Изменения: что происходит при записи

- Все методы изменения (трейт, `SubjectAccess`, `RoleManager`, команды, Filament) создают `Change` и отправляют его в
  пайплайн изменений ([D49](02-decisions.md#d49)).
- `ChangeResult`: `status` (`Applied` | `Unchanged`), `record` (затронутая модель панели), `state` (новая версия).
- Повторная выдача того же — `Unchanged`, без события. Выдача с новым сроком или полями — обновление строки.
- Свои поля (`fields:`) проверяются по `azguardFields()` модели панели и хукам `changing`; неизвестное поле →
  `InvalidChangeFieldsException`.
- Отзыв во всех сущностях — `on: AnyContext::all()`.

## 10. Исключения

| Класс | Код | Родитель | Когда |
|---|---|---|---|
| `InvalidConfigurationException` | `invalid_configuration` | `ConfigurationException` | проверки при загрузке |
| `DuplicatePanelException`, `UnknownPanelException`, `RegistryFrozenException`, `DefaultPanelConflictException` | `duplicate_panel`, `unknown_panel`, `registry_frozen`, `default_panel_conflict` | `DefinitionException` | реестр панелей |
| `PanelNotResolvedException` | `panel_not_resolved` | `DefinitionException` | правило выбора панели не нашло панель |
| `AmbiguousPanelException` | `ambiguous_panel` | `DefinitionException` | enum подключён к нескольким панелям, панель не указана |
| `SubjectNotAcceptedException` | `subject_not_accepted` | `DefinitionException` | модель не является субъектом панели |
| `DuplicatePermissionException`, `DuplicateRoleException`, `DuplicatePolicyBindingException` | `duplicate_permission`, `duplicate_role`, `duplicate_policy_binding` | `DefinitionException` | коллизии вкладов (с именами плагинов) |
| `PluginDependencyMissingException`, `PluginConflictException` | `plugin_dependency_missing`, `plugin_conflict` | `PluginException` | сборка панели |
| `InvalidPanelIdException`, `InvalidPermissionKeyException`, `InvalidRoleKeyException`, `InvalidContextException` | `invalid_panel_id`, `invalid_permission_key`, `invalid_role_key`, `invalid_context` | `InvalidIdentityException` | грамматика |
| `StorageMismatchException`, `UnsupportedDirectWriteException` | `storage_mismatch`, `unsupported_direct_write` | `StorageException` | хранилище |
| `UnknownPermissionException`, `UnknownRoleException`, `PermissionNotAssignableException`, `RoleNotAssignableException`, `RoleNotEditableException`, `ContextNotAcceptedException`, `PanelNotWritableException`, `InvalidChangeFieldsException`, `StaleSelectionException`, `ChangeCancelledException` | `unknown_permission`, `unknown_role`, `permission_not_assignable`, `role_not_assignable`, `role_not_editable`, `context_not_accepted`, `panel_not_writable`, `invalid_change_fields`, `stale_selection`, `change_cancelled` | `ChangeException` | пайплайн изменений |

Отказ в доступе — `Illuminate\Auth\Access\AuthorizationException`; `$e->response()->code()` = `DecisionReason::value`.
Ошибки механик, политик и ограничений при проверке не выбрасываются наружу: они дают отказ с причиной
`source_error`/`policy_error`/`restriction_error`/`hook_error` и пишутся в лог.
