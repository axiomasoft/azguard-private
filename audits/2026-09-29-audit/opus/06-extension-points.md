# 06 — Расширение: источники, плагины, хуки, свои модели, модули

Решения: [D14](02-decisions.md#d14), [D20](02-decisions.md#d20), [D23](02-decisions.md#d23), [D36](02-decisions.md#d36),
[D39](02-decisions.md#d39), [D46](02-decisions.md#d46)–[D58](02-decisions.md#d58).

Всё в этом файле — `@spi`: контракты, которые реализуют авторы источников, плагинов, хуков и интеграций. Правило одно:
**встроенные источники AzGuard написаны на этих же контрактах** (arch-тест). Значит, всё, что умеет AzGuard, может
сделать и сторонний автор. Где у Laravel уже есть механизм, AzGuard использует его ([D58](02-decisions.md#d58)).

## 1. Источники: фабрика

Панель — конструктор; её детали — источники ([D52](02-decisions.md#d52)). Источник — класс, который целиком отвечает
за один способ получить права: читает папку панели, работает с базой данных, спрашивает LDAP. Панели всё равно,
откуда пришла выдача: проверка одна.

### 1.1 Контракт и возможности

Источник реализует `Source` и только те возможности, которые ему нужны:

```php
namespace AzGuard\Contracts\Sources;

interface Source
{
    public function id(): string;                                   // 'folder', 'database', 'relation:project', 'ldap' — уникален в панели
}

interface ProvidesPermissions extends Source                        // права в каталог панели
{
    /** @return iterable<PermissionDefinition> */
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable;
    public function isDynamic(): bool;                              // false — при сборке, в catalog:cache; true — меняется во время работы
}

interface ProvidesRoles extends Source                              // роли панели
{
    /** @return iterable<BaseRole> */
    public function roles(Panel $panel): iterable; // code definitions, только на сборке
}

interface ProvidesGrants extends Source                             // первый уровень: что выдано субъекту
{
    /** @param list<AccessScope> $scopes @return iterable<Grant> */
    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable;
    public function volatility(): Volatility;                       // Stable | Request | Volatile
}

interface ProvidesRoleGrants extends Source // назначенные роли, включая superadmin с пустыми permissions
{
    /** @param list<AccessScope> $scopes @return iterable<RoleContribution> */
    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable;
    public function volatility(): Volatility;
}

interface ProvidesPolicies extends Source                           // explicit PolicyOnly authority / RequiresGrant veto
{
    /** @return iterable<PolicyBinding> право → метод политики или ability Gate */
    public function policies(Panel $panel): iterable;
}

interface StoresGrants extends Source                               // писатель панели; не больше одного на панели
{
    public function apply(Change $change): ChangeResult;            // validated change, inside transaction; orchestration owns Changes
    public function transaction(Closure $callback): mixed;          // транзакция на соединении источника
}

interface FiltersQueries extends Source                             // для visibleTo(): какие сущности типа $type дают право
{
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?AssignmentScopeSelection;
}

interface DescribesSchema extends Source                            // для схемы панели (D54): что может дать, свои поля
{
    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription;
}

interface ChecksHealth extends Source                               // проверки doctor
{
    /** @return list<DoctorCheck> */
    public function doctorChecks(): array;
}

interface EvaluationContext                     // @api — что движок даёт источникам, хукам, ограничениям
{
    public function panel(): Panel;
    public function scope(): AccessScope;
    public function scopes(): array;          // применимые AssignmentScopeRef
    public function resource(): ?object;
    public function state(): CodeStateToken|StateToken;
    public function now(): DateTimeImmutable;   // одно значение на проверку / на decideMany
    public function subjectModel(): ?Model;     // target user, не implicit Auth
    public function actor(): ActorRef;
    public function actorModel(): ?Model;
    public function role(): ?BaseRole;          // эта contribution; до role collection = null
    public function grant(): Grant|RoleContribution|null;
    /** @return list<Grant> выдачи, покрывающие запрошенное право (с decisionFields) */
    public function matchingGrants(): array;
}
```

| Встроенный источник | Возможности |
|---|---|
| `FolderSource` | `ProvidesPermissions` (статичные), `ProvidesRoles` (статичные), `ProvidesRoleGrants` (автоматические роли), `ProvidesGrants` (`#[GrantedToAll]`), `ProvidesPolicies` (политики доменов), `DescribesSchema`, `ChecksHealth` |
| `DatabaseSource` | `ProvidesPermissions` (динамические), `ProvidesRoles` (динамические), `ProvidesRoleGrants`, `ProvidesGrants`, `StoresGrants`, `FiltersQueries`, `DescribesSchema`, `ChecksHealth` |
| `RelationSource` | `ProvidesRoleGrants`, `FiltersQueries`, `DescribesSchema` |
| `GateSource` | `ProvidesPolicies`, `DescribesSchema` |

### 1.2 Как панель собирает источники

1. `FolderSource` есть всегда и идёт первым: папка провайдера, папки из `->discover()`, enum class-strings из `->permissions([...])`,
   `->roles()`, `->policies()`. Enum и источник разделяются по типу элемента, не по второму имени метода.
2. Затем Source objects/зарегистрированные имена из того же `->permissions([...])` в порядке перечисления; плагины добавляют свои в `register()`.
3. Имя вместо объекта (`'ldap'`) разрешает `SourceManager` (§1.3); для каждой панели создаётся свой экземпляр.
4. Проверки сборки: `id()` источников не повторяются; права и роли разных источников не сталкиваются
   (`DuplicatePermissionException`, `DuplicateRoleException` с id источников); писатель не больше одного
   (`WriterConflictException`); роли, на которые ссылаются выдачи (`RelationSource`), существуют.
5. Заморозка: статичная часть каталога и привязки политик попадают в `azguard:catalog:cache`.

Правила выдач: источник не может дать право другой панели или голую звёздочку — невалидный ответ SPI даёт SourceError. Удалённые role definitions не дают прав и видны в doctor. Исключение внутри источника → отказ с причиной `source_error` и запись в лог.
`Volatility::Stable` требует revision contract: dependency revision входит в ключ либо каждое изменение
атомарно вызывает panel touch. Редкость изменений сама по себе недостаточна. `Request` — кэш contributions
на request/job, `Volatile` — перечитывание каждый check. Expiry проверяется во всех режимах, final Allow
не кэшируется только по panel version ([09 §8](09-authorization-semantics.md#8-кэш-и-консистентность)).

Исполнение и lifecycle: registry хранит immutable definitions/factories. Instance с scoped зависимостью
(например CurrentUser) создаётся на request/job, не на boot frozen panel. Stateless instance можно переиспользовать.
`SourceManager::make` не вызывает стандартный `Manager::driver($name)` cache: тот вернул бы один object на разные
панели. Factory получает config выбранной панели и создаёт отдельное instance; тест проверяет это на двух панелях.
Автопоиск `AsSource` в двух папках с одним именем и разным классом — ошибка, а не last-wins.

`ProvidesRoleGrants` — добавочная capability: Folder/Database используют её рядом с direct ProvidesGrants;
Relation предоставляет role contributions. Core разворачивает роли, применяет scope/expiry/conditions и учитывает
superadmin. `ProvidesRoles` описывает определения и не означает назначения.
Для статичной сборки catalog methods получают tenant=null. Dynamic capabilities требуют выбранного TenantRef
(в non-tenant панели явный TenantRef::global()); null не означает все tenants. describe без tenant возвращает
только статичные capabilities/fields, scoped schema добавляет overlay выбранного tenant.
Каждый grant source принимает AccessScope, возвращает scoped contributions; opt-in dynamic permission catalogue читается с выбранным
tenant и validated authority state. Stateless definitions не загружают все tenants при boot.

### 1.3 Свой источник по имени: фабрика

`SourceManager` — `Illuminate\Support\Manager`, как у кэша и файловых систем. Имя регистрируется одним из способов:

```php
// 1. атрибутом на классе — найдётся в app/Guards/Shared/Sources/ и в Sources/ панелей
#[AsSource('ldap')]
final class LdapSource implements Source, ProvidesRoleGrants { … }

// 2. в сервис-провайдере — как Cache::extend() / Storage::extend()
AzGuard::sources()->extend('ldap', fn (Application $app, array $config) => new LdapSource($app->make(LdapClient::class), $config));

// параметры — config/azguard.php
'sources' => [
    'ldap' => ['group_attribute' => 'memberOf', 'map' => ['CN=Support' => 'support']],
],
```

Подключение к панели — всегда явно: `->permissions(['ldap'])`. Неизвестное имя → `UnknownSourceException` при загрузке.
Класс источника создаёт контейнер: внедрение зависимостей и атрибуты контейнера (`#[Config]`, `#[CurrentUser]`)
работают.

## 2. Свой источник

Пример — способности пользовательского Sanctum-токена **сужают** права:

```php
final class TokenAbilitiesRestriction implements Restriction
{
    public function key(): string { return 'acme/token-cap'; }
    public function appliesTo(AccessRequest $r, EvaluationContext $c): bool { return true; }
    public function exemptsSuperAdmin(): bool { return false; }
    public function check(AccessRequest $r, EvaluationContext $c): RestrictionResult
    {
        $user = $c->subjectModel();
        return $user?->tokenCan($r->permission()->full())
            ? RestrictionResult::pass() : RestrictionResult::deny('Token ability required');
    }
}
```

Mapping полного права на token ability задаётся приложением; authentication Sanctum уже подтвердил token validity.
В production adapter обновление/revoke current token перепроверяется согласно live/freshness contract.
`*` в токене значит cap пропускает действия, а не SuperAdmin/grant. SPA Sanctum может возвращать tokenCan=true;
права пользователя и tenant boundaries всё равно проверяются. [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum).
Для отдельного service principal capabilities действительно могут быть **источником**, если authority, tenant,
срок и trust mapping заданы явно; смешивать этот режим с обычным пользовательским token union нельзя.

Пример — внешний code catalogue без базы definitions:

```php
#[AsSource('code-catalogue')]
final class CodeCatalogueSource implements ProvidesPermissions, ProvidesRoles
{
    /** @param list<class-string<UnitEnum>> $permissions
     *  @param list<class-string<BaseRole>> $roles */
    public function __construct(private readonly array $permissions, private readonly array $roles) {}
    public function id(): string { return 'code-catalogue'; }
    public function isDynamic(): bool { return false; } // permission catalogue только build-time
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable { /* compile explicit enum metadata/modes */ }
    public function roles(Panel $panel): iterable { /* resolve validated BaseRole classes at build */ }
}
```

Однородные списки содержат только проверенные enum/role classes; definitions не извлекаются из произвольного
config['roles']['permissions'] behavior языка. Consumer может зарегистрировать этот Source явным объектом.

Своему источнику не нужно ничего, кроме контрактов §1.1: встроенные источники устроены так же.

### 2.1 Связи сущностей

`RelationSource` превращает связь модели в роль внутри сущности без таблиц AzGuard:

```php
->permissions([
    RelationSource::make(Project::class, via: 'members', role: 'pivot.role'),          // участник проекта с ролью в pivot
    RelationSource::make(Store::class, via: 'owner', role: 'owner'),                   // владелец магазина — роль owner
    RelationSource::make(Team::class, via: 'users', role: fn ($pivot) => $pivot->is_lead ? 'lead' : 'member'),
])
```

Статичная роль (`role: 'owner'`) должна существовать на панели (зарегистрированный PHP-класс), иначе ошибка при загрузке.
Значения из pivot проверить при загрузке нельзя: значение, которому нет роли на панели, прав не даёт и видно в doctor
(`panels.relations`). Источник умеет фильтровать запросы (`visibleTo`) через `whereHas` по той же связи.

## 3. Плагины

Плагин — готовый набор дополнений панели: источники, права, роли, ограничения, pipes, поля, проверки doctor
([D47](02-decisions.md#d47)). Источник отвечает на вопрос «откуда права», плагин — «что добавить в панель одним
вызовом».

```php
namespace AzGuard\Contracts\Plugins;

interface Plugin
{
    public function id(): string;
    public function register(PanelBuilder $panel, PluginContext $context): void;
    public function boot(Panel $panel, PluginContext $context): void;
}

interface DependsOnPlugins { /** @return list<string> */ public function requires(): array; }
interface PrefixesKeys { public function prefix(): ?string; }

abstract class BasePlugin implements Plugin, PrefixesKeys   // удобная база
{
    public function prefixed(string $prefix): static;      // clone, как и остальные setters
    public function prefix(): ?string;
}
```

```php
// пакет acme/azguard-audit: обычный Laravel-пакет + плагин панели
final class AuditTrailServiceProvider extends ServiceProvider      // миграции и конфиг — как у любого пакета
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/audit-trail.php' => config_path('audit-trail.php')], 'audit-trail-config');
    }
}

final class AuditTrailPlugin extends BasePlugin
{
    public function id(): string { return 'acme/audit-trail'; }
    private int $retentionDays;
    private function __construct(int $retentionDays) { $this->retentionDays = $retentionDays; }
    public static function make(int $retentionDays = 90): self
    {
        if ($retentionDays < 1) { throw new InvalidArgumentException('retentionDays >= 1'); }
        return new self($retentionDays);
    }
    public function retention(int $days): static
    {
        if ($days < 1) { throw new InvalidArgumentException('retentionDays >= 1'); }
        $copy = clone $this; $copy->retentionDays = $days; return $copy;
    }
    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $retentionDays = $this->retentionDays;
        $panel->changing([function (Change $change, Closure $next) use ($retentionDays) {
            return app()->makeWith(RecordChange::class, ['retentionDays' => $retentionDays])->handle($change, $next);
        }])->doctorChecks([AuditTableExists::class]); // pipe получает $change->context(), пишет в той же transaction
    }
    public function boot(Panel $panel, PluginContext $context): void
    {
        Event::listen(GrantExpired::class, fn (GrantExpired $e) => $e->panel === $panel->id() ? AuditLog::expired($e) : null);
    }
}
```

Жизненный цикл: провайдеры панелей и `configurePanel()`/`configurePanels()` собирают `PanelBuilder` → `register()`
каждого плагина в порядке подключения → сборка источников (§1.2) и проверки → заморозка → `boot()`.

Если плагин реализует `PrefixesKeys`, `PanelBuilder` добавляет префикс к локальным именам прав и ролей этого плагина:
`posts.edit` → `blog.posts.edit`, роль `editor` → `blog-editor`. Префикс панели (`->resourcePrefix()` на панели, D05)
добавляется снаружи: `admin.blog.posts.edit`.

Правила: один плагин можно подключить к нескольким панелям, каждый экземпляр видит свою; конфликт двух плагинов по
одной настройке → `PluginConflictException`; плагин не отключает гарантии D45; порядок плагинов входит в отпечаток
панели (кэш не отдаст результат, посчитанный при другой конфигурации). Встроенный плагин один — `azguard/audit`
(журнал изменений, выключен по умолчанию); встроенные источники — не плагины, а источники.

## 4. Хуки проверки

```php
// before и after — как у Laravel Gate: замыкание или invokable-класс (создаётся контейнером)
->before(fn (AccessRequest $r, EvaluationContext $c): BeforeResult =>
    $c->subjectModel()?->is_frozen ? BeforeResult::Deny : BeforeResult::Continue)
->after(fn (AccessRequest $r, Decision $d) => Metrics::decision($r, $d))

namespace AzGuard\Contracts\Authorization;

interface Restriction
{
    public function key(): string;
    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool;
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult;   // pass | deny(reason)
    public function exemptsSuperAdmin(): bool;                                // по умолчанию false: действует и на суперадмина
}
```

Порядок: owner/common eligibility → BeforeResult checks → selected authority (PolicyOnly policy либо qualifying Grants sources + optional veto) → restrictions → after
([09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм)).

- `before`: Deny — отказ, в том числе для scoped superadmin; Continue — продолжить выбранный authority path;
  возврат bool/null не является допустимым BeforeResult.
- `restrictions`: пользователь заблокирован, режим «только чтение», нерабочее время, не сотрудник магазина. Проверяет
  любое «да», включая суперадмина, если не освободил его (`exemptsSuperAdmin()`). Исключение → отказ.
- `after`: метрики, журнал отказов. Исключение → лог, решение не меняется.

## 5. Хуки изменений: pipes и события

```php
// pipe — как middleware или pipe Illuminate\Pipeline: handle(Change $change, Closure $next)
final class RequireReason
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        if ($change->type->isGrant() && blank($change->fields['reason'] ?? null)) {
            $change->cancel('Укажите причину выдачи');           // ChangeCancelledException
        }
        return $next($change);
    }
}

final readonly class Change
{
    public function context(): ChangeContext; // actor/target user/BaseRole/proposed values/operation; fresh derived input
    public ChangeType $type;              // GrantRole | RevokeRole | GrantPermission | RevokePermission | UpdateGrant | CreatePermission | UpdatePermission | DeletePermission
    public string $panel; public ?SubjectRef $subject; public ?RoleKey $role; public ?PermissionPattern $permission;
    public AccessScope $scope; public string $origin; public ?DateTimeImmutable $until; public array $fields; public ?ActorRef $actor;
    public function withUntil(?DateTimeImmutable $until): self;
    public function withFields(array $fields): self; // only schema-declared values
    public function cancel(string $reason): never;
}
```

Pipes выполняются под state lock внутри mutation до записи (`->changing([...])`, плагины, `configurePanels()`), в порядке регистрации. После pipes движок повторно валидирует финальный Change; identity/scope/actor/origin pipe не меняет. После
commit — обычные Laravel-события (`RoleGranted`, `PermissionRevoked`, …, [08 §6](08-data-model-and-migration.md#6-каталог-событий))
со слушателями; отдельного хука «после изменения» нет.

Рецепты (в документации, не во встроенном коде — [D23](02-decisions.md#d23)):

```php
// Delegation — policy приложения, проверяет actor и target AccessScope, включая роль/шаблон/superadmin.
final class AuthorizeAccessChange
{
    public function __construct(private DelegationPolicy $policy) {}
    public function handle(Change $change, Closure $next): ChangeResult
    {
        if (! $this->policy->allows($change->actor, $change)) {
            $change->cancel('Выдача в этом tenant/project не разрешена');
        }
        return $next($change);
    }
}

// При проверке pattern policy расширяет его по каталогу и отдельно учитывает будущие действия namespace.
// hasPermission('orders.*') не используется: pattern — выдача, а не проверяемое действие.

// «Срок по умолчанию — 90 дней»
->changing([fn (Change $c, Closure $next) => $next($c->type === ChangeType::GrantRole && ! $c->until ? $c->withUntil(now()->addDays(90)->toDateTimeImmutable()) : $c)])

// «Изменения ролей админки подтверждает второй человек» — pipe сохраняет заявку в свою таблицу и отменяет
// изменение; после подтверждения заявка применяется обычным вызовом $user->guard('admin')->grantRole(...)
```

## 6. Свои модели и поля

1. Модель задаётся у `DatabaseSource` (`->models(roleGrant: AdminRoleGrant::class)`), лежит в `Models/` папки панели и
   наследует базовую модель того же вида; ядро проверяет это при сборке панели. Атрибуты Eloquent (`#[Table]`,
   `#[Connection]`, `#[ObservedBy]`) работают; хранилище сверяет таблицу и соединение.
2. Идентификационные колонки и методы не переопределяются (`final` в базовой модели).
3. Поля: настоящие колонки (миграция) или `meta` (JSON, nullable).
4. Описание полей — `public static function azguardFields(): array` в модели. Из него берутся правила проверки, схема
   панели и формы Filament.
5. Поля, участвующие в решении, перечисляются в `DatabaseSource::make()->decisionFields(...)`; только они загружаются
   вместе с выдачами и кэшируются.

```php
final class AdminRoleGrant extends RoleGrant
{
    protected $casts = ['meta' => AsArrayObject::class];

    public static function azguardFields(): array
    {
        return [
            Field::model('department_id', Department::class)->label('Отдел')->required(),   // колонка
            Field::enum('weekdays', Weekday::class)->multiple()->label('Дни работы')->inMeta(), // в meta
        ];
    }
}

final class WeekdaysCondition implements GrantCondition
{
    public function allows(Grant|RoleContribution $g, AccessRequest $r, EvaluationContext $c): bool
    {
        $days = $g->fields()['weekdays'] ?? null;
        return $days === null || in_array((int) $c->now()->format('N'), $days, true);
    }
}
```

GrantCondition принимает Grant или RoleContribution; fields() имеет один shape на обоих значениях.
GrantCondition квалифицирует одну grant до OR всех grants; department+weekdays должны подойти у одной строки.
Для exact visibleTo condition дополнительно предоставляет query predicate **в этой ветке выдачи**.
Panel-wide Restriction не используется вместо условий одной строки (D61).


## 7. Контексты и субъекты

Контракт **ProjectScope** и других классов типов областей — `@spi`, принадлежит ядру:

```php
namespace AzGuard\Contracts\Scopes;

interface AssignmentScopeDefinition
{
    public function type(): string; // стабильный зарегистрированный alias, уникальный в панели
    /** @return class-string<Model>|null */ public function model(): ?string;
    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope;
}

interface QueryableAssignmentScopeDefinition extends AssignmentScopeDefinition
{
    public function query(): Builder; // fresh structural query, до common/role filters
    public function tenantOf(Model $record): TenantRef; // owner уже загруженной записи
}

final readonly class ResolvedAssignmentScope
{
    public function __construct(
        public AssignmentScopeRef $ref,
        public TenantRef $tenant,
        public ?Model $record = null,
    ) {}
}

interface ConfigurableAssignmentScopeDefinition extends AssignmentScopeDefinition
{
    public function filter(AssignmentScopeFilter|string|Closure $filter): static; // string = validated class-string<AssignmentScopeFilter>
    public function label(string $label): static;
    public function directory(string $class): static;
    public function settings(): AssignmentScopeSettings;
}
interface AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void;
}
interface AssignmentScopeAccessAdapter
{
    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool;
    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array;
    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder;
}
// Adapter query относится к модели context; core ставит outer identity/owner predicates.

interface ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope;
}
interface ProvidesAccessScope { public function azguardScope(): AccessScope; }
interface TenantMembership { public function isMember(SubjectRef $subject, TenantRef $tenant): bool; }
interface TenantResolver { public function resolve(Request $request): ?TenantRef; }
interface TenantDirectory
{
    public function search(string $term, LookupContext $lookup, int $limit): array;
    public function describe(TenantRef $tenant, LookupContext $lookup): ?TenantOption;
}
interface AssignmentScopeDirectory
{
    public function search(string $type, string $term, LookupContext $lookup, int $limit): array;
    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption;
}
interface SubjectDirectory
{
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array;
    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption;
}
interface AssignmentScopeResolver { public function resolve(Request $request): ?AssignmentScopeRef; }
interface AssignmentScopeMembership { public function isMember(SubjectRef $subject, AssignmentScopeRef $context): bool; }
interface SubjectResolver { public function resolve(mixed $subject): SubjectRef; public function model(SubjectRef $ref): ?Model; }
interface ProvidesAssignmentScope { public function azguardAssignmentScope(): ?AssignmentScopeRef; } // только non-tenant shortcut
```

query(): Builder — structural набор, filter(...) — predicate configuration; сигнатуры не перегружены.
BaseAssignmentScope.resolve(ref) проверяет type, использует fresh query()->whereKey(ref.id())->first(),
выводит модель и tenant из этой же записи. Core сверяет returned ref/model key/tenant с запросом;
null означает отсутствие, exception — отказ. query не вызывается дважды для existence + owner.
Filters применяются отдельно; исходный Builder/connection не хранится между requests. Внешний descriptor
реализует resolve(ref) сам и может возвращать snapshot с record=null; дальнейшие model-required policies
требуют явного загрузчика, отсутствие модели не заменяется null argument.

`AzGuard\Scopes\BaseAssignmentScope implements ConfigurableAssignmentScopeDefinition, QueryableAssignmentScopeDefinition` — удобная база. `ProjectScope` лежит
в `Scopes/` панели; FolderSource регистрирует его, AssignmentScopePolicy подключает явно.
`BaseRole::scopes()` возвращает classes или configured recipes; bindings находятся в PHP, stored grants хранят aliases;
empty contexts разрешает только tenant-wide выдачу. `scopeRequired=true` запрещает global context.
Doctor сверяет binding классов и aliases. Role/context FQCN не сохраняются в grants.

Non-tenant `AssignmentScopePolicy::inherit(Project::class)` — shortcut через ModelAssignmentScopeDefinition с global TenantRef.
В tenant-панели нужен descriptor или явный owner resolver; одного имени модели недостаточно.
`ContextAware` умеет legacy azguardContext для non-tenant и `ProvidesAccessScope` для tenant resource.
Непринятый AssignmentScopeRef не игнорируется. External descriptor с model=null допускает refs, но policy с обязательной
Eloquent-моделью требует отдельного ModelResolver; без него сборка binding отклоняется.

`TenantPolicy::required(Organization::class)` использует зарегистрированный ModelTenantDefinition/morph alias;
`requireMembership(TenantMembership::class)` включён для required по умолчанию, отсутствие adapter — ошибка.
`allowGlobalRoles([RootRole::class])` — явный список платформенных ролей. Этот список не отменяет owner boundary.
Directories — поиск для UI, не только отображение. LookupContext явно несёт actor, target subject/BaseRole/proposed values, panel/tenant/phase; LIMIT и ограничения
предикатов применяются до выдачи результатов. Перечисления contexts/tenant types в schema — definitions,
а не все объекты всех организаций.

### Условия выдач и точная видимость

```php
namespace AzGuard\Contracts\Authorization;
interface GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool;
}
interface FiltersAccessQueries
{
    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): AccessPredicate;
}
```

AccessPredicate — pure descriptor expression tree с bound values, без Eloquent builder. Для boolean condition/
restriction он описывает allow/deny; для policy/before — три непересекающихся предиката allow/deny/abstain,
в сумме покрывающих допустимые строки. NULL SQL не означает автоматически abstain: adapter нормализует его явно.
Константные pass/deny/abstain и unsupported тоже выражаются descriptor. Laravel adapter строит builder.
Для GrantCondition contribution обязателен: это одна исходная выдача, включая RoleContribution; plan соединяет
её условия внутри одной ветки, не позволяет adapter брать поля другой выдачи. Для остальных компонентов null. Политика, restriction, before hook и condition могут реализовать этот
дополнительный интерфейс; одной `FiltersQueries` источника недостаточно для final visibility.
Поддержка объявляется для конкретного права/типа ресурса/scope. Unsupported любого влияющего компонента
в exact режиме -> VisibilityNotSupportedException. GrantCondition predicate прикладывается к одной grant ветке,
policy predicate выражает null/permit/deny семантику в том же плане (D66).

## 8. Модули и сторонние пакеты внутри приложения

```php
// Modules/Blog/Providers/BlogServiceProvider.php
public function register(): void
{
    // вариант 1: своя панель модуля
    AzGuard::registerPanel(BlogGuardPanelProvider::class);

    // вариант 2: дополнить панель, которую выбрало приложение (id из конфига модуля, не зашит)
    AzGuard::configurePanel(config('blog.azguard_panel', 'admin'), fn (PanelBuilder $panel) =>
        $panel->plugins([BlogAccessPlugin::make()->prefixed('blog')]));
}

// Modules/Blog/Guards/BlogAccessPlugin.php — плагин приносит домены модуля из своей папки
public function register(PanelBuilder $panel, PluginContext $context): void
{
    $panel->discover(__DIR__);        // Permissions/Posts, Policies/Posts; Roles/ — та же структура, что у панели
}
```

`prefixed('blog')` превращает `posts.edit` модуля в `blog.posts.edit` панели — модули не сталкиваются.
`azguard:panels:list --sources` показывает, кто что принёс.

## 9. Doctor

```php
interface DoctorCheck { public function key(): string; /** @return iterable<DoctorFinding> */ public function run(DoctorContext $context): iterable; }
```

Проверки приносят источники (`ChecksHealth`), плагины и панель (`->doctorChecks([...])`). Каталог проверок ядра —
[12 §2](12-operations-and-release.md#2-doctor-проверки).

## 10. Контрактные наборы (`AzGuard\Testing\Contracts\`)

| Набор | Для кого | Что гарантирует |
|---|---|---|
| `SourceContractTests` | авторы источников | только своя панель; одинаковый ответ на одной версии; уважает сроки и `now`; `describe()` совпадает с тем, что источник реально даёт; писатель пишет только внутри транзакции пайплайна |
| `PluginContractTests` | авторы плагинов | плагин собирается на чистой панели и на двух панелях; не меняет панель в `boot()`; отключается без побочных эффектов |
| `RestrictionContractTests` | авторы ограничений | нет записи; исключение ≠ pass |
| `HookContractTests` | авторы хуков и pipes | `before` без побочных эффектов; pipe не пишет в БД в обход писателя; отменённое изменение не оставляет следов |
| `SubjectResolverContractTests`, `AssignmentScopeResolverContractTests` | авторы резолверов | идемпотентность, кодек, нет `:` в типе |
| `IntegrationContractTests` | пакеты-интеграции ([10](10-integrations.md)) | решение одинаково через трейт, `decideMany` и Gate; `StateToken` меняется при изменении; события после commit |

## 11. Истории расширения — проверка, что границы достаточно

| История | Что пишет автор | Что меняется в ядре |
|---|---|---|
| Кабинет на жёстких правилах, админка на БД | два провайдера; у админки `DatabaseSource` | ничего |
| Кабинет продавца: автоматическая роль + доступ к своим магазинам | роль с `GrantedAutomatically` в `Roles/` + `RelationSource` | ничего |
| Право «смотреть заказ» решает политика, «смотреть все заказы» выдаётся в БД | метод политики домена + `granted()` | ничего |
| Выдано в БД, но с 18:00 до 9:00 нельзя | метод политики вернёт `false` вне часов (второй уровень) | ничего |
| Перенос права из кода в БД, чтобы его выдавали из админки | убрать `#[GrantedToAll]`, добавить `DatabaseSource` | ничего; код проверок тот же |
| Права, созданные в админке без релиза | `DatabaseSource::make()->dynamicPermissions()` | ничего |
| Панель API пользователя, ограниченная токеном | grants пользователя + TokenAbilitiesRestriction; service principal capabilities отдельно | ничего |
| LDAP-группы → роли | свой источник с `#[AsSource('ldap')]` (`Volatility::Request`) | ничего |
| Права у проектов (тариф) | панель `features` с `for(model: Project::class)` + свой источник | ничего |
| Модуль Blog со своими правами в админке | плагин с `discover(__DIR__)` + `configurePanel()` | ничего |
| Поле `department_id` у выдачи роли и правило «только свой отдел» | модель в `Models/` + `azguardFields()` + `Restriction` | ничего |
| Подтверждение изменений вторым человеком | pipe `changing` + своя таблица заявок | ничего |
| CRM: CallerRole/AnalystRole/optional dynamic action на Projects разных Organizations | ProjectScope implements AssignmentScopeDefinition + TenantPolicy + scoped grants + ClientPolicy/query adapter | ничего |
| «Только сотрудники магазина» | `AssignmentScopeMembership` + `requireMembership()` | ничего |
| Суперадмины во всех панелях по флагу пользователя | `RootRole` с `#[SuperAdmin]` в `Shared/Roles/` + `AzGuard::configurePanels(fn ($p) => $p->roles([RootRole::class]))` | ничего |
| Суперадмин одного магазина | роль с `#[SuperAdmin]`, выданная `on: $store` | ничего |
| Отдельная БД для прав админки | `DatabaseSource::make()->storage(Storage::own(connection: 'backoffice'))` + `azguard:storage:migration` | ничего |
| Интеграция стороннего пакета (Vaulter и др.) | свой пакет: плагин + вызовы `decideMany` + `IntegrationContractTests` | ничего |
| Альтернативное хранилище выдач (не Eloquent) | **не в 1.0**: внешние данные читаются своим источником; писатель в 1.0 — `DatabaseSource` | после 1.0 — свой `StoresGrants` |

## 12. Уровни гибкости

Чем глубже уровень, тем больше можно изменить; ядро не меняется ни на одном.

| Уровень | Что меняют | Чем | Пример |
|---|---|---|---|
| 1. Конфиг | значения по умолчанию для всех панелей | `config/azguard.php` | кэш, имена подпапок, хранилище по умолчанию |
| 2. Провайдер панели | из чего собрана панель | `->permissions()`, `->restrictions()`, `->changing()`, `->plugins()` | админка на БД, кабинет без БД |
| 3. Папка панели | права, роли, политики | enum, классы и атрибуты в папке | новая группа `Permissions/Invoices` + `Policies/Invoices` |
| 4. Свой класс | новое поведение в одной точке | свой источник, ограничение, pipe, хук | LDAP, «рабочие часы», «нельзя выдавать больше своего» |
| 5. Плагин | готовый набор для многих панелей и приложений | `Plugin` + Laravel-пакет | журнал изменений, модуль Blog |
| 6. Пакет-интеграция | чужой пакет опирается на AzGuard | `@api`/`@spi` + контрактные тесты | мост Vaulter |

## 11. Явные inputs расширений и настройки контекстов

[18](18-contexts-and-runtime-inputs.md) задаёт AssignmentScopeRuntime/LookupContext/ChangeContext,
[19](19-oop-and-permission-authority.md) — строгий OOP API и authority modes.
Плагин получает конфигурацию через собственные именованные typed parameters, не через общий options bag.
filter принимает объект AssignmentScopeFilter, exact filter class-string или Closure; string profile registry отсутствует.
BaseRole — реальный класс; настройки role/context в PHP, в БД только назначения. Сервисы фильтра из container
разрешаются на operation, build recipe не удерживает User/Request/Builder.
Pipeline остаётся handle(Change, Closure next); ChangeContext пересоздаётся после with/final validation/retry.
