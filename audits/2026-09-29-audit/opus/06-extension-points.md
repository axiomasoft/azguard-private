# 06 — Расширение: плагины, механики, хуки, свои модели, модули

Решения: [D14](02-decisions.md#d14), [D20](02-decisions.md#d20), [D23](02-decisions.md#d23), [D36](02-decisions.md#d36),
[D39](02-decisions.md#d39), [D46](02-decisions.md#d46)–[D55](02-decisions.md#d55).

Всё в этом файле — `@spi`: контракты, которые реализуют авторы плагинов, механик, хуков и интеграций. Правило одно:
**встроенные механики AzGuard написаны на этих же контрактах** (arch-тест). Значит, всё, что умеет AzGuard, может
сделать и сторонний автор.

## 1. Плагин

```php
namespace AzGuard\Contracts\Plugins;

interface Plugin
{
    public function id(): string;                           // 'vendor/name'
    public function register(PanelBuilder $panel): void;   // объявить вклад (до заморозки)
    public function boot(Panel $panel): void;               // рантайм-связи после заморозки; менять панель нельзя
}

interface DependsOnPlugins { /** @return list<string> */ public function requires(): array; }
interface PrefixesKeys { public function prefix(): ?string; }

abstract class BasePlugin implements Plugin, PrefixesKeys   // удобная база
{
    public static function make(): static;
    public function keyPrefix(string $prefix): static;      // BlogAccessPlugin::make()->keyPrefix('blog')
    public function prefix(): ?string;
}
```

Жизненный цикл: провайдеры панелей и `configurePanel()`/`configurePanels()` собирают `PanelBuilder` → `register()`
каждого плагина в порядке подключения → проверки сборки (коллизии, зависимости, хранилище, модели) → заморозка →
`boot()`.

В `register()` плагину доступны все методы `PanelBuilder` ([05 §4](05-php-api.md#4-описание-панели-panelprovider-и-panelbuilder)):
права, роли, механики, политики, хуки, модели, поля, doctor-проверки, настройки. Если плагин реализует
`PrefixesKeys`, `PanelBuilder` добавляет префикс к локальным именам прав и ролей этого плагина: `posts.edit` →
`blog.posts.edit`, роль `editor` → `blog-editor`.

Правила: один плагин можно подключить к нескольким панелям; конфликт двух плагинов по одной настройке →
`PluginConflictException`; плагин не отключает гарантии D45; порядок плагинов входит в отпечаток панели (кэш не
отдаст результат, посчитанный при другой конфигурации).

### 1.1 Встроенные плагины

| id | Что даёт | Как подключается |
|---|---|---|
| `azguard/code` | права всем (`grantToAll`), роли из кода, автоматические роли | всегда, если на панели есть роли из кода или `grantToAll` |
| `azguard/policies` | Laravel Policy и Gate как механика | `->policies()`, `->discoverPolicies()`, `->gates()` |
| `azguard/database` | роли в БД, назначения, прямые права | `->database()` или `defaults.database = true` в конфиге |
| `azguard/relations` | права из связей сущностей | `->relation(...)` |
| `azguard/audit` | журнал изменений в той же транзакции | `->plugin(AuditPlugin::make())` |

## 2. Свой источник прав

Источник прав (механика) — единственное место, где права **добавляются**.

```php
namespace AzGuard\Contracts\Sources;

interface GrantSource
{
    public function key(): string;                                      // 'vendor/name'
    /** @param list<ContextRef> $contexts @return iterable<Contribution> */
    public function contributions(SubjectRef $subject, array $contexts, EvaluationContext $context): iterable;
    public function volatility(): Volatility;                           // Stable | Request | Volatile
    /** что источник может дать — для схемы панели (D54) */
    public function describe(Panel $panel): SourceDescription;
    /** для видимости: какие сущности типа $type дают право $key; null — не умеет */
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?ContextSelection;
}

interface EvaluationContext                     // @api — что движок даёт механикам, хукам, ограничениям
{
    public function panel(): Panel;
    public function contexts(): array;          // применимые ContextRef
    public function resource(): ?object;
    public function state(): StateToken;
    public function now(): DateTimeImmutable;   // одно значение на проверку / на decideMany
    public function subjectModel(): ?Model;     // ленивая загрузка
    /** @return list<Contribution> вклады, покрывающие запрошенное право (с decisionFields) */
    public function matchingContributions(): array;
}
```

- `Volatility::Stable` — данные меняются только через AzGuard (кэш между запросами по версии панели); `Request` — кэш
  на запрос; `Volatile` — без кэша (внешняя система, токен).
- Источник не может дать право другой панели или голую звёздочку — такие вклады отбрасываются с предупреждением.
- Исключение внутри источника → отказ с причиной `source_error` и запись в лог.

Пример — права из способностей Sanctum-токена для панели `api`:

```php
final class TokenAbilitiesSource implements GrantSource
{
    public function key(): string { return 'acme/token-abilities'; }
    public function volatility(): Volatility { return Volatility::Volatile; }
    public function contributions(SubjectRef $subject, array $contexts, EvaluationContext $context): iterable
    {
        $token = $context->subjectModel()?->currentAccessToken();
        foreach ($token?->abilities ?? [] as $ability) {
            yield Contribution::pattern($context->panel()->id(), $ability, source: $this->key());
        }
    }
    // describe(), contextsCovering() — …
}
```

### 2.1 Связи сущностей

Встроенная механика `azguard/relations` превращает связь модели в роль внутри сущности без таблиц AzGuard:

```php
->relation(Project::class, via: 'members', role: 'pivot.role')          // участник проекта с ролью в pivot
->relation(Store::class, via: 'owner', role: 'owner')                   // владелец магазина — роль owner
->relation(Team::class, via: 'users', role: fn ($pivot) => $pivot->is_lead ? 'lead' : 'member')
```

Фиксированная роль (`role: 'owner'`) должна существовать на панели (роль из кода или из БД), иначе ошибка при
загрузке. Значения из pivot проверить при загрузке нельзя: значение, которому нет роли на панели, прав не даёт и
видно в doctor (`panels.relations`). Механика умеет фильтровать запросы (`visibleTo`) через `whereHas` по той же
связи.

## 3. Хуки проверки

```php
namespace AzGuard\Contracts\Hooks;

interface BeforeHook  { public function before(AccessRequest $request, EvaluationContext $context): ?bool; }   // да / нет / не знаю
interface AfterHook   { public function after(AccessRequest $request, Decision $decision): void; }              // только наблюдать

interface Restriction                                                     // AzGuard\Contracts\Authorization
{
    public function key(): string;
    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool;
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult;   // pass | deny(reason)
}
```

- `before`: первый ответ «да» или «нет» — итог (как `Gate::before`). Суперадмин — всегда первый before-хук.
  Пример — режим «только чтение» для всех, кроме суперадмина: `->before(fn (AccessRequest $r) => ReadOnlyMode::on() && $r->isWrite() ? false : null)`.
- `restrict`: пользователь заблокирован, не сотрудник магазина, нерабочее время. Исключение → отказ.
- `after`: метрики, журнал отказов. Исключение → лог, решение не меняется.

## 4. Хуки изменений

```php
interface ChangingHook { public function changing(Change $change): Change; }            // вернуть изменённый или бросить ChangeCancelledException
interface ChangedHook  { public function changed(AppliedChange $change): void; }         // после commit

final readonly class Change
{
    public ChangeType $type;              // AssignRole | RemoveRole | GivePermission | RevokePermission | CreateRole | UpdateRole | DeleteRole | SyncRolePermissions
    public string $panel; public ?SubjectRef $subject; public ?RoleKey $role; public ?PermissionPattern $permission;
    public ?ContextRef $context; public ?DateTimeImmutable $expiresAt; public array $fields; public ?ActorRef $actor;
    public function with(array $changes): self;
    public function cancel(string $reason): never;
}
```

Рецепты (в документации, не во встроенном коде — [D23](02-decisions.md#d23)):

```php
// «Нельзя выдать то, чего нет у тебя»
->changing(function (Change $change) {
    $actor = auth()->user();
    if ($change->permission && $actor && ! $actor->isSuperAdmin() && ! $actor->hasPermissionTo($change->permission->full())) {
        $change->cancel('Можно выдавать только свои права');
    }
    return $change;
})

// «Срок по умолчанию — 90 дней»
->changing(fn (Change $c) => $c->type === ChangeType::AssignRole && ! $c->expiresAt ? $c->with(['expiresAt' => now()->addDays(90)]) : $c)

// «Изменения ролей админки подтверждает второй человек» — хук сохраняет заявку в свою таблицу и отменяет
// изменение; после подтверждения заявка применяется обычным вызовом $user->inPanel('admin')->assignRole(...)
```

## 5. Свои модели и поля

1. Модель панели наследует базовую модель того же вида; ядро проверяет при сборке панели.
2. Идентификационные колонки и методы не переопределяются (`final` в базовой модели).
3. Поля: настоящие колонки (миграция) или `meta` (JSON, nullable).
4. Описание полей — `public static function azguardFields(): array` в модели. Из него берутся правила проверки, схема
   панели и формы Filament.
5. Поля, участвующие в решении, перечисляются в `->decisionFields([...])`; только они загружаются вместе с выдачами и
   кэшируются.

```php
final class AdminRoleAssignment extends RoleAssignment
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
        foreach ($c->matchingContributions() as $contribution) {
            $days = $contribution->fields()['weekdays'] ?? null;
            if ($days === null || in_array($c->now()->format('N'), $days, true)) {
                return RestrictionResult::pass();
            }
        }
        return RestrictionResult::deny('Сегодня не рабочий день по назначению');
    }
}
```

## 6. Контексты и субъекты

```php
interface ContextResolver   { public function resolve(Request $request): ?ContextRef; }                     // текущая сущность запроса
interface ContextMembership { public function isMember(SubjectRef $subject, ContextRef $context): bool; }  // «сотрудник ли этого магазина»
interface ContextDirectory  { public function search(string $type, string $term, int $limit): array; public function describe(ContextRef $c): ?ContextOption; }
interface SubjectResolver   { public function resolve(mixed $subject): SubjectRef; public function model(SubjectRef $ref): ?Model; }
interface SubjectDirectory  { public function search(string $term, int $limit, ?string $type = null): array; public function describe(SubjectRef $ref): ?SubjectOption; }
interface ProvidesContext   { public function azguardContext(): ?ContextRef; }       // ресурс сообщает свою сущность ($order → store)
interface SuperAdminRule    { public function isSuperAdmin(Model $subject, Panel $panel): bool; }   // ->superAdmin(when: …)
```

Трейт `Contexts\ContextAware` на моделях-сущностях и ресурсах реализует `ProvidesContext` (сущность возвращает себя,
ресурс переопределяет: `return $this->store`) и даёт scope `visibleTo()`.

По умолчанию: резолвер модели, директория по моделям субъектов панели, `RouteParameterResolver` для сущности из
параметра маршрута. `ContextMembership` можно задать классом, связью (`StoreStaff::viaRelation('staff')`) или
замыканием в провайдере.

## 7. Модули и сторонние пакеты внутри приложения

```php
// Modules/Blog/Providers/BlogServiceProvider.php
public function register(): void
{
    // вариант 1: своя панель модуля
    AzGuard::registerPanel(BlogPanelProvider::class);

    // вариант 2: дополнить панель, которую выбрало приложение (id из конфига модуля, не зашит)
    AzGuard::configurePanel(config('blog.azguard_panel', 'admin'), fn (PanelBuilder $panel) =>
        $panel->plugin(BlogAccessPlugin::make()->keyPrefix('blog')));
}
```

`keyPrefix('blog')` превращает `posts.edit` модуля в `blog.posts.edit` панели — модули не сталкиваются.
`azguard:panels:list --sources` показывает, кто что принёс.

## 8. Doctor

```php
interface DoctorCheck { public function key(): string; /** @return iterable<DoctorFinding> */ public function run(DoctorContext $context): iterable; }
```

## 9. Контрактные наборы (`AzGuard\Testing\Contracts\`)

| Набор | Для кого | Что гарантирует |
|---|---|---|
| `PluginContractTests` | авторы плагинов | плагин собирается на чистой панели и на двух панелях; не меняет панель в `boot()`; отключается без побочных эффектов |
| `GrantSourceContractTests` | авторы механик | только своя панель; одинаковый ответ на одной версии; уважает сроки и `now`; `describe()` совпадает с тем, что источник реально даёт |
| `RestrictionContractTests` | авторы ограничений | нет записи; исключение ≠ pass |
| `HookContractTests` | авторы хуков | `before` без побочных эффектов; `changing` не пишет в БД; `changed` не вызывается при откате |
| `SubjectResolverContractTests`, `ContextResolverContractTests` | авторы резолверов | идемпотентность, кодек, нет `:` в типе |
| `IntegrationContractTests` | пакеты-интеграции ([10](10-integrations.md)) | решение одинаково через трейт, `decideMany` и Gate; `StateToken` меняется при изменении; события после commit |

## 10. Истории расширения — проверка, что границы достаточно

| История | Что пишет автор | Что меняется в ядре |
|---|---|---|
| Кабинет на жёстких правилах, админка на БД | два `PanelProvider` | ничего |
| Кабинет продавца: автоматическая роль + доступ к своим магазинам | `CodeRole` с `appliesTo()` + `->relation()` | ничего |
| Право «смотреть заказ» решает политика, «смотреть все заказы» выдаётся в БД | метод политики с `#[Decides]` + `granted()` | ничего |
| Перенос права из кода в БД, чтобы его выдавали из админки | убрать из `grantToAll`, включить `->database()` | ничего; код проверок тот же |
| Панель API без ролей, права из токена | `GrantSource` (`Volatility::Volatile`) | ничего |
| Права у проектов (тариф) | панель `features` с `subjects(Project::class)` + свой источник | ничего |
| Модуль Blog со своими правами в админке | плагин + `configurePanel()` | ничего |
| Поле `department_id` у назначения и правило «только свой отдел» | модель панели + `azguardFields()` + `Restriction` | ничего |
| Подтверждение изменений вторым человеком | хук `changing` + своя таблица заявок | ничего |
| «Только сотрудники магазина» | `ContextMembership` + `requireMembership()` | ничего |
| LDAP-группы → роли | `GrantSource` (`Volatility::Request`) | ничего |
| Суперадмины во всех панелях по флагу | `AzGuard::configurePanels(fn ($p) => $p->superAdmin(when: IsRoot::class))` | ничего |
| Отдельная БД для прав админки | `Storage::own(connection: 'backoffice')` + `azguard:storage:migration` | ничего |
| Интеграция стороннего пакета (Vaulter и др.) | свой пакет: плагин + вызовы `decideMany` + `IntegrationContractTests` | ничего |
| Альтернативное хранилище выдач | **не в 1.0**: внешние данные читаются как `GrantSource` | после 1.0 |
