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
    public function permissions(Panel $panel): iterable;
    public function isDynamic(): bool;                              // false — при сборке, в catalog:cache; true — меняется во время работы
}

interface ProvidesRoles extends Source                              // роли панели
{
    /** @return iterable<RoleDefinition> */
    public function roles(Panel $panel): iterable;
    public function isDynamic(): bool;
}

interface ProvidesGrants extends Source                             // первый уровень: что выдано субъекту
{
    /** @param list<ContextRef> $contexts @return iterable<Grant> */
    public function grants(SubjectRef $subject, array $contexts, EvaluationContext $context): iterable;
    public function volatility(): Volatility;                       // Stable | Request | Volatile
}

interface ProvidesPolicies extends Source                           // второй уровень: код, уточняющий выдачи
{
    /** @return iterable<PolicyBinding> право → метод политики или ability Gate */
    public function policies(Panel $panel): iterable;
}

interface StoresGrants extends Source                               // писатель панели; не больше одного на панели
{
    public function apply(Change $change): ChangeResult;            // вызывает пайплайн изменений внутри транзакции
    public function transaction(Closure $callback): mixed;          // транзакция на соединении источника
}

interface FiltersQueries extends Source                             // для visibleTo(): какие сущности типа $type дают право
{
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?ContextSelection;
}

interface DescribesSchema extends Source                            // для схемы панели (D54): что может дать, свои поля
{
    public function describe(Panel $panel): SourceDescription;
}

interface ChecksHealth extends Source                               // проверки doctor
{
    /** @return list<DoctorCheck> */
    public function doctorChecks(): array;
}

interface EvaluationContext                     // @api — что движок даёт источникам, хукам, ограничениям
{
    public function panel(): Panel;
    public function contexts(): array;          // применимые ContextRef
    public function resource(): ?object;
    public function state(): StateToken;
    public function now(): DateTimeImmutable;   // одно значение на проверку / на decideMany
    public function subjectModel(): ?Model;     // ленивая загрузка
    /** @return list<Grant> выдачи, покрывающие запрошенное право (с decisionFields) */
    public function matchingGrants(): array;
}
```

| Встроенный источник | Возможности |
|---|---|
| `FolderSource` | `ProvidesPermissions` (статичные), `ProvidesRoles` (статичные), `ProvidesGrants` (автоматические роли, `#[GrantedToAll]`), `ProvidesPolicies` (политики доменов), `DescribesSchema`, `ChecksHealth` |
| `DatabaseSource` | `ProvidesPermissions` (динамические), `ProvidesRoles` (динамические), `ProvidesGrants`, `StoresGrants`, `FiltersQueries`, `DescribesSchema`, `ChecksHealth` |
| `RelationSource` | `ProvidesGrants`, `FiltersQueries`, `DescribesSchema` |
| `GateSource` | `ProvidesPolicies`, `DescribesSchema` |

### 1.2 Как панель собирает источники

1. `FolderSource` есть всегда и идёт первым: папка провайдера, папки из `->discover()`, классы из `->permissions()`,
   `->roles()`, `->policies()`.
2. Затем источники из `->sources([...])` в порядке перечисления; плагины добавляют свои в `register()`.
3. Имя вместо объекта (`'ldap'`) разрешает `SourceManager` (§1.3); для каждой панели создаётся свой экземпляр.
4. Проверки сборки: `id()` источников не повторяются; права и роли разных источников не сталкиваются
   (`DuplicatePermissionException`, `DuplicateRoleException` с id источников); писатель не больше одного
   (`WriterConflictException`); роли, на которые ссылаются выдачи (`RelationSource`), существуют.
5. Заморозка: статичная часть каталога и привязки политик попадают в `azguard:catalog:cache`.

Правила выдач: источник не может дать право другой панели или голую звёздочку — такие выдачи отбрасываются с
предупреждением. Исключение внутри источника → отказ с причиной `source_error` и запись в лог.
`Volatility::Stable` — данные меняются только через AzGuard (кэш между запросами по версии панели); `Request` — кэш
на запрос; `Volatile` — без кэша (внешняя система, токен).

### 1.3 Свой источник по имени: фабрика

`SourceManager` — `Illuminate\Support\Manager`, как у кэша и файловых систем. Имя регистрируется одним из способов:

```php
// 1. атрибутом на классе — найдётся в app/Guards/Shared/Sources/ и в Sources/ панелей
#[AsSource('ldap')]
final class LdapSource implements Source, ProvidesGrants { … }

// 2. в сервис-провайдере — как Cache::extend() / Storage::extend()
AzGuard::sources()->extend('ldap', fn (Application $app, array $config) => new LdapSource($app->make(LdapClient::class), $config));

// параметры — config/azguard.php
'sources' => [
    'ldap' => ['group_attribute' => 'memberOf', 'map' => ['CN=Support' => 'support']],
],
```

Подключение к панели — всегда явно: `->sources(['ldap'])`. Неизвестное имя → `UnknownSourceException` при загрузке.
Класс источника создаёт контейнер: внедрение зависимостей и атрибуты контейнера (`#[Config]`, `#[CurrentUser]`)
работают.

## 2. Свой источник

Пример — права из способностей Sanctum-токена для панели `api`:

```php
final class TokenAbilitiesSource implements ProvidesGrants, DescribesSchema
{
    public function id(): string { return 'token-abilities'; }
    public function volatility(): Volatility { return Volatility::Volatile; }
    public function grants(SubjectRef $subject, array $contexts, EvaluationContext $context): iterable
    {
        $token = $context->subjectModel()?->currentAccessToken();
        foreach ($token?->abilities ?? [] as $ability) {
            yield Grant::pattern($context->panel()->id(), $ability, source: $this->id());
        }
    }
    public function describe(Panel $panel): SourceDescription { … }
}
```

Пример — права и роли из конфиг-файла (для маленьких приложений без БД):

```php
#[AsSource('config')]
final class ConfigSource implements ProvidesPermissions, ProvidesRoles
{
    public function __construct(#[Config('azguard-roles')] private array $config) {}
    public function id(): string { return 'config'; }
    public function isDynamic(): bool { return false; }
    public function permissions(Panel $panel): iterable { /* PermissionDefinition из $config[$panel->id()]['permissions'] */ }
    public function roles(Panel $panel): iterable { /* RoleDefinition из $config[$panel->id()]['roles'] */ }
}
```

Своему источнику не нужно ничего, кроме контрактов §1.1: встроенные источники устроены так же.

### 2.1 Связи сущностей

`RelationSource` превращает связь модели в роль внутри сущности без таблиц AzGuard:

```php
->sources([
    RelationSource::make(Project::class, via: 'members', role: 'pivot.role'),          // участник проекта с ролью в pivot
    RelationSource::make(Store::class, via: 'owner', role: 'owner'),                   // владелец магазина — роль owner
    RelationSource::make(Team::class, via: 'users', role: fn ($pivot) => $pivot->is_lead ? 'lead' : 'member'),
])
```

Статичная роль (`role: 'owner'`) должна существовать на панели (из папки или из БД), иначе ошибка при загрузке.
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
    public function id(): string;                           // 'vendor/name'
    public function register(PanelBuilder $panel): void;   // добавить в панель (до заморозки)
    public function boot(Panel $panel): void;               // после заморозки: слушатели, связи; менять панель нельзя
}

interface DependsOnPlugins { /** @return list<string> */ public function requires(): array; }
interface PrefixesKeys { public function prefix(): ?string; }

abstract class BasePlugin implements Plugin, PrefixesKeys   // удобная база
{
    public static function make(): static;                  // app(static::class): зависимости из контейнера
    public function prefixed(string $prefix): static;      // BlogAccessPlugin::make()->prefixed('blog')
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
    private int $days = 365;
    public function id(): string { return 'acme/audit-trail'; }
    public function retention(int $days): static { $this->days = $days; return $this; }
    public function register(PanelBuilder $panel): void
    {
        $panel->changing([RecordChange::class])             // запись журнала в той же транзакции
              ->doctorChecks([AuditTableExists::class]);
    }
    public function boot(Panel $panel): void
    {
        Event::listen(GrantExpired::class, fn (GrantExpired $e) => $e->panel === $panel->id() ? AuditLog::expired($e) : null);
    }
}
```

Жизненный цикл: провайдеры панелей и `configurePanel()`/`configurePanels()` собирают `PanelBuilder` → `register()`
каждого плагина в порядке подключения → сборка источников (§1.2) и проверки → заморозка → `boot()`.

Если плагин реализует `PrefixesKeys`, `PanelBuilder` добавляет префикс к локальным именам прав и ролей этого плагина:
`posts.edit` → `blog.posts.edit`, роль `editor` → `blog-editor`. Префикс панели (`->prefixed()` на панели, D05)
добавляется снаружи: `admin.blog.posts.edit`.

Правила: один плагин можно подключить к нескольким панелям, каждый экземпляр видит свою; конфликт двух плагинов по
одной настройке → `PluginConflictException`; плагин не отключает гарантии D45; порядок плагинов входит в отпечаток
панели (кэш не отдаст результат, посчитанный при другой конфигурации). Встроенный плагин один — `azguard/audit`
(журнал изменений, выключен по умолчанию); встроенные источники — не плагины, а источники.

## 4. Хуки проверки

```php
// before и after — как у Laravel Gate: замыкание или invokable-класс (создаётся контейнером)
->before(fn (AccessRequest $r, EvaluationContext $c): ?bool => $c->subjectModel()?->is_frozen ? false : null)
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

Порядок: before-хуки → суперадмин → выдачи (первый уровень) → политика (второй уровень) → ограничения → after-хуки
([09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм)).

- `before`: «нет» — окончательный отказ, в том числе для суперадмина; «да» — разрешение, которое ещё проверят
  ограничения; «не знаю» (`null`) — дальше.
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
    public ChangeType $type;              // GrantRole | RevokeRole | GrantPermission | RevokePermission | CreateRole | UpdateRole | DeleteRole | SyncRolePermissions | CreatePermission | DeletePermission
    public string $panel; public ?SubjectRef $subject; public ?RoleKey $role; public ?PermissionPattern $permission;
    public ?ContextRef $context; public ?DateTimeImmutable $until; public array $fields; public ?ActorRef $actor;
    public function with(array $changes): self;
    public function cancel(string $reason): never;
}
```

Pipes выполняются до записи (`->changing([...])`, плагины, `configurePanels()`), в порядке регистрации. После
commit — обычные Laravel-события (`RoleGranted`, `PermissionRevoked`, …, [08 §6](08-data-model-and-migration.md#6-каталог-событий))
со слушателями; отдельного хука «после изменения» нет.

Рецепты (в документации, не во встроенном коде — [D23](02-decisions.md#d23)):

```php
// «Нельзя выдать то, чего нет у тебя»
final class NoEscalation
{
    public function __construct(#[CurrentUser] private ?User $actor) {}
    public function handle(Change $change, Closure $next): ChangeResult
    {
        if ($change->permission && $this->actor && ! $this->actor->isSuperAdmin()
            && ! $this->actor->hasPermission($change->permission->full())) {
            $change->cancel('Можно выдавать только свои права');
        }
        return $next($change);
    }
}

// «Срок по умолчанию — 90 дней»
->changing([fn (Change $c, Closure $next) => $next($c->type === ChangeType::GrantRole && ! $c->until ? $c->with(['until' => now()->addDays(90)]) : $c)])

// «Изменения ролей админки подтверждает второй человек» — pipe сохраняет заявку в свою таблицу и отменяет
// изменение; после подтверждения заявка применяется обычным вызовом $user->inPanel('admin')->grantRole(...)
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

final class WeekdaysRestriction implements Restriction
{
    public function key(): string { return 'acme/weekdays'; }
    public function appliesTo(AccessRequest $r, EvaluationContext $c): bool { return true; }
    public function check(AccessRequest $r, EvaluationContext $c): RestrictionResult
    {
        foreach ($c->matchingGrants() as $grant) {
            $days = $grant->fields()['weekdays'] ?? null;
            if ($days === null || in_array($c->now()->format('N'), $days, true)) {
                return RestrictionResult::pass();
            }
        }
        return RestrictionResult::deny('Сегодня не рабочий день по условиям выдачи');
    }
}
```

## 7. Контексты и субъекты

```php
interface ContextResolver   { public function resolve(Request $request): ?ContextRef; }                     // текущая сущность запроса
interface ContextMembership { public function isMember(SubjectRef $subject, ContextRef $context): bool; }  // «сотрудник ли этого магазина»
interface ContextDirectory  { public function search(string $type, string $term, int $limit): array; public function describe(ContextRef $c): ?ContextOption; }
interface SubjectResolver   { public function resolve(mixed $subject): SubjectRef; public function model(SubjectRef $ref): ?Model; }
interface SubjectDirectory  { public function search(string $term, int $limit, ?string $type = null): array; public function describe(SubjectRef $ref): ?SubjectOption; }
interface ProvidesContext   { public function azguardContext(): ?ContextRef; }       // ресурс сообщает свою сущность ($order → store)
```

Трейт `Contexts\ContextAware` на моделях-сущностях и ресурсах реализует `ProvidesContext` (сущность возвращает себя,
ресурс переопределяет: `return $this->store`) и даёт scope `visibleTo()`.

По умолчанию: резолвер модели, директория по моделям субъектов панели, `RouteParameterResolver` для сущности из
параметра маршрута. `ContextMembership` можно задать классом, связью (`StoreStaff::viaRelation('staff')`) или
замыканием в провайдере.

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
public function register(PanelBuilder $panel): void
{
    $panel->discover(__DIR__);        // Posts/Permissions, Posts/Policies, Roles/ — та же структура, что у панели
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
| `SubjectResolverContractTests`, `ContextResolverContractTests` | авторы резолверов | идемпотентность, кодек, нет `:` в типе |
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
| Панель API без ролей, права из токена | свой источник (`Volatility::Volatile`) | ничего |
| LDAP-группы → роли | свой источник с `#[AsSource('ldap')]` (`Volatility::Request`) | ничего |
| Права у проектов (тариф) | панель `features` с `subjects([Project::class])` + свой источник | ничего |
| Модуль Blog со своими правами в админке | плагин с `discover(__DIR__)` + `configurePanel()` | ничего |
| Поле `department_id` у выдачи роли и правило «только свой отдел» | модель в `Models/` + `azguardFields()` + `Restriction` | ничего |
| Подтверждение изменений вторым человеком | pipe `changing` + своя таблица заявок | ничего |
| «Только сотрудники магазина» | `ContextMembership` + `requireMembership()` | ничего |
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
| 2. Провайдер панели | из чего собрана панель | `->sources()`, `->restrictions()`, `->changing()`, `->plugins()` | админка на БД, кабинет без БД |
| 3. Папка панели | права, роли, политики | enum, классы и атрибуты в папке | новый домен `Invoices/` с политикой |
| 4. Свой класс | новое поведение в одной точке | свой источник, ограничение, pipe, хук | LDAP, «рабочие часы», «нельзя выдавать больше своего» |
| 5. Плагин | готовый набор для многих панелей и приложений | `Plugin` + Laravel-пакет | журнал изменений, модуль Blog |
| 6. Пакет-интеграция | чужой пакет опирается на AzGuard | `@api`/`@spi` + контрактные тесты | мост Vaulter |
