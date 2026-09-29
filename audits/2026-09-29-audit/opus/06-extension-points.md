# 06 — Расширение: плагины, пайплайны, свои модели, модули

Решения: [D11](02-decisions.md#d11), [D14](02-decisions.md#d14), [D19](02-decisions.md#d19), [D20](02-decisions.md#d20),
[D23](02-decisions.md#d23), [D36](02-decisions.md#d36), [D39](02-decisions.md#d39), [D46](02-decisions.md#d46)–[D51](02-decisions.md#d51).

Главная мысль: **всё, что можно захотеть поменять, подключается к панели плагином**, а плагин встраивается в
шаги пайплайнов, у каждого из которых узкий и безопасный контракт. Одиночные «подмени класс в конфиге» из 0.3
(`manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator`, `PermissionLayer`) удаляются:
они позволяли заменить целиком, но не сочетать несколько расширений и не гарантировали безопасность (N03).

## 1. Плагин

```php
namespace AzGuard\Contracts\Plugins;

interface Plugin                      // @spi
{
    public function id(): string;                         // 'vendor/name'
    public function register(PanelBuilder $panel): void;   // объявить всё, что приносит плагин (до заморозки)
    public function boot(Panel $panel): void;              // рантайм-связи после заморозки; менять панель нельзя
}

interface DependsOnPlugins { /** @return list<string> */ public function requires(): array; }
interface PrefixesKeys { public function prefix(): ?string; }   // необязательный: пространство имён ключей плагина

abstract class BasePlugin implements Plugin, PrefixesKeys        // удобная база для своих плагинов
{
    public static function make(): static;
    public function keyPrefix(string $prefix): static;           // BlogAccessPlugin::make()->keyPrefix('blog')
    public function prefix(): ?string;
}
```

Если плагин реализует `PrefixesKeys`, `PanelBuilder` добавляет префикс к локальным ключам прав и ролей, которые плагин
объявил в `register()`: `posts.edit` → `admin.blog.posts.edit`, роль `editor` → `admin:blog-editor`.

Жизненный цикл: провайдеры панелей и `configurePanel()` собирают `PanelBuilder` → для каждого подключённого плагина
`register()` (в порядке подключения) → проверки сборки (коллизии, зависимости, хранилище) → заморозка → `boot()`.

Что плагин может принести в `register()`:

| Вклад | Метод `PanelBuilder` | Пример |
|---|---|---|
| Права | `permissions()`, `catalogBuilders()` | права модуля Blog |
| Роли | `roles()` | роль `blog-editor` |
| Источник прав | `grantSources()` | права из способностей Sanctum-токена, из LDAP-групп |
| Подготовка запроса | `prepare()` | вывести контекст из ресурса (`$post->store`) |
| Ограничение | `restrict()` | рабочие часы, лицензия, IP |
| Наблюдение | `observe()` | метрики, журнал отказов |
| Суперадмин | `superadmin()` | «root-пользователи хоста — суперадмины» |
| Шаги изменений | `onChange($pipe, ChangeStage::…)` | подтверждения, свои правила, история |
| Делегирование | `delegation()` | своя политика «кто кому что выдаёт» |
| Контексты | `contexts()`, `contextResolvers()`, `membership()` | текущий магазин из поддомена |
| Модели и поля | `models()`, `decisionAttributes()` | поле `department_id` у назначения |
| Doctor | `doctorChecks()` | проверка конфигурации плагина |
| Настройки панели | любые из [D45](02-decisions.md#d45) | значение по умолчанию, если провайдер панели не задал |

Правила: один плагин можно подключить к нескольким панелям (экземпляр видит свою); конфликт двух плагинов по одной
настройке → `PluginConflictException`; плагин не может отключить инварианты D45; порядок плагинов входит в отпечаток
политики (кэш не отдаст результат, посчитанный при другой конфигурации).

### 1.1 Встроенные плагины

| id | Что даёт | По умолчанию |
|---|---|---|
| `azguard/roles` | роли, назначения, `RoleGrantSource`, sync code-ролей | включён |
| `azguard/direct-grants` | прямые права, `DirectGrantSource` | включён |
| `azguard/contexts` | `ContextPolicy`, текущий контекст, резолверы, ограничение членства | включён (политика `none`, пока панель не задаст) |
| `azguard/superadmin` | роль `{panel}:superadmin`, `RoleSuperadminPolicy` | включён |
| `azguard/access` | мета-права панели, `DefaultDelegationPolicy` | включён |
| `azguard/audit` | журнал изменений в транзакции, `AuditEntry` | выключен |

Встроенные плагины пишутся **только** через публичные разъёмы — так же, как написал бы сторонний автор (arch-тест).
Это доказательство, что API плагинов достаточен для настоящих функций.

## 2. Пайплайн доступа — разъёмы шагов

```php
namespace AzGuard\Contracts\Authorization;

interface PreparesAccess        // шаг 1: может дополнить запрос, не может решить
{
    public function prepare(AccessRequest $request, EvaluationContext $context): AccessRequest;
}

interface SuperadminPolicy      // шаг 2
{
    public function isSuperadmin(SubjectRef $subject, EvaluationContext $context): bool;
}

interface GrantSource           // шаг 3: единственное место, где права добавляются
{
    public function key(): string;                                    // 'vendor/name'
    /** @param list<ContextRef> $contexts @return iterable<Contribution> */
    public function contributions(SubjectRef $subject, array $contexts, EvaluationContext $context): iterable;
    public function volatility(): Volatility;                          // Revisioned | Volatile | Ttl(int $seconds)
    /** для видимости: какие контексты типа $type дают шаблоны, покрывающие $key; null — не поддерживает */
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?ContextSelection;
}

interface Restriction           // шаг 4: может только запретить
{
    public function key(): string;
    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool;
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult;   // pass | deny(reason) | abstain
    public function bypassable(): bool;                                // может ли суперадмин пройти мимо
}

interface ObservesAccess        // шаг 5: не может изменить решение
{
    public function observe(AccessRequest $request, Decision $decision, EvaluationContext $context): void;
}

interface EvaluationContext     // @api — что движок даёт шагам
{
    public function panel(): Panel;
    public function contexts(): array;                    // применимые ContextRef
    public function state(): StateToken;
    public function now(): DateTimeImmutable;             // одно значение на оценку/на decideMany
    public function readFromPrimary(): bool;
    public function subjectModel(): ?Model;               // ленивая загрузка через SubjectResolver панели
    /** @return list<Contribution> вклады, покрывающие запрошенный ключ (для ограничений; с decisionAttributes) */
    public function matchingContributions(): array;
}
```

- `Volatility::Revisioned` — источник читает только данные хранилища панели (инвалидация версией);
  `Volatile` — межзапросно не кэшируется (внешняя система); `Ttl(n)` — кэш не дольше n секунд.
- Шаблоны другой панели и голый `*` от источника отбрасываются с warning (doctor показывает счётчик).
- Исключение: источник — наружу (частичный набор не авторизует); ограничение — `Deny(RestrictionError)`;
  наблюдатель — лог, решение не меняется.

## 3. Пайплайн изменений — разъёмы шагов

```php
namespace AzGuard\Contracts\Administration;

interface DelegationPolicy      // шаг 1
{
    /** @throws AccessManagementDeniedException */
    public function authorize(Actor $actor, Change $change, EvaluationContext $context): void;
}

interface ValidatesChange       // шаг 2
{
    /** @return array<string, list<string>> ошибки по полям; пусто — ок */
    public function validate(Change $change): array;
}

interface InterceptsChange      // шаг 3 — Laravel-style pipe
{
    /** @param Closure(Change): ChangeResult $next */
    public function intercept(Change $change, Closure $next): ChangeResult;
    // вернуть $next($change->with(...)) — изменить; ChangeResult::pending(...) — отложить; throw — отклонить
}

interface RecordsChange         // шаг 5 — в той же транзакции, после записи
{
    public function record(AppliedChange $change, Storage $storage): void;
}

interface NotifiesChange        // шаг 6 — после commit
{
    public function notify(AppliedChange $change): void;
}

enum ChangeStage: string { case Validate = 'validate'; case Intercept = 'intercept'; case Record = 'record'; case Notify = 'notify'; }
```

`Change` — неизменяемое описание: `type` (`AssignRole`, `RevokeRole`, `GrantPermission`, …), `panel`, `actor`,
`subject`, `role`/`patterns`, `context`, `expiresAt`, `attributes` (свои поля), `reason`, `correlationId`;
`with(...)` возвращает копию. `AppliedChange` — `Change` + затронутые записи + новая версия состояния.

### 3.1 Пример: подтверждение вторым администратором (4-eyes)

```php
final class ApprovalPlugin implements Plugin
{
    public function id(): string { return 'acme/approvals'; }

    public function register(PanelBuilder $panel): void
    {
        $panel->onChange(new RequireApproval($this->roles), ChangeStage::Intercept)
              ->permissions(ApprovalPermission::class)             // кто может подтверждать
              ->doctorChecks(ApprovalsTableCheck::class);
    }
    public function boot(Panel $panel): void {}
}

final class RequireApproval implements InterceptsChange
{
    public function intercept(Change $change, Closure $next): ChangeResult
    {
        if (! $this->needsApproval($change)) {
            return $next($change);
        }
        $request = ApprovalRequest::createFrom($change);          // своя таблица плагина
        return ChangeResult::pending(PendingChange::fromChange($change, reference: $request->id));
    }
}

// Второй админ подтверждает: применение от его имени, с повторной проверкой полномочий
AzGuard::panel('admin')->manage()->actingAs($approver)->apply($request->pending());
```

## 4. Свои модели и поля

Правила (D46):

1. Модель панели наследует базовую модель AzGuard того же вида; ядро проверяет при сборке панели.
2. Идентификационные колонки и методы не переопределяются (`final` в базовой модели).
3. Новые поля: колонки (миграция хранилища) или `meta` (JSON, кастом модели).
4. Правила валидации своих полей — `public static function azguardRules(): array` в модели; плагины добавляют правила
   шагом `ValidatesChange`. Неизвестное поле в `attributes` → `InvalidChangeAttributesException`.
5. Поля, участвующие в решении, объявляются `decisionAttributes()`; только они загружаются с выдачами и кэшируются.

```php
final class AdminRoleAssignment extends RoleAssignment
{
    protected $casts = ['weekdays' => 'array', 'approved_at' => 'datetime'];
    public static function azguardRules(): array
    {
        return ['department_id' => ['required', 'integer', 'exists:departments,id'], 'weekdays' => ['array']];
    }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}

final class WeekdaysRestriction implements Restriction
{
    public function key(): string { return 'acme/weekdays'; }
    public function appliesTo(AccessRequest $r, EvaluationContext $c): bool { return true; }
    public function check(AccessRequest $r, EvaluationContext $c): RestrictionResult
    {
        // вклады, давшие право, доступны вместе с decisionAttributes
        foreach ($c->matchingContributions() as $contribution) {
            $days = $contribution->attributes()['weekdays'] ?? null;
            if ($days === null || in_array($c->now()->format('N'), $days, true)) {
                return RestrictionResult::pass();
            }
        }
        return RestrictionResult::deny('outside_allowed_weekdays');
    }
    public function bypassable(): bool { return true; }
}
```

## 5. Контексты и субъекты — разъёмы

```php
interface ContextResolver   { public function resolve(Request $request): ?ContextRef; }                    // текущий контекст запроса
interface ContextMembership { public function isMember(SubjectRef $subject, ContextRef $context): bool; } // граница tenant
interface ContextDirectory  { public function search(string $type, string $term, int $limit): array; public function describe(ContextRef $c): ?ContextOption; }
interface SubjectResolver   { public function resolve(mixed $subject): SubjectRef; public function model(SubjectRef $ref): ?Model; }
interface SubjectDirectory  { public function search(string $term, int $limit, ?string $type = null): array; public function describe(SubjectRef $ref): ?SubjectOption; }
```

По умолчанию: `ModelSubjectResolver`, `GuardSubjectDirectory` (провайдер guard'а панели), `RouteParameterResolver`
для контекста из параметра маршрута.

## 6. Каталог и роли из кода

```php
interface PermissionCatalogBuilder   // имя сохраняется
{
    public function key(): string;
    /** @return iterable<PermissionDefinition> */
    public function build(Panel $panel): iterable;
}

interface RoleDefinition
{
    public function key(): string;                 // ^[a-z0-9][a-z0-9-]{0,63}$
    public function label(): ?string;
    /** @return list<UnitEnum|class-string<Permission>|string> enum — локальные, строки — полные ключи/шаблоны своей панели */
    public function permissions(): array;
    /** @return list<string> */ public function formerKeys(): array;
    public function rank(): int;
}
```

## 7. Модули и сторонние пакеты внутри приложения

```php
// Modules/Blog/Providers/BlogServiceProvider.php
public function register(): void
{
    // вариант 1: своя панель модуля
    AzGuard::registerPanel(BlogPanelProvider::class);

    // вариант 2: дополнить панель, которую выбрал хост (id из конфига модуля — не зашит)
    AzGuard::configurePanel(config('blog.azguard_panel', 'admin'), fn (PanelBuilder $panel) =>
        $panel->plugin(BlogAccessPlugin::make()->keyPrefix('blog')));
}
```

`keyPrefix('blog')` превращает локальные ключи модуля `posts.edit` в `admin.blog.posts.edit` — модули не
сталкиваются. `azguard:panels:list --contributions` показывает, какой плагин что принёс.

## 8. Doctor

```php
interface DoctorCheck { public function key(): string; /** @return iterable<DoctorFinding> */ public function run(DoctorContext $context): iterable; }
```

## 9. Контрактные наборы (`AzGuard\Testing\Contracts\`)

| Набор | Для кого | Что гарантирует |
|---|---|---|
| `PluginContractTests` | авторы плагинов | плагин собирается на чистой панели и на двух панелях сразу; не меняет панель в `boot()`; объявляет id; отключаем без побочных эффектов |
| `GrantSourceContractTests` | авторы источников | только своя панель; детерминизм на одном `StateToken`; уважение срока и `now`; `readFromPrimary` |
| `RestrictionContractTests` | авторы ограничений | нет записи; исключение ≠ pass; `appliesTo` без IO |
| `ChangePipeContractTests` | авторы шагов изменений | `Record` не пишет вне транзакции; `Notify` не вызывается при откате; `Intercept` возвращает `ChangeResult` |
| `SubjectResolverContractTests`, `ContextResolverContractTests` | авторы резолверов | идемпотентность, кодек, отсутствие `:` в типе |
| `IntegrationContractTests` | пакеты-интеграции ([10](10-integrations.md)) | решение через `decide`/`decideMany`/Gate одинаково; `StateToken` меняется при изменении; события после commit |

## 10. Истории расширения — проверка, что граница завершена

| История | Что пишет автор | Что меняется в ядре |
|---|---|---|
| Несколько панелей: админка, сайт, API | три `PanelProvider` | ничего |
| Панель API без ролей, права из Sanctum-токена | `withoutPlugin('azguard/roles')` + плагин с `GrantSource` (`Volatility::Volatile`) | ничего |
| Модуль Blog со своими правами в админке | плагин + `configurePanel()` | ничего |
| Поле `department_id` у назначения и проверка «только своего отдела» | модель панели + `azguardRules()` + `Restriction` | ничего |
| Подтверждение изменений вторым админом | плагин с `InterceptsChange` | ничего |
| Tenant-граница «только члены магазина» | `ContextMembership` + `requireMembership()` | ничего |
| LDAP-группы → права | `GrantSource` с `Ttl(300)` | ничего |
| Суперадмины во всех панелях по флагу пользователя | `GlobalSuperadminPlugin` на нужных панелях | ничего |
| Отдельная БД для прав админки | `Storage::own(connection: 'backoffice')` + `azguard:storage:migration` | ничего |
| Переименование класса роли | ничего (ключ тот же) / смена ключа — `formerKeys()` | ничего |
| Интеграция стороннего пакета (Vaulter и др.) | свой пакет: плагин + вызовы `decide/decideMany` + `IntegrationContractTests` | ничего |
| Альтернативное хранилище выдач | **не поддерживается в 1.0** — внешние данные читаются как `GrantSource` | T2 |
