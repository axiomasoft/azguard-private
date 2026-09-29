# 06 — Точки расширения (SPI)

Решения: [D11](02-decisions.md#d11), [D14](02-decisions.md#d14), [D16](02-decisions.md#d16), [D19](02-decisions.md#d19),
[D20](02-decisions.md#d20), [D23](02-decisions.md#d23), [D36](02-decisions.md#d36), [D39](02-decisions.md#d39).

Принцип: **расширяемость = реестр с ключами + контракт + контрактный тест**, а не «переопредели класс в конфиге».
Удаляются single-binding seams 0.3 (`manager`, `resolver`, `matcher`, `abilities_resolver`,
`role_permission_validator`, `PermissionLayer`): они давали подмену целиком, но не композицию и не гарантии (N03).

## 1. Общие правила реестров

| Правило | Значение |
|---|---|
| Ключ | `vendor/name`, `^[a-z0-9-]+/[a-z0-9-]+$`; `azguard/*` зарезервирован |
| Источники регистрации | конфиг (FQCN или `key => FQCN`) + `AzGuard::extend()` в `register()`/`boot()` провайдера хоста |
| Дубликат ключа | `DuplicateExtensionException` при boot; замена — только `AzGuard::extend()->replace(key, class)` |
| Заморозка | на `booted` приложения; после — `RegistryFrozenException` |
| Жизненный цикл экземпляров | stateless-реализации — singleton; всё, что держит request-состояние, объявляет `ScopedExtension` (маркер) и биндится scoped |
| Порядок | источники — порядок не влияет на результат (объединение); constraints — порядок конфига, затем realm; порядок входит в `PolicyFingerprint` |
| Ошибки | исключение источника/constraint — наружу как `PermissionSourceException`/`Decision(ConstraintError)`: fail-closed, лог с ключом расширения |
| Контрактные тесты | каждый SPI имеет абстрактный Pest/PHPUnit-набор в `AzGuard\Testing\Contracts\` |

```php
namespace AzGuard\Extension;
final class ExtensionRegistrar    // AzGuard::extend()
{
    public function source(string $key, string $class): static;
    public function constraint(string $key, string $class): static;
    public function catalogProvider(string $realm, CatalogProvider|string $provider): static;
    public function contextResolver(string $key, string $class): static;
    public function doctorCheck(string $key, string $class): static;
    public function replace(string $key, string $class): static;
}
```

## 2. Permission source

```php
namespace AzGuard\Contracts\Authorization;

interface PermissionSource
{
    public function key(): string;                                    // 'azguard/roles'

    /**
     * Вклады субъекта в realm для перечисленных контекстов (глобальный всегда первый).
     * Возвращает шаблоны ТОЛЬКО этого realm; чужие отбрасываются движком с warning.
     *
     * @param list<ContextRef> $contexts
     * @return iterable<Contribution>
     */
    public function contributions(SubjectRef $subject, string $realm, array $contexts, SourceReadOptions $options): iterable;

    public function volatility(): Volatility;                         // Revisioned | Volatile | Ttl(int $seconds)

    /** Для Visibility: какие контексты типа $type дают шаблоны, покрывающие $key. null = источник не поддерживает. */
    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType): ?ContextSelection;
}
```

- `SourceReadOptions` несёт `readFromPrimary: bool` (D24) и `now: DateTimeImmutable` — источник не вызывает `now()`.
- `Volatility::Revisioned` — источник читает только данные AzGuard, инвалидация — ревизией (встроенные).
  `Volatile` — результат **не** кэшируется межзапросно (внешняя система, лицензия). `Ttl(n)` — кэшируется не дольше n.
- Встроенные: `azguard/roles` (`RolesSource`: назначения ролей → права code-ролей из реестра + DB-ролей из
  `azg_role_permissions`, одна выборка назначений на `(subject, realm, contexts)`), `azguard/grants` (`GrantsSource`).
- Отключение встроенного: `azguard.authorization.sources.azguard/grants = null` (замена `features.direct_grants`).
- `*` и шаблоны чужого realm от внешнего источника → отбрасываются, `azguard:doctor` показывает счётчик.

## 3. Constraint

```php
interface Constraint
{
    public function key(): string;
    public function appliesTo(AccessRequest $request, Realm $realm): bool;
    public function check(AccessRequest $request, EvaluationContext $context): ConstraintResult;  // pass|fail(reason)|abstain
    public function bypassable(): bool;                                // может ли superadmin пройти мимо
}

interface EvaluationContext   // @api, передаётся движком
{
    public function realm(): Realm;
    public function contexts(): array;                                 // применимые ContextRef
    public function state(): StateToken;
    public function now(): DateTimeImmutable;
    public function subjectModel(): ?Model;                            // ленивая загрузка через SubjectResolver
}
```

Встроенный `azguard/context-membership`: `appliesTo` — realm требует членства и запрос в контексте;
`check` — `ContextMembership::isMember($subject, $context)`; `bypassable()` — `false`.

## 4. Контексты

```php
namespace AzGuard\Contracts\Context;

interface ContextResolver          // ambient-контекст запроса (middleware azguard.context)
{
    public function resolve(Request $request): ?ContextRef;
}

interface ContextMembership        // граница tenant (D15)
{
    public function isMember(SubjectRef $subject, ContextRef $context): bool;
}

interface ContextDirectory         // UI/CLI: выбор контекста
{
    /** @return list<ContextOption> */ public function search(string $type, string $term, int $limit): array;
    public function describe(ContextRef $context): ?ContextOption;
}
```

Несколько резолверов — по порядку, первый не-null побеждает. Резолвер из route-параметра — встроенный
`RouteParameterContextResolver('workspace', type: 'workspace')` (конфигурируется массивом, без кода).

## 5. Субъекты

```php
namespace AzGuard\Contracts\Authorization;
interface SubjectResolver
{
    public function resolve(mixed $subject): SubjectRef;               // @throws InvalidSubjectException
    public function model(SubjectRef $ref): ?Model;                    // для constraints и UI
}

namespace AzGuard\Contracts\Subjects;
interface SubjectDirectory
{
    /** @return list<SubjectOption> */ public function search(string $term, int $limit, ?string $type = null): array;
    public function describe(SubjectRef $ref): ?SubjectOption;         // label, avatar?, type
}
```

По умолчанию `ModelSubjectResolver` (morph alias + ключ модели; `getAuthIdentifier()` для не-моделей) и
`GuardSubjectDirectory` (провайдер guard'а `azguard.subjects.guard`, колонка `azguard.subjects.label_column`).

## 6. Superadmin и делегирование

```php
interface SuperadminPolicy
{
    public function isSuperadmin(SubjectRef $subject, string $realm, EvaluationContext $context): bool;
}

namespace AzGuard\Contracts\Administration;
interface DelegationPolicy
{
    /** @throws AccessManagementDeniedException */
    public function authorize(Actor $actor, AdministrativeOperation $operation): void;
}
```

`AdministrativeOperation` — readonly-описание (`type: AssignRole|IssueGrant|…`, `realm`, `subject`, `context`,
`role`, `patterns`) — политика не видит моделей и не пишет.

## 7. Роли из кода

```php
namespace AzGuard\Contracts\Roles;
interface RoleDefinition
{
    public function key(): string;                                     // ^[a-z0-9][a-z0-9-]{0,63}$
    public function label(): ?string;
    /** @return list<UnitEnum|class-string<Permission>|string> строки — ключи/шаблоны своего realm */
    public function permissions(): array;
    /** @return list<string> прежние ключи — sync переносит назначения */
    public function formerKeys(): array;
    public function rank(): int;                                        // начальное значение; далее — БД
}
```

`AzGuard\Roles\CodeRole` — абстрактная база: `label()` → `null`, `formerKeys()` → `[]`, `rank()` → `0`.

## 8. Каталог

```php
namespace AzGuard\Contracts\Catalog;
interface CatalogProvider
{
    public function key(): string;
    /** @return iterable<PermissionDefinition> */
    public function definitions(string $realm): iterable;
}
```

Встроенные: `azguard/enum`, `azguard/class`, `azguard/config` (`azguard.catalog.permissions.{realm}` — список
строк для каталогов без кода), `azguard/access` (мета-права D23), `azguard/filament`. Одинаковый ключ из двух
провайдеров с разными метаданными → `DuplicatePermissionException`; с одинаковыми — допустим (идемпотентно).

## 9. Doctor

```php
namespace AzGuard\Diagnostics;
interface DoctorCheck
{
    public function key(): string;
    /** @return iterable<DoctorFinding> severity: error|warning|info */
    public function run(DoctorContext $context): iterable;
}
```

## 10. Контрактные наборы (`AzGuard\Testing\Contracts\`)

| Набор | Что гарантирует реализация |
|---|---|
| `PermissionSourceContractTests` | только свой realm; детерминизм на одном `StateToken`; уважение `expiresAt`/`now`; `readFromPrimary` передаётся в запросы |
| `ConstraintContractTests` | чистота (без записи), исключение ≠ pass, `appliesTo` без IO |
| `SubjectResolverContractTests` | `resolve(resolve(x)) == resolve(x)`; int/строковый ключ → одна ссылка; тип без `:` |
| `ContextResolverContractTests` | тип контекста принадлежит объявленным; никакого IO сверх одного запроса |
| `DelegationPolicyContractTests` | system-актор не проверяется политикой; отказ — только `AccessManagementDeniedException` |

## 11. Истории расширения (как проверяется, что граница завершена)

| История | Что пишет автор | Что меняется в ядре |
|---|---|---|
| Новый источник прав (LDAP-группы → роли) | `PermissionSource` с `Volatility::Ttl(300)` + ключ в конфиге | ничего |
| Tenant-граница «член workspace» | `ContextMembership` + `->requireMembership()` на realm | ничего |
| Лицензионное ограничение («фича оплачена») | `Constraint` `acme/license`, `bypassable() = false` | ничего |
| Другой auth-провайдер (admins таблица) | `azguard.subjects.guard = 'admin'` или свой `SubjectDirectory` | ничего |
| Контекст в очереди | job хранит `ContextRef`, `AzGuard::withinContext()` | ничего |
| Переименование класса роли | ничего (ключ тот же) / смена ключа — `formerKeys()` | ничего |
| Внешний каталог (права из БД хоста) | `CatalogProvider` | ничего |
| Filament со своей формой субъекта | `SubjectDirectory` | ничего |
| Альтернативное хранилище назначений | **не поддерживается в 1.0** — подключается как `PermissionSource` (чтение) | T2 |
