# 05 — Публичный PHP API

Решения: [D05](02-decisions.md#d05)–[D11](02-decisions.md#d11), [D14](02-decisions.md#d14), [D19](02-decisions.md#d19),
[D22](02-decisions.md#d22), [D45](02-decisions.md#d45)–[D58](02-decisions.md#d58).
Имена, типы и смысл сигнатур обязательны; порядок необязательных параметров можно уточнить в спецификации пункта
плана. Всё ниже — `@api`, если не сказано иное. Контракты для расширения (источники, хуки, плагины) —
[06](06-extension-points.md).

## 0. Как это выглядит целиком

Каждая панель — папка ([D56](02-decisions.md#d56)): enum прав, политики и роли в ней находит `FolderSource`. Всё
остальное панель берёт из **источников**, перечисленных в `->permissions([...])` ([D52](02-decisions.md#d52)).

```php
// app/Guards/Cabinet/CabinetGuardPanelProvider.php — личный кабинет: папка панели и связи, БД нет
final class CabinetGuardPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('cabinet')->label('Личный кабинет')->default()   // имена прав: cabinet.orders.view
            ->for([User::class], guard: 'web')
            ->middleware(['web', 'auth:web'])
            ->permissions([
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
            ->for([User::class], guard: 'web')
            ->entry('panel.access')                                // войти может только продавец (право даёт SellerRole)
            ->permissions([
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
            ->for([User::class], guard: 'web')
            ->entry('panel.access')
            ->requireRouteChecks()                                 // у каждого действия — #[CheckPermission] или явный пропуск
            ->permissions([
                ReportPermission::class,                            // enum вне папки панели → FolderSource
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
final class LdapSource implements Source, ProvidesRoleGrants { /* группы LDAP → роли панели, 06 §2 */ }

// app/Providers/AppServiceProvider.php — общее для всех панелей
AzGuard::configurePanels(fn (PanelBuilder $panel) => $panel->roles([RootRole::class]));   // суперадмин «по флагу»

// Проверки — панель по умолчанию (cabinet), панель маршрута или явная
$user->hasPermission(OrderPermission::View, on: $order);        // enum кабинета: выдачи + OrderPolicy::view()
$user->can('update', $order);                                    // Laravel-стиль: домен объявил модель Order
$user->hasPermission('seller.orders.cancel', on: $store);       // префикс указывает на панель seller
$user->hasPermission('backoffice.orders.refund');               // свой префикс панели admin
$user->guard('admin')->hasRole('manager');
$user->hasPermission('admin:orders.refund');                    // полное имя работает всегда

// Изменения — через источник-писатель панели (DatabaseSource)
$user->guard('seller')->grantRole('store-manager', on: $store);
$user->guard('admin')->grantRole('support', fields: ['department_id' => 7], until: now()->addMonth());
$user->guard('admin')->revokeRole('support');
AzGuard::panel('admin')->roles()->all(); // read-only catalogue PHP классов; grants меняет SubjectAccess
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
    public function inTenant(Model|TenantRef $tenant): SubjectAccess; // текущая/явная панель
    /** @return ($guarded is array ? static : SubjectAccess) */
    public function guard(array|string $guarded): static|SubjectAccess; // native mass assignment либо panel selector
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
final readonly class SubjectAccess          // $user->guard('admin'), AzGuard::panel('admin')->for($user)
{
    // все методы трейта из групп «Права», «Роли», «Суперадмин», «Изменения» — в этой панели
    public function panel(): Panel;
    public function fromOrigin(string $origin): self;
    public function inTenant(Model|TenantRef $tenant): self; // новый immutable wrapper
    public function scope(): AccessScope;
    public function decide(string|UnitEnum $permission, Model|ContextRef|null $on = null): Decision;
    /** @return array<string, bool> */ public function abilities(array $permissions, Model|ContextRef|null $on = null): array;  // для фронтенда
    /** @return Collection<int, RoleGrant> модели панели со своими полями */ public function roleGrants(Model|ContextRef|AnyContext|null $on = null): Collection;
    /** @return Collection<int, PermissionGrant> */ public function permissionGrants(Model|ContextRef|AnyContext|null $on = null): Collection;
}

final readonly class SubjectPanels          // $user->azguard()
{
    /** @return list<string> */ public function panels(): array;               // панели, принимающие модель
    public function default(): ?string;
    public function guard(string $id): SubjectAccess;
    /** @return array<string, PermissionSet> */ public function permissions(): array;  // только non-tenant панели; tenant-панель требует scoped wrapper
    /** @return array<string, list<string>> */ public function roles(): array; // tenant-панель без tenant -> TenantRequiredException
}
```

## 2. Панель: `AzGuard::panel()`

```php
namespace AzGuard\Contracts;

interface PanelAccess                         // AzGuard::panel('admin')
{
    public function definition(): Panel;
    public function inTenant(Model|TenantRef $tenant): self;
    public function fromOrigin(string $origin): self; // partition GrantManager; не сужает authorization reads
    public function scope(): AccessScope;
    public function for(Model|Authenticatable|SubjectRef $subject): SubjectAccess;
    public function roles(): RoleCatalog; // read-only code definitions
    public function grants(): GrantManager; // read/write только выбранных panel/tenant/origin
    public function permissions(): PermissionManager;
    public function schema(): PanelSchema;
    public function decide(AccessRequest $request): Decision;
    /** @param iterable<AccessRequest> $requests */
    public function decideMany(iterable $requests): DecisionSet;
    public function explain(AccessRequest $request): Explanation;
    public function catalog(): PermissionCatalog;
    public function visibility(): Visibility;
    public function state(): StateToken; // explicit storage management, not policy prerequisite
    public function touch(): StateToken;       // новая версия вручную (данные автоматических ролей изменились)
}

interface PermissionManager                   // только при DatabaseSource::make()->dynamicPermissions(); права из enum — в коде
{
    /** @return list<PermissionSchema> статичные и динамические */ public function all(): array;
    public function create(string $name, ?string $label = null, ?string $group = null, array $fields = []): ChangeResult;
    public function update(string $name, PermissionDetails $details): ChangeResult;
    public function delete(string $name): ChangeResult;                                // + выдачи этого права, одной транзакцией
}

interface RoleCatalog // immutable роли PHP-классов; назначения — GrantManager/SubjectAccess
{
    /** @return list<RoleSchema> */ public function all(): array;
    public function find(string $key): ?RoleSchema;
}
```

Все managers привязаны к immutable `(panel, tenant)`; не принимают tenant через `fields`.
`SubjectAccess::fromOrigin(string $origin): self` выбирает partition назначения (по умолчанию manual);
изменение внешнего origin требует policy/pipe приложения. Actor и origin — разные понятия.
`syncRoles`/`syncPermissions` работают только в точном scope и origin. `AnyContext::all()` расширяет
только contexts выбранного tenant; операции «все tenants» нет в обычном API.
`permissionSet`/`roleNames` без scope не агрегируют скрытно все организации.

```php
interface GrantManager // @api; scoped readers и операции для UI, включая update/bulk
{
    public function page(GrantFilter $filter): GrantPage; // filters + limit; panel/tenant не переопределяются
    public function find(string $id): ?GrantRecord; // чужой panel/tenant/origin -> null
    public function update(string $id, GrantDetails $details, ?string $expectedFingerprint = null): ChangeResult;
    /** @param list<string> $ids */ public function revokeMany(array $ids): ChangeResult;
}
```

GrantFilter/GrantPage — typed значения pagination, вида grant, subject/context/role/expiry;
никакого свободного SQL/expression из UI. update меняет только срок/fields и снова валидирует роль/scope;
смена субъекта/role/context — revoke+grant в одной mutation. Чужой id в bulk -> вся операция отклоняется.

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
    public function for(array $models, ?string $guard = null, ?string $directory = null): static;
    public function middleware(array $middleware): static;                // что выполняется при входе в панель
    public function entry(string|UnitEnum|null $permission): static;     // право входа (у суперадмина есть)
    public function onDenied(Closure|string|null $response): static;     // 403 по умолчанию; редирект и т. п.
    public function requireRouteChecks(): static;                         // строгий режим: у каждого действия проверка или #[SkipPermissionCheck]
    // описание прав (D73): enum definitions + источники; FolderSource есть всегда
    /** @param list<Source|class-string<BackedEnum>|string> $definitions */
    public function permissions(array $definitions): static;
    // дополнительные роли/политики вне папки панели — добавляются в FolderSource
    public function roles(array $roles): static;
    /** @param list<PolicyBinding|class-string> $bindings */
    public function policies(array $bindings): static; // grant-side veto requires explicit PolicyBinding
    public function discover(string $path, ?string $namespace = null): static;   // ещё одна папка той же структуры (плагины, модули)
    // tenant и context — независимые dimensions
    public function tenants(TenantPolicy $policy): static;
    public function tenantResolvers(array $resolvers): static;
    public function contexts(ContextPolicy $policy): static;
    public function contextResolvers(array $resolvers): static;
    /** @param array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>> $resolvers */
    public function resourceScopes(array $resolvers): static; // class-string ресурса -> ResourceScopeResolver
    public function grantConditions(array $conditions): static; // AND условий одной grant
    // хуки (D55); суперадмин задаётся ролью, а не панелью (D19)
    public function before(array|Closure|string $hooks): static;         // fn (AccessRequest $r, EvaluationContext $c): BeforeResult; не native Gate shortcut
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
    /** @return list<SourceDescription> */ public function sources(): array; // immutable metadata; factories internal
    public function writer(): ?StoresGrants;                   // resolve runtime adapter текущего request/job, null для read-only
    public function contextPolicy(): ContextPolicy;
    public function tenantPolicy(): TenantPolicy;
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

`PanelBuilder::permissions([...])` — один метод для дополнительных enums и источников. Enum class-string
добавляется в FolderSource; Source object описывает отдельный источник; короткая строка — зарегистрированное
имя SourceManager. Класс enum должен быть string-backed, неизвестный/неподходящий класс отклоняется.
Более поздние вызовы permissions дополняют список; повтор того же enum (включая autodiscovery) дедуплицируется
по FQCN. Не-enum элементы сохраняют порядок регистрации после FolderSource; повтор source id — ошибка.
Строка `orders.view` здесь не объявляет action: string означает имя зарегистрированного источника.

`PanelAccess::permissions()` без аргументов возвращает scoped PermissionManager, а BaseRole::permissions() —
права роли; это разные receivers. `AzGuard::sources()` остаётся фабрикой, Panel::sources() — metadata getter.
Ни фабрика, ни introspection не являются методом конфигурации PanelBuilder.

`PanelResolver` (internal) — единственная реализация правила выбора панели ([09 §1](09-authorization-semantics.md#1-как-выбирается-панель)).

### 4.1 Встроенные источники

```php
namespace AzGuard\Sources;

final class FolderSource implements Source, ProvidesPermissions, ProvidesRoles, ProvidesGrants, ProvidesRoleGrants, ProvidesPolicies, DescribesSchema, ChecksHealth
{
    public static function make(): static;                     // папка провайдера + папки из ->discover(); есть на каждой панели
    public function folders(?string $permissions = null, ?string $policies = null, ?string $roles = null, ?string $abilities = null): static;   // свои имена подпапок
}

final class DatabaseSource implements Source, ProvidesPermissions, ProvidesGrants, ProvidesRoleGrants, StoresGrants, FiltersQueries, DescribesSchema, ChecksHealth
{
    public static function make(): static;                     // назначения классов ролей и enum прав; dynamic actions только opt-in
    public function rolesOnly(): static;                       // без выдачи отдельных прав: права — только через роли
    public function dynamicPermissions(): static;              // права можно создавать во время работы ({p}permissions)
    public function storage(string|Storage $storage): static;  // 'default' по умолчанию; именованное из конфига или Storage::own(...)
    public function models(?string $roleGrant = null, ?string $permissionGrant = null, ?string $permission = null): static;
    public function decisionFields(array $roleGrant = [], array $permissionGrant = []): static;
}

final class RelationSource implements Source, ProvidesRoleGrants, FiltersQueries, DescribesSchema
{
    public static function make(string $model, string $via, string|Closure $role, ?Closure $scope = null): static; // модель или ContextDefinition; scope дополнительно сужает выбранный tenant
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
final readonly class ContextRef { public static function of(string $type, int|string $id): self; public static function global(): self; public function isGlobal(): bool; public function key(): string; public function type(): ?string; public function id(): ?string; }
final readonly class TenantRef
{
    public static function of(string $type, int|string $id): self;
    public static function global(): self; public function isGlobal(): bool; public function key(): string;
    public function type(): ?string; public function id(): ?string;
}
final readonly class AccessScope
{
    public static function in(TenantRef $tenant, ?ContextRef $context = null): self;
    public TenantRef $tenant; public ContextRef $context; // null в factory нормализуется в global
}
final readonly class RoleContribution
{
    public RoleKey $role; public AccessScope $scope; public string $source; public string $origin;
    public ?DateTimeImmutable $expiresAt;
    /** @return array<string, mixed> только decisionFields */ public function fields(): array;
}
final readonly class AnyContext { public static function all(): self; }    // «во всех сущностях» — только для отзыва

final readonly class ActorRef { public const string SYSTEM_TYPE = 'azguard.system'; public ?string $type; public ?string $id; public ?string $reason; }

final readonly class AccessRequest
{
    public static function for(SubjectRef $subject, PermissionKey $permission): self;
    public function inTenant(TenantRef $tenant): self;
    public function on(?ContextRef $context, ?object $resource = null): self; // ContextRef::global() явно; null не отменяет required tenant
    public function inScope(AccessScope $scope, ?object $resource = null): self;
    public function traced(bool $trace = true): self;
}

enum Effect: string { case Allow = 'allow'; case Deny = 'deny'; case NotApplicable = 'not_applicable'; }

enum DecisionReason: string
{
    case Granted = 'granted';               case SuperAdmin = 'super_admin';        case Hook = 'hook';
    case Policy = 'policy';                 case NotGranted = 'not_granted';        case NotApplicable = 'not_applicable';
    case ContextRequired = 'context_required'; case ContextNotAccepted = 'context_not_accepted';
    case ContextIneligible = 'context_ineligible'; case ContextFilterError = 'context_filter_error';
    case Restricted = 'restricted';         case SourceError = 'source_error';      case PolicyError = 'policy_error';
    case RestrictionError = 'restriction_error'; case HookError = 'hook_error';
    case TenantRequired = 'tenant_required'; case TenantMismatch = 'tenant_mismatch';
    case ContextMismatch = 'context_mismatch'; case ResourceScopeMissing = 'resource_scope_missing';
    case ConditionError = 'condition_error'; case ConsistencyError = 'consistency_error';
}

final readonly class Decision
{
    public Effect $effect; public DecisionReason $reason; public CodeStateToken|StateToken $state; public AccessScope $scope;
    public ?string $component;                                     // ключ хука, политики, ограничения, источника
    /** @var list<Grant> */ public array $grants;                  // выдачи, давшие право (при трассировке)
    public function allowed(): bool;
    public function toGateResult(): mixed; // bool|null|Laravel Response через adapter; Kernel Response не импортирует
}

final readonly class Grant                                         // выдача из любого источника: «право есть, потому что…»
{
    public PermissionPattern $pattern; public string $source;      // id источника: 'folder', 'database', 'relation:project', 'ldap'
    public ?RoleKey $role; public AccessScope $scope; public string $origin; public ?DateTimeImmutable $expiresAt;
    /** @return array<string, mixed> только decisionFields */ public function fields(): array;
}

enum BeforeResult { case Continue; case Deny; }
final readonly class CodeStateToken { public string $panel; public string $buildId; public string $fingerprint; }
final readonly class StateToken { public string $storageId; public string $panel; public string $incarnation; public int $version; public int $generation; public string $fingerprint; }
final readonly class DecisionSet implements Countable, IteratorAggregate { public function get(int $i): Decision; /** @return array<string, CodeStateToken|StateToken> keyed by code/panel/build or storage/panel */ public function states(): array; }
final readonly class PermissionSet { public function patterns(): array; public function covers(PermissionKey $key): bool; public function validUntil(): ?DateTimeImmutable; }
```

`Kernel\` не зависит от Laravel. Модели в `ContextRef` переводит Laravel-слой; все методы с `Model|ContextRef` принимают
модель напрямую.

## 6. Роли в коде

```php
namespace AzGuard\Roles;

abstract class BaseRole
{
    public function key(): string;                             // explicit #[Role(key)] или override stable key(); отсутствует -> DefinitionException
    public function label(): string;                           // из #[Role(label:)] через __(); иначе из ключа
    /** @return list<UnitEnum|string> */ abstract public function permissions(): array;   // enum или локальные имена, шаблоны 'orders.*'
    /** @return list<string> */ public function formerKeys(): array;   // из #[FormerKeys]
    public function grantable(): bool;                         // false, если #[NotGrantable]
    public function superAdmin(): bool;                        // true, если #[SuperAdmin] (D19)
    /** @return list<ContextDefinition|class-string<ContextDefinition>> */ public function contexts(): array; // configured definitions; [] = tenant-wide
    public function contextRequired(): bool; // default false; true требует contexts и on:
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
    public function appliesTo(Model $subject, AccessScope $scope): bool;
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
    public function appliesTo(Model $subject, AccessScope $scope): bool { return $subject->stores()->exists(); }
}

// app/Guards/Shared/Roles/RootRole.php — суперадмин «по флагу пользователя»
#[Role('root', label: 'Root')]
#[SuperAdmin]
#[NotGrantable]
final class RootRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array { return []; }
    public function appliesTo(Model $subject, AccessScope $scope): bool { return (bool) $subject->is_root; }
}
```

Суперадмин — **свойство PHP-класса роли**: #[SuperAdmin]/superAdmin(). Назначение её в БД не создаёт
новую definition. В Grants mode scoped superadmin может обеспечить authority, но attached policy veto/common owner/
restrictions остаются. PolicyOnly проверяет свою policy без role lookup. RootRole подключается к нужным панелям.

`appliesTo()` вызывается при сборе прав и кэшируется вместе с набором прав на время запроса (`Volatility::Request`).
Если результат зависит от данных, которые меняются редко, роль может объявить `Volatility::Stable`; тогда приложение
само сбрасывает версию панели при их изменении (`AzGuard::panel($id)->touch()`).

## 7. Ресурсы и политики

```php
namespace AzGuard\Permissions;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Resource                                     // на enum прав: подпись и модель домена (D56); необязателен
{
    /** @param class-string<Model>|null $model */
    public function __construct(public ?string $label = null, public ?string $model = null) {}
}

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Describe { public function __construct(public string $label, public ?string $group = null, public ?string $description = null) {} }

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_CLASS_CONSTANT)] final readonly class RequiresGrant {}  // authority — только квалифицированные назначения
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_CLASS_CONSTANT)] final readonly class PolicyOnly {} // authority — PHP policy, grants не читаются

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)] final readonly class GrantedToAll {}   // право есть у каждого субъекта панели
```

```php
// app/Guards/Admin/Permissions/Orders/OrderPermission.php
#[Resource(label: 'Заказы', model: Order::class)]
#[RequiresGrant]
enum OrderPermission: string
{
    #[Describe('Просмотр списка')] case ViewAny = 'orders.view_any';
    #[Describe('Просмотр')]        case View = 'orders.view';
    #[Describe('Возврат денег')]   case Refund = 'orders.refund';
    #[RequiresGrant]                  case Export = 'orders.export';     // policy может только ограничить существующее назначение
}
```

```php
namespace AzGuard\Policies;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PolicyFor                                  // точная привязка enum при неоднозначном pairing D56 (сегодня #[GuardPolicy])
{
    /** @param class-string<UnitEnum> $permissions */
    public function __construct(public string $permissions) {}
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Decides                                    // явная привязка метода к праву (сегодня #[GateAbility])
{
    public function __construct(public UnitEnum|string $permission) {}
}


```

Метод политики получает subject/resource из `on:` и возвращает bool/null/Response. В PolicyOnly true — authority,
false/null — deny. В RequiresGrant true/null — только pass при наличии assignment, false — veto. Пример и
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
    public string $panel; public string $label; public bool $writable; public TenantRef $tenant;
    /** @return list<PermissionSchema> */ public function permissions(): array;
    /** @return array<string, list<PermissionSchema>> */ public function groups(): array;   // по доменам
    /** @return list<RoleSchema> */ public function roles(): array;
    /** @return list<FieldSchema> */ public function fields(FieldTarget $target): array;   // Role | RoleGrant | PermissionGrant
    /** @return list<TenantTypeSchema> */ public function tenants(): array;
    /** @return list<ContextTypeSchema> */ public function contexts(): array;
    /** @return list<SubjectTypeSchema> */ public function subjects(): array;
    public function toArray(): array;
}

final readonly class PermissionSchema
{
    public PermissionKey $key; public string $label; public ?string $resourceGroup; public ?string $description;
    public PermissionAuthority $authority;  // Policy | Grants: обязательная часть definition
    public bool $dynamic;                    // создано в БД во время работы (D52), а не в enum
    public string $name;                     // имя для Gate и фронтенда: с префиксом панели, если он есть
    public ?string $decidedBy;               // 'policy:App\Guards\Admin\Policies\Orders\OrderPolicy@view', 'gate:beta-access' или null — подсказка для UI
    /** @var list<string> */ public array $sources;   // id источников, которые могут дать право
    /** @var list<string> */ public array $contextTypes;
}

final readonly class RoleSchema
{
    public RoleKey $key; public string $label;
    /** @var class-string<BaseRole> */ public string $class;
    public bool $editable = false;           // definition меняется только в PHP
    public bool $grantable;                  // можно выдавать вручную
    public bool $automatic;                  // выдаётся правилом (может быть одновременно с grantable)
    public bool $contextRequired;
    /** @var list<string> aliases допустимых ContextDefinition */ public array $contextTypes;
    /** @var list<ContextBindingSchema> */ public array $contextBindings; // alias/filter class metadata, без runtime models/closures
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

- Все методы изменения (трейт, `SubjectAccess`, `GrantManager`/`PermissionManager`, команды, Filament) создают `Change` и отправляют его в
  пайплайн изменений ([D49](02-decisions.md#d49)).
- `ChangeResult`: `status` (`Applied` | `Unchanged`), `record` (затронутая запись), `state`, `committed` (false до внешнего commit).
- Повторная выдача того же в полном ключе panel/tenant/context/origin — `Unchanged`, без события. Выдача с новым сроком или полями — обновление строки.
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
| `InvalidPolicyStructureException` | `invalid_policy_structure` | `DefinitionException` | неоднозначная группа enum/policy без явной привязки; у права две политики; неподходящая сигнатура метода |
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


## 11. Контракты tenant/context и версии adapters

Tenant/context refs codec и owner boundary — 08/09. BaseRole.contexts default [] = tenant-wide only;
contextRequired default false. Никаких роли/filters в БД; RoleCatalog read-only registered classes.
GrantManager readers/writers scoped panel+tenant+origin. Revocation orphan rows использует stored scope/actor authority.
ResourceScopeResolver и Query adapters сохраняют immutable owner/common predicates. PolicyOnly не вызывает
assignment store, Decision evidence typed CodeStateToken либо consumed DB StateToken. Exact Eloquent adapter
поддерживается только на реально qualified engine/framework combinations, unsupported query throws.

## 12. Конфигурация контекстов и runtime inputs

Канонические contracts — [18](18-contexts-and-runtime-inputs.md) и [19](19-oop-and-permission-authority.md).
HasAzGuard guard(array|string) сохраняет native Eloquent behavior; string выбирает панель, for(...guard:) — auth guard.

```php
namespace AzGuard\Contexts;
abstract class BaseContext implements ConfigurableContextDefinition
{
    /** @param ContextQueryFilter|class-string<ContextQueryFilter>|Closure $filter */
    public function query(ContextQueryFilter|string|Closure $filter): static;
    public function label(string $label): static;
    /** @param class-string<ContextDirectory> $class */ public function directory(string $class): static;
    public function settings(): ContextSettings;
}
namespace AzGuard\Permissions;
#[RequiresGrant]
enum PermissionAuthority: string { case Policy = 'policy'; case Grants = 'grants'; }
```

query(new SellerProjects(...)) передаёт объект с именованными constructor args. Class-string означает ровно
класс ContextQueryFilter и разрешается Laravel container на operation; не имя профиля/драйвера. Closure получает
reserved operation inputs; current user/role/actor не сохраняются в configuration. Все fluent setters clone.
BaseRole в ContextRuntime — настоящий зарегистрированный класс, common/direct path role=null.
BaseRole не имеет model()/field()/fields(): регион/другие параметры описывает конкретный PHP-класс типизированными
методами либо отдельный typed filter constructor. Grant fields — данные конкретного назначения, не изменение роли.
ContextBindingSchema: contextType + filter class metadata + display + exactSupport. ContextTypeSchema содержит
label/model/directory. JSON bindings/profiles в БД нет; settings меняются review/deploy + build fingerprint.
ContextPolicy inherit/isolated/required принимает definitions или class-string; role bindings только из кода.

BasePlugin не объявляет make(options:), options()/withOptions(). Конкретный plugin объявляет собственную factory
с named typed parameters; register/boot совместимы с Plugin SPI. PluginContext — panel/plugin/build/dependencies,
а конфигурация принадлежит типизированным полям самого plugin. Пример CrmModels и проверок — 19 §4.
RequiresGrant/PolicyOnly на enum или case явно выбирают authority. Case override enum разрешён; оба атрибута на
одном элементе либо отсутствие итогового режима — DefinitionException. Grants policy — veto/pass, без authority
fallback. Dynamic actions имеют только Grants mode; schema/editor не меняют этот режим (D83).



GrantDetails(until: ?DateTimeImmutable, fields: array) и PermissionDetails(label: ?string, group: ?string,
description: ?string, fields: array) — immutable **полные replacements** редактируемых значений, не произвольные
patch maps. fields имеют declared FieldSchema/validation, неизвестные keys отклоняются. Identity/role/action/mode/
scope/origin сюда не входят. UI merge текущих разрешённых полей выполняется до создания DTO и проверяет fingerprint.
Change pipes используют withUntil/withFields, а не arbitrary with(['scope'=>...]); final validation обязательна.


```php
namespace AzGuard\Changes;
final readonly class GrantDetails
{
    public function __construct(public ?DateTimeImmutable $until, public array $fields = []) {}
}
final readonly class PermissionDetails
{
    public function __construct(
        public ?string $label, public ?string $group, public ?string $description, public array $fields = [],
    ) {}
}
```

Details не являются assertion, что поля уже безопасны: writer повторно проверяет schema/actor/fingerprint после
pipes. Constructor только ограничивает shape; identity/mode никогда не входят в details. Scalar input values
валидируются adapter, UI напрямую constructor/composer container не вызывает.


```php
namespace AzGuard\Policies;
final readonly class PolicyBinding
{
    public static function for(UnitEnum|string $permission, string $policy, string $method): self;
}
```

PolicyBinding.for targets exact action + concrete class + public method, validates DI/model inputs at compilation.
Для RequiresGrant это обязательное явное declaration optional veto: missing class/method не становится pass.
Folder pairing/PolicyFor/Decides помогают найти PolicyOnly binding; без итогового binding PolicyOnly compile error.
Автопоиск policy method не подключает optional grant-side veto скрыто. Role/policy/generator stubs публикуют
объявленные bindings отдельно от implementation, поэтому случайный rename метода обнаруживается.
