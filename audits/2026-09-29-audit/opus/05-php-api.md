# 05 — Публичный PHP API

Решения: [D05](02-decisions.md#d05)–[D11](02-decisions.md#d11), [D14](02-decisions.md#d14), [D19](02-decisions.md#d19),
[D22](02-decisions.md#d22), [D45](02-decisions.md#d45)–[D58](02-decisions.md#d58).
Имена, типы и смысл сигнатур обязательны; порядок необязательных параметров можно уточнить в спецификации пункта
плана. Всё ниже — `@api`, если не сказано иное. Контракты для расширения (источники, хуки, плагины) —
[06](06-extension-points.md).

## 0. Как это выглядит целиком

Каждая панель — папка ([D56](02-decisions.md#d56)): enum прав, политики и роли в ней находит `FolderSource`. Всё
остальное панель берёт из **источников**, перечисленных в `->sources([...])` ([D52](02-decisions.md#d52)).

```php
// app/Guards/Cabinet/CabinetGuardPanelProvider.php — личный кабинет: папка панели и связи, БД нет
final class CabinetGuardPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('cabinet')->label('Личный кабинет')->default()   // имена прав: cabinet.orders.view
            ->subjects([User::class], guard: 'web')
            ->middleware(['web', 'auth:web'])
            ->sources([
                RelationSource::make(Project::class, via: 'members', role: 'pivot.role'),   // роли editor/viewer — из Roles/
            ])
            ->contexts(ContextPolicy::inherit(Project::class));
            // папка app/Guards/Cabinet/ читается всегда; права «всем» — #[GrantedToAll] на кейсах enum
            // DatabaseSource нет — нет таблиц и редактирования
    }
}

// app/Guards/Seller/SellerGuardPanelProvider.php — кабинет продавца
final class SellerGuardPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('seller')                                         // имена прав: seller.orders.cancel (префикс по умолчанию)
            ->subjects([User::class], guard: 'web')
            ->entry('panel.access')                                // войти может только продавец (право даёт SellerRole)
            ->sources([
                RelationSource::make(Store::class, via: 'staff', role: 'pivot.role'),
                DatabaseSource::make()->rolesOnly(),               // владелец магазина выдаёт роли; отдельных прав не выдаём
            ])
            ->contexts(ContextPolicy::inherit(Store::class)->requireMembership(StoreStaff::viaRelation('staff')));
    }
}

// app/Guards/Admin/AdminGuardPanelProvider.php — админка: роли и права в БД, редактируются в Filament
final class AdminGuardPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('admin')->prefixed('backoffice')                  // свой префикс: backoffice.orders.refund
            ->subjects([User::class], guard: 'web')
            ->entry('panel.access')
            ->requireRouteChecks()                                 // у каждого действия — #[CheckPermission] или явный пропуск
            ->sources([
                DatabaseSource::make()
                    ->dynamicPermissions()                         // + права, созданные в админке
                    ->models(roleGrant: AdminRoleGrant::class),    // своё поле department_id
                GateSource::make()->map(ReportPermission::Export, 'export-enabled'),   // фича-флаг Laravel — второй уровень
                'ldap',                                            // свой источник по имени (app/Guards/Shared/Sources/LdapSource.php)
            ])
            ->restrictions([AccountLocked::class])                 // действует и на суперадмина
            ->changing([RequireReason::class])                     // pipe изменений: без причины выдать нельзя
            ->plugins([AuditTrailPlugin::make()->retention(days: 90)]);
            // SuperAdminRole, ManagerRole — в app/Guards/Admin/Roles/, найдутся сами
    }
}

// app/Guards/Shared/Sources/LdapSource.php — свой источник, доступен всем панелям по имени
#[AsSource('ldap')]
final class LdapSource implements Source, ProvidesGrants { /* группы LDAP → роли панели, 06 §2 */ }

// app/Providers/AppServiceProvider.php — общее для всех панелей
AzGuard::configurePanels(fn (PanelBuilder $panel) => $panel->roles([RootRole::class]));   // суперадмин «по флагу»

// Проверки — панель по умолчанию (cabinet), панель маршрута или явная
$user->hasPermission(OrderPermission::View, on: $order);        // enum кабинета: выдачи + OrderPolicy::view()
$user->can('update', $order);                                    // Laravel-стиль: домен объявил модель Order
$user->hasPermission('seller.orders.cancel', on: $store);       // префикс указывает на панель seller
$user->hasPermission('backoffice.orders.refund');               // свой префикс панели admin
$user->inPanel('admin')->hasRole('manager');
$user->hasPermission('admin:orders.refund');                    // полное имя работает всегда

// Изменения — через источник-писатель панели (DatabaseSource)
$user->inPanel('seller')->grantRole('store-manager', on: $store);
$user->inPanel('admin')->grantRole('support', fields: ['department_id' => 7], until: now()->addMonth());
$user->inPanel('admin')->revokeRole('support');
AzGuard::panel('admin')->roles()->create('support', label: 'Поддержка', permissions: ['orders.view', 'orders.refund']);
AzGuard::panel('admin')->permissions()->create('reports.quarterly', label: 'Отчёт за квартал');   // динамическое право
```

## 1. Модель: трейт `HasAzGuard`

```php
namespace AzGuard\Concerns;

trait HasAzGuard            // реализует AzGuard\Contracts\AzGuardSubject; подходит любой модели
{
    // Права (панель по правилу D05)
    public function hasPermission(string|UnitEnum $permission, Model|ContextRef|null $on = null): bool;
    /** @param list<string|UnitEnum> $permissions */
    public function hasAnyPermission(array $permissions, Model|ContextRef|null $on = null): bool;
    public function hasAllPermissions(array $permissions, Model|ContextRef|null $on = null): bool;
    public function permissionSet(Model|ContextRef|null $on = null): PermissionSet;
    /** @return Collection<int, string> */ public function permissionNames(Model|ContextRef|null $on = null): Collection;

    // Роли
    public function hasRole(string|UnitEnum|array $roles, Model|ContextRef|null $on = null): bool;   // массив = любая
    public function hasAnyRole(array $roles, Model|ContextRef|null $on = null): bool;
    public function hasAllRoles(array $roles, Model|ContextRef|null $on = null): bool;
    /** @return Collection<int, string> */ public function roleNames(Model|ContextRef|null $on = null): Collection;

    public function isSuperAdmin(Model|ContextRef|null $on = null): bool;   // есть ли роль с признаком суперадмина (D19)

    // Изменения (через источник-писатель панели, обычно DatabaseSource)
    public function grantRole(string|UnitEnum|array $roles, Model|ContextRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult;
    public function revokeRole(string|UnitEnum $role, Model|ContextRef|AnyContext|null $on = null): ChangeResult;
    public function syncRoles(array $roles, Model|ContextRef|null $on = null): ChangeResult;
    public function grantPermission(string|UnitEnum|array $permissions, Model|ContextRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult;
    public function revokePermission(string|UnitEnum $permission, Model|ContextRef|AnyContext|null $on = null): ChangeResult;
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
    /** @return Collection<int, RoleGrant> модели панели со своими полями */ public function roleGrants(Model|ContextRef|AnyContext|null $on = null): Collection;
    /** @return Collection<int, PermissionGrant> */ public function permissionGrants(Model|ContextRef|AnyContext|null $on = null): Collection;
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
    public function permissions(): PermissionManager;
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

interface PermissionManager                   // только при DatabaseSource::make()->dynamicPermissions(); права из enum — в коде
{
    /** @return list<PermissionSchema> статичные и динамические */ public function all(): array;
    public function create(string $name, ?string $label = null, ?string $group = null): ChangeResult;
    public function update(string $name, array $changes): ChangeResult;
    public function delete(string $name): ChangeResult;                                // + выдачи этого права, одной транзакцией
}

interface RoleManager                         // только роли в БД; роли из кода — в коде
{
    /** @return list<RoleSchema> роли из кода и из БД */ public function all(): array;
    public function find(string $key): ?RoleSchema;
    public function create(string $key, ?string $label = null, array $permissions = [], bool $superAdmin = false, array $fields = []): ChangeResult;
    public function update(string $key, array $changes): ChangeResult;                 // label, description, super_admin, свои поля
    public function syncPermissions(string $key, array $permissions, ?string $expectedFingerprint = null): ChangeResult;
    public function delete(string $key): ChangeResult;                                 // + выдачи этой роли, одной транзакцией
    public function renameKey(string $from, string $to): ChangeResult;                 // для ролей из кода (#[FormerKeys])
}
```

`expectedFingerprint` защищает от сохранения устаревшей формы: если права роли успели поменять, → `StaleSelectionException`.

## 3. Фасад

```php
/**
 * @method static PanelAccess panel(string $id)
 * @method static SourceManager sources()                                                  // фабрика источников (D52)
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
Пример: `AzGuard::actingAs('import: crm', fn () => $user->grantRole('manager'))`.

## 4. Описание панели: `PanelProvider` и `PanelBuilder`

```php
namespace AzGuard\Panels;

abstract class PanelProvider extends \Illuminate\Support\ServiceProvider   // как в Filament; папка провайдера = папка панели
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
    public function prefixed(string|bool $prefix = true): static;         // по умолчанию включён (id панели); строка — свой; false — выключить
    // субъекты и вход
    public function subjects(array $models, ?string $guard = null, ?string $directory = null): static;
    public function middleware(array $middleware): static;                // что выполняется при входе в панель
    public function entry(string|UnitEnum|null $permission): static;     // право входа (у суперадмина есть)
    public function onDenied(Closure|string|null $response): static;     // 403 по умолчанию; редирект и т. п.
    public function requireRouteChecks(): static;                         // строгий режим: у каждого действия проверка или #[SkipPermissionCheck]
    // источники (D52): объекты Source или имена из SourceManager; FolderSource есть всегда
    public function sources(array $sources): static;
    // права, роли, политики вне папки панели — добавляются в FolderSource
    public function permissions(array $enums): static;
    public function roles(array $roles): static;
    public function policies(array $policyClasses): static;
    public function discover(string $path, ?string $namespace = null): static;   // ещё одна папка той же структуры (плагины, модули)
    // контексты
    public function contexts(ContextPolicy $policy): static;
    public function contextResolvers(array $resolvers): static;
    // хуки (D55); суперадмин задаётся ролью, а не панелью (D19)
    public function before(array|Closure|string $hooks): static;         // как Gate::before: fn (AccessRequest $r, EvaluationContext $c): ?bool
    public function restrictions(array $restrictions): static;           // только запрещают (D20)
    public function after(array|Closure|string $hooks): static;          // как Gate::after: fn (AccessRequest $r, Decision $d): void
    public function changing(array $pipes): static;                       // pipes Illuminate\Pipeline: handle(Change $change, Closure $next)
    public function fields(FieldTarget $target, array $fields): static;  // поля от плагина (без своей модели)
    // Gate, кэш, консистентность
    public function gate(GateMode $mode = GateMode::Authoritative): static;
    public function cache(?string $store = null, ?int $ttl = null, ?int $generation = null): static;
    public function consistency(Reads $reads = Reads::Primary, StateRefresh $refresh = StateRefresh::Request): static;
    // плагины и прочее
    public function plugins(array $plugins): static;
    public function withoutPlugins(array $pluginIds): static;            // убрать плагин, подключённый через configurePanels()
    public function doctorChecks(array $checks): static;
    public function presentation(array $options): static;               // группы, иконки, подписи для UI
}

final readonly class Panel                    // после сборки неизменяем
{
    public function id(): string;
    public function label(): string;
    public function isDefault(): bool;
    public function prefix(): ?string;
    /** @return list<class-string<Model>> */ public function subjectModels(): array;
    public function accepts(Model|SubjectRef $subject): bool;
    public function settings(): PanelSettings;                 // итоговые значения и откуда взято каждое
    /** @return list<Source> */ public function sources(): array;
    public function writer(): ?StoresGrants;                   // источник-писатель или null («только чтение»)
    public function contextPolicy(): ContextPolicy;
    public function isWritable(): bool;
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

### 4.1 Встроенные источники

```php
namespace AzGuard\Sources;

final class FolderSource implements Source, ProvidesPermissions, ProvidesRoles, ProvidesGrants, ProvidesPolicies, DescribesSchema, ChecksHealth
{
    public static function make(): static;                     // папка провайдера + папки из ->discover(); есть на каждой панели
    public function folders(?string $permissions = null, ?string $policies = null, ?string $roles = null, ?string $abilities = null): static;   // свои имена подпапок
}

final class DatabaseSource implements Source, ProvidesPermissions, ProvidesRoles, ProvidesGrants, StoresGrants, FiltersQueries, DescribesSchema, ChecksHealth
{
    public static function make(): static;                     // динамические роли, выдачи ролей и выдачи прав
    public function rolesOnly(): static;                       // без выдачи отдельных прав: права — только через роли
    public function dynamicPermissions(): static;              // права можно создавать во время работы ({p}permissions)
    public function storage(string|Storage $storage): static;  // 'default' по умолчанию; именованное из конфига или Storage::own(...)
    public function models(?string $role = null, ?string $rolePermission = null, ?string $roleGrant = null, ?string $permissionGrant = null, ?string $permission = null): static;
    public function decisionFields(array $roleGrant = [], array $permissionGrant = []): static;
}

final class RelationSource implements Source, ProvidesGrants, FiltersQueries, DescribesSchema
{
    public static function make(string $model, string $via, string $role, ?Closure $scope = null): static;
}

final class GateSource implements Source, ProvidesPolicies, DescribesSchema
{
    public static function make(): static;
    public function map(string|UnitEnum $permission, string $ability): static;   // право → существующая ability Laravel (второй уровень)
}

final class SourceManager extends \Illuminate\Support\Manager    // AzGuard::sources()
{
    /** @param Closure(Application, array<string, mixed>): Source $callback */
    public function extend($driver, Closure $callback): static;  // как Cache::extend(); #[AsSource('имя')] — то же атрибутом
    public function make(string $name, string $panel): Source;   // новый экземпляр для каждой панели; параметры — config('azguard.sources.<name>')
}
```

Контракты `Source` и его возможностей — [06 §2](06-extension-points.md#2-свой-источник).

## 5. Значения ядра (`AzGuard\Kernel\…`)

```php
final readonly class PermissionKey implements Stringable, JsonSerializable
{
    // три формы одного имени: локальное 'orders.view', с префиксом панели 'admin.orders.view', полное 'admin:orders.view'
    public static function of(string $panel, string $local): self;
    public static function parse(string $full): self;                  // 'admin:orders.view'
    public function panel(): string;
    public function local(): string;                                   // 'orders.view'
    public function full(): string;                                    // 'admin:orders.view'
    public function prefixed(): string;                                // 'admin.orders.view' или локальное, если у панели нет префикса
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
    /** @var list<Grant> */ public array $grants;                  // выдачи, давшие право (при трассировке)
    public function allowed(): bool;
    public function toGateResult(): ?bool;
}

final readonly class Grant                                         // выдача из любого источника: «право есть, потому что…»
{
    public PermissionPattern $pattern; public string $source;      // id источника: 'folder', 'database', 'relation:project', 'ldap'
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

abstract class BaseRole
{
    public function key(): string;                             // из #[Role]; без него — из имени класса (ManagerRole → manager)
    public function label(): string;                           // из #[Role(label:)] через __(); иначе из ключа
    /** @return list<UnitEnum|string> */ abstract public function permissions(): array;   // enum или локальные имена, шаблоны 'orders.*'
    /** @return list<string> */ public function formerKeys(): array;   // из #[FormerKeys]
    public function grantable(): bool;                         // false, если #[NotGrantable]
    public function superAdmin(): bool;                        // true, если #[SuperAdmin] (D19)
    public function level(): int;                              // из #[Role(level:)]; 0 по умолчанию
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Role { public function __construct(public ?string $key = null, public ?string $label = null, public int $level = 0) {} }

#[Attribute(Attribute::TARGET_CLASS)] final readonly class SuperAdmin {}      // держатель роли — суперадмин
#[Attribute(Attribute::TARGET_CLASS)] final readonly class NotGrantable {}    // только автоматически, вручную не выдаётся
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FormerKeys { /** @var list<string> */ public array $keys; public function __construct(string ...$keys) { $this->keys = $keys; } }

interface GrantedAutomatically                                 // роль, которую получает каждый, кто подходит под правило
{
    public function appliesTo(Model $subject, ?ContextRef $context): bool;
}
```

Атрибут и метод равнозначны: метод переопределяют, когда значение вычисляется (например, подпись из своего словаря).
Подписи в атрибутах проходят через `__()`, поэтому в них можно писать ключ перевода.

```php
#[Role('superadmin', label: 'azguard::roles.superadmin')]
#[SuperAdmin]
final class SuperAdminRole extends BaseRole                    // готовая роль; подключается явно: ->roles([SuperAdminRole::class]) или копией в Roles/
{
    public function permissions(): array { return []; }        // права не нужны: у суперадмина есть всё
}

// app/Guards/Seller/Roles/SellerRole.php
#[Role('seller', label: 'Продавец')]
final class SellerRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array { return ['panel.access', ProductPermission::Manage]; }
    public function appliesTo(Model $subject, ?ContextRef $context): bool { return $subject->stores()->exists(); }
}

// app/Guards/Shared/Roles/RootRole.php — суперадмин «по флагу пользователя»
#[Role('root', label: 'Root')]
#[SuperAdmin]
#[NotGrantable]
final class RootRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array { return []; }
    public function appliesTo(Model $subject, ?ContextRef $context): bool { return (bool) $subject->is_root; }
}
```

Суперадмин — **свойство роли**, а не панели: роль из кода помечена `#[SuperAdmin]`, у роли из БД есть флаг
`is_super_admin` (`roles()->create('owner', superAdmin: true)`). Кому роль выдана глобально — тот суперадмин
панели; кому выдана в сущности (`on: $store`) — у того все права внутри этой сущности. Правило «по флагу
пользователя» — автоматическая роль, как `RootRole`. Чтобы роль была во всех панелях, её подключают в каждой:
`AzGuard::configurePanels(fn (PanelBuilder $p) => $p->roles([RootRole::class]))`.

`appliesTo()` вызывается при сборе прав и кэшируется вместе с набором прав на время запроса (`Volatility::Request`).
Если результат зависит от данных, которые меняются редко, роль может объявить `Volatility::Stable`; тогда приложение
само сбрасывает версию панели при их изменении (`AzGuard::panel($id)->touch()`).

## 7. Домены и политики

```php
namespace AzGuard\Permissions;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Domain                                     // на enum прав: подпись и модель домена (D56); необязателен
{
    /** @param class-string<Model>|null $model */
    public function __construct(public ?string $label = null, public ?string $model = null) {}
}

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Describe { public function __construct(public string $label, public ?string $group = null, public ?string $description = null) {} }

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)] final readonly class GrantsOnly {}     // проверяется только выдачами (сегодня #[RoleOnly])
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)] final readonly class GrantedToAll {}   // право есть у каждого субъекта панели
```

```php
// app/Guards/Admin/Orders/Permissions/OrderPermission.php
#[Domain(label: 'Заказы', model: Order::class)]
enum OrderPermission: string
{
    #[Describe('Просмотр списка')] case ViewAny = 'orders.view_any';
    #[Describe('Просмотр')]        case View = 'orders.view';
    #[Describe('Возврат денег')]   case Refund = 'orders.refund';
    #[GrantsOnly]                  case Export = 'orders.export';     // политика его не решает — doctor не спросит метод
}
```

```php
namespace AzGuard\Policies;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PolicyFor                                  // привязка политики вне папки домена (сегодня #[GuardPolicy])
{
    /** @param class-string<UnitEnum> $permissions */
    public function __construct(public string $permissions) {}
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Decides                                    // явная привязка метода к праву (сегодня #[GateAbility])
{
    public function __construct(public UnitEnum|string $permission) {}
}

trait ConsultsGrants
{
    // ответ первого уровня: только выдачи источников (без политик и хуков) — рекурсии нет
    protected function granted(string|UnitEnum $permission, Model $subject, Model|ContextRef|null $on = null): bool;
}
```

Метод политики — второй уровень проверки: получает субъекта и ресурс из `on:` и возвращает `null` (решают выдачи),
`false` (сузить: «нет», даже если выдано), `true` (расширить: «да», даже если не выдано) или `Response`. Пример и
правила структуры — [D53](02-decisions.md#d53), [D56](02-decisions.md#d56).

### 7.1 Атрибуты контроллеров

```php
namespace AzGuard\Attributes;

use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class CheckPermission extends Middleware                  // как сейчас по смыслу; применяет роутер Laravel
{
    public function __construct(
        public UnitEnum|string $permission,
        public ?string $on = null,                              // имя параметра маршрута: сущность или ресурс
        public int $status = 403,
        public ?string $message = null,
        ?array $only = null,                                    // на классе — как у Laravel
        ?array $except = null,
    ) {
        parent::__construct(CheckPermissionMiddleware::using($permission, $on, $status, $message), $only, $except);   // 'azguard.can:…'
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class SkipPermissionCheck {}                     // явно без проверки (сегодня #[SkipGuardCheck])
```

```php
#[CheckPermission(OrderPermission::ViewAny, only: ['index'])]
final class OrderController
{
    #[CheckPermission(OrderPermission::Refund, on: 'order')]
    public function refund(Order $order) { … }
}
```

## 8. Схема панели

```php
namespace AzGuard\Schema;

final readonly class PanelSchema implements JsonSerializable
{
    public string $panel; public string $label; public bool $writable;
    /** @return list<PermissionSchema> */ public function permissions(): array;
    /** @return array<string, list<PermissionSchema>> */ public function groups(): array;   // по доменам
    /** @return list<RoleSchema> */ public function roles(): array;
    /** @return list<FieldSchema> */ public function fields(FieldTarget $target): array;   // Role | RoleGrant | PermissionGrant
    /** @return list<ContextTypeSchema> */ public function contexts(): array;
    /** @return list<SubjectTypeSchema> */ public function subjects(): array;
    public function toArray(): array;
}

final readonly class PermissionSchema
{
    public PermissionKey $key; public string $label; public ?string $domain; public ?string $description;
    public bool $dynamic;                    // создано в БД во время работы (D52), а не в enum
    public string $name;                     // имя для Gate и фронтенда: с префиксом панели, если он есть
    public ?string $decidedBy;               // 'policy:App\Guards\Admin\Orders\Policies\OrderPolicy@view', 'gate:beta-access' или null — подсказка для UI
    /** @var list<string> */ public array $sources;   // id источников, которые могут дать право
    /** @var list<string> */ public array $contextTypes;
}

final readonly class RoleSchema
{
    public RoleKey $key; public string $label;
    public RoleOrigin $origin;               // Static (класс) | Dynamic (БД)
    public bool $editable;                   // можно менять права (только роли DatabaseSource)
    public bool $grantable;                  // можно выдавать вручную
    public bool $automatic;                  // выдаётся правилом (может быть одновременно с grantable)
    public bool $superAdmin;                 // держатель — суперадмин
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
- Свои поля (`fields:`) проверяются по `azguardFields()` модели панели и pipes `changing`; неизвестное поле →
  `InvalidChangeFieldsException`.
- Отзыв во всех сущностях — `on: AnyContext::all()`.

## 10. Исключения

| Класс | Код | Родитель | Когда |
|---|---|---|---|
| `InvalidConfigurationException` | `invalid_configuration` | `ConfigurationException` | проверки при загрузке |
| `DuplicatePanelException`, `UnknownPanelException`, `RegistryFrozenException`, `DefaultPanelConflictException` | `duplicate_panel`, `unknown_panel`, `registry_frozen`, `default_panel_conflict` | `DefinitionException` | реестр панелей |
| `PanelNotResolvedException` | `panel_not_resolved` | `DefinitionException` | правило выбора панели не нашло панель |
| `UnknownSourceException`, `WriterConflictException` | `unknown_source`, `writer_conflict` | `DefinitionException` | имя источника не зарегистрировано; два источника-писателя на одной панели |
| `MissingPermissionCheckException` | `missing_permission_check` | `ConfigurationException` | строгий режим: у действия нет проверки и нет `#[SkipPermissionCheck]` |
| `PrefixConflictException` | `prefix_conflict` | `DefinitionException` | префикс панели повторяется или совпадает с первым сегментом чьего-то локального имени |
| `InvalidPolicyStructureException` | `invalid_policy_structure` | `DefinitionException` | enum с разными ресурсами в значениях; у права две политики; неподходящая сигнатура метода |
| `AmbiguousPanelException` | `ambiguous_panel` | `DefinitionException` | enum подключён к нескольким панелям, панель не указана |
| `SubjectNotAcceptedException` | `subject_not_accepted` | `DefinitionException` | модель не является субъектом панели |
| `DuplicatePermissionException`, `DuplicateRoleException`, `DuplicatePolicyBindingException` | `duplicate_permission`, `duplicate_role`, `duplicate_policy_binding` | `DefinitionException` | коллизии вкладов (с именами плагинов) |
| `PluginDependencyMissingException`, `PluginConflictException` | `plugin_dependency_missing`, `plugin_conflict` | `PluginException` | сборка панели |
| `InvalidPanelIdException`, `InvalidPermissionKeyException`, `InvalidRoleKeyException`, `InvalidContextException` | `invalid_panel_id`, `invalid_permission_key`, `invalid_role_key`, `invalid_context` | `InvalidIdentityException` | грамматика |
| `StorageMismatchException`, `UnsupportedDirectWriteException` | `storage_mismatch`, `unsupported_direct_write` | `StorageException` | хранилище |
| `UnknownPermissionException`, `UnknownRoleException`, `RoleNotGrantableException`, `RoleNotEditableException`, `ContextNotAcceptedException`, `PanelNotWritableException`, `InvalidChangeFieldsException`, `StaleSelectionException`, `ChangeCancelledException` | `unknown_permission`, `unknown_role`, `role_not_grantable`, `role_not_editable`, `context_not_accepted`, `panel_not_writable`, `invalid_change_fields`, `stale_selection`, `change_cancelled` | `ChangeException` | пайплайн изменений |

Отказ в доступе — `Illuminate\Auth\Access\AuthorizationException`; `$e->response()->code()` = `DecisionReason::value`.
Ошибки источников, политик, ограничений и хуков при проверке не выбрасываются наружу: они дают отказ с причиной
`source_error`/`policy_error`/`restriction_error`/`hook_error` и пишутся в лог.
