# 05 — Публичный PHP API (нормативные сигнатуры)

Решения: [D05](02-decisions.md#d05)–[D11](02-decisions.md#d11), [D17](02-decisions.md#d17), [D22](02-decisions.md#d22),
[D27](02-decisions.md#d27)–[D29](02-decisions.md#d29), [D31](02-decisions.md#d31), [D37](02-decisions.md#d37).
Сигнатуры нормативны по именам, типам и семантике; порядок необязательных параметров можно уточнить в
спецификации пункта плана, не меняя смысла. Всё ниже — `@api`, если не сказано иное.

## 0. Как это выглядит целиком

```php
// Realm (app/Authorization/AppRealm.php)
final class AppRealm extends RealmProvider
{
    public function realm(RealmBuilder $realm): RealmBuilder
    {
        return $realm->id('app')->label('Приложение')
            ->permissions(DocumentPermission::class, ProjectPermission::class)
            ->roles(EditorRole::class, ViewerRole::class)
            ->contexts(ContextPolicy::inherit('workspace', 'project')->requireMembership());
    }
}

#[Realm('app')]
enum DocumentPermission: string
{
    #[Describe(label: 'Просмотр документов', group: 'Документы')]
    case View = 'documents.view';
    case Update = 'documents.update';
}

final class EditorRole extends CodeRole
{
    public function key(): string { return 'editor'; }
    public function permissions(): array { return [DocumentPermission::View, DocumentPermission::Update, 'app.projects.*']; }
}

// Проверки
$user->hasPermission(DocumentPermission::Update, context: $workspace);
AzGuard::check($user, 'app.documents.update', context: $workspace);
Gate::allows('app.documents.update', [$workspace]);                     // authoritative
Project::query()->visibleTo($user, ProjectPermission::View)->paginate();

// Изменения — только с актором
AzGuard::access()->actingAs($admin)->assignRole($user, 'app:editor', context: $workspace);
AzGuard::access()->asSystem('onboarding')->issueGrant($user, 'app.reports.export', expiresAt: now()->addDay());
```

## 1. Фасад

```php
namespace AzGuard\Facades;

/**
 * @method static bool check(mixed $subject, PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null)
 * @method static void authorize(mixed $subject, PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null) @throws AuthorizationException
 * @method static Decision decide(AccessRequest $request)
 * @method static DecisionSet decideMany(iterable<AccessRequest> $requests)
 * @method static Explanation explain(AccessRequest $request)
 * @method static SubjectAccess for(mixed $subject)
 * @method static AccessManager access()
 * @method static RealmRegistry realms()
 * @method static PermissionCatalog catalog()
 * @method static Visibility visibility()
 * @method static mixed withinContext(ContextRef|Model $context, Closure $callback)
 * @method static ContextRef|null currentContext()
 * @method static StateToken state()
 * @method static ExtensionRegistrar extend()
 */
final class AzGuard extends Facade
{
    public static function fake(): AzGuardFake;
}
```

`$subject` везде — `Model|Authenticatable|SubjectRef`; приводится `SubjectResolver`. `ContextRef|Model` — модель
приводится к `ContextRef::of($model->getMorphClass(), $model->getKey())`.

## 2. Значения ядра (`AzGuard\Kernel\…`)

```php
final readonly class PermissionKey implements Stringable, JsonSerializable
{
    public static function from(PermissionKey|string|UnitEnum $permission): self;   // строка — только квалифицированная
    public static function tryFrom(string $value): ?self;
    public static function in(string $realm, string $local): self;                  // PermissionKey::in('app', 'documents.view')
    public function realm(): string;
    public function local(): string;                                                 // 'documents.view'
    public function value(): string;                                                 // 'app.documents.view'
    public function equals(self $other): bool;
}

final readonly class PermissionPattern implements Stringable, JsonSerializable
{
    public static function from(PermissionKey|string|UnitEnum $pattern): self;       // 'app.docs.*', 'app.**', ключ
    public function realm(): string;
    public function covers(PermissionKey $key): bool;
    public function isExact(): bool;
    public function isRealmWide(): bool;                                              // 'realm.**'
}

final readonly class RoleKey implements Stringable
{
    public static function from(RoleKey|string $value): self;                        // 'app:editor'
    public static function of(string $realm, string $key): self;
    public function realm(): string;
    public function key(): string;
    public function value(): string;
}

final readonly class SubjectRef implements Stringable, JsonSerializable
{
    public static function of(string $type, int|string $id): self;
    public function type(): string;
    public function id(): string;                                                     // канонично: строка
    public function equals(self $other): bool;
}

final readonly class ContextRef implements Stringable, JsonSerializable
{
    public static function of(string $type, int|string $id): self;
    public static function global(): self;
    public static function fromModel(Model $model): self;                             // адаптер: Laravel-слой
    public function isGlobal(): bool;
    public function type(): ?string;
    public function id(): ?string;
    public function key(): string;                                                    // 'global' | '{type}:{id}'
    public function equals(self $other): bool;
}

final readonly class Actor
{
    public static function subject(SubjectRef $ref, ?string $reason = null): self;
    public static function system(string $reason): self;
    public function isSystem(): bool;
    public function subjectRef(): ?SubjectRef;
    public function reason(): ?string;
    public function ref(): ActorRef;
}

final readonly class ActorRef                          // ≙ Vaulter\ValueObjects\Access\ActorRef
{
    public const string SYSTEM_TYPE = 'azguard:system';
    public function __construct(public ?string $type, public ?string $id, public ?string $reason = null) {}
}

final readonly class AccessRequest
{
    public static function for(SubjectRef $subject, PermissionKey $permission): self;
    public function in(?ContextRef $context): self;                                   // null = ambient/global
    public function about(?object $resource): self;
    public function traced(bool $trace = true): self;
    public SubjectRef $subject; public PermissionKey $permission; public ?ContextRef $context;
    public ?object $resource; public bool $trace;
}

final readonly class AnyContext                      // маркер «во всех контекстах» для снятия назначений
{
    public static function all(): self;
}

enum Effect: string { case Allow = 'allow'; case Deny = 'deny'; case NotApplicable = 'not_applicable'; }

enum DecisionReason: string
{
    case Granted = 'granted'; case Superadmin = 'superadmin'; case NotGranted = 'not_granted';
    case NotApplicable = 'not_applicable'; case ContextRequired = 'context_required';
    case ContextNotAccepted = 'context_not_accepted'; case ConstraintFailed = 'constraint_failed';
    case ConstraintError = 'constraint_error';
}

final readonly class Decision
{
    public Effect $effect; public DecisionReason $reason; public StateToken $state;
    public ?string $constraint;                         // ключ constraint при ConstraintFailed/Error
    /** @var list<Contribution> */ public array $contributions;   // пусто, если не traced
    public function allowed(): bool;
    public function toGateResult(): ?bool;              // Allow→true, Deny→false, NotApplicable→null (authoritative)
}

final readonly class DecisionSet implements Countable, IteratorAggregate
{
    public function get(int $index): Decision;
    /** @return list<int> */ public function allowedIndexes(): array;
    public StateToken $state;
}

final readonly class Contribution
{
    public PermissionPattern $pattern; public string $source;           // 'azguard/roles'
    public ?RoleKey $role; public ContextRef $context; public ?DateTimeImmutable $expiresAt;
    public ?string $assignmentId;
}

final readonly class StateToken implements Stringable
{
    public int $revision; public int $generation; public string $policyFingerprint;
}

final readonly class PermissionSet                   // результат permissions()
{
    /** @return list<PermissionPattern> */ public function patterns(): array;
    public function covers(PermissionKey $key): bool;
    public function validUntil(): ?DateTimeImmutable;
    public function isEmpty(): bool;
}
```

`Kernel\` не зависит от Illuminate (arch-тест); `ContextRef::fromModel()` поэтому реализован в Laravel-слое как
статический фабричный хелпер `AzGuard\Context\Contexts::fromModel()`, а в `Kernel` — только `of()`.

## 3. `Authorizer`

```php
namespace AzGuard\Contracts\Authorization;

interface Authorizer
{
    public function decide(AccessRequest $request): Decision;
    public function allows(AccessRequest $request): bool;
    /** @param iterable<AccessRequest> $requests */
    public function decideMany(iterable $requests): DecisionSet;      // один StateToken на весь набор
    public function explain(AccessRequest $request): Explanation;    // та же оценка + trace
    public function state(): StateToken;
}
```

- Неквалифицированная строка → `UnqualifiedPermissionException`; неизвестный realm → `UnknownRealmException`;
  ключ realm вне каталога → `Decision(NotApplicable)` (для Gate — `null`), в `decide()` без Gate — тоже
  `NotApplicable` (не исключение: хост мог спросить чужое).
- Контекст: явный из запроса → иначе `CurrentContext` (если realm принимает его тип) → иначе глобальный.

## 4. Handle субъекта

```php
namespace AzGuard\Authorization;

final readonly class SubjectAccess
{
    public function in(ContextRef|Model|null $context): self;
    public function can(PermissionKey|string|UnitEnum $permission, ?object $resource = null): bool;
    public function decide(PermissionKey|string|UnitEnum $permission, ?object $resource = null): Decision;
    public function permissions(string $realm): PermissionSet;
    /** @param list<PermissionKey|string|UnitEnum> $permissions @return array<string, bool> ключ — value() */
    public function abilities(array $permissions): array;             // для фронтенда (было abilitiesFor)
    /** @return list<RoleKey> */ public function roles(string $realm): array;
    public function hasRole(RoleKey|string $role): bool;
    public function isSuperadmin(string $realm): bool;
    /** @return list<AssignmentView> */ public function assignments(?string $realm = null): array;  // роли+гранты, read-model
    public function ref(): SubjectRef;
}
```

## 5. `AccessManager`

```php
namespace AzGuard\Contracts\Administration;

interface AccessManager
{
    public function actingAs(Model|Authenticatable|SubjectRef $actor): static;
    public function asSystem(string $reason): static;
    public function withReason(string $reason): static;              // причина в аудит/события
    public function withCorrelationId(string $id): static;

    // роли субъектов
    public function assignRole(mixed $subject, RoleKey|string|class-string $role, ContextRef|Model|null $context = null, ?DateTimeInterface $expiresAt = null): RoleAssignmentResult;
    public function unassignRole(mixed $subject, RoleKey|string|class-string $role, ContextRef|Model|AnyContext|null $context = null): int;
    /** @param list<RoleKey|string> $roles */
    public function syncRoles(mixed $subject, string $realm, array $roles, ContextRef|Model|null $context = null): SyncResult;

    // гранты
    public function issueGrant(mixed $subject, PermissionPattern|string|UnitEnum $pattern, ContextRef|Model|null $context = null, ?DateTimeInterface $expiresAt = null): GrantResult;
    public function revokeGrant(mixed $subject, PermissionPattern|string|UnitEnum $pattern, ContextRef|Model|AnyContext|null $context = null): int;
    public function revokeGrants(mixed $subject, string $realm, ContextRef|Model|AnyContext|null $context = null): int;

    // роли как объекты (только DB-роли, кроме label у code)
    public function createRole(string $realm, string $key, ?string $label = null, int $rank = 0): RoleView;
    public function updateRole(RoleKey|string $role, RoleUpdate $changes): RoleView;
    public function deleteRole(RoleKey|string $role): void;          // каскад назначений внутри той же транзакции + события
    public function setRolePermissions(RoleKey|string $role, PermissionSelection $selection): RolePermissionsResult;

    // superadmin
    public function assignPlatformSuperadmin(mixed $subject): RoleAssignmentResult;
    public function unassignPlatformSuperadmin(mixed $subject): int;

    // обслуживание (только system)
    public function pruneExpired(?DateTimeInterface $before = null): PruneResult;
    public function resetState(): StateToken;
}
```

- Без `actingAs()`/`asSystem()` любой метод записи → `MissingActorException` (`missing_actor`).
- Каждый метод: делегирование (D23) → валидация → запись → ревизия → события, одной транзакцией (D22).
- Результаты сообщают, изменилось ли что-то (`changed: bool`); no-op не порождает событий.
- `AnyContext::all()` — явный маркер «во всех контекстах» (было `removeScopedRoleEverywhere`).
- `PermissionSelection::replace(string $realm, list<string> $patterns, ?string $expectedFingerprint)`,
  `::toggle(string $pattern, bool $present, ?string $expectedFingerprint)` — сохраняет защиту от устаревшей формы
  (`StaleSelectionException`, код `stale_selection`), которая была в `RolePermissionSynchronizer`.

## 6. Realm

```php
namespace AzGuard\Realms;

abstract class RealmProvider                             // НЕ ServiceProvider; регистрируется в azguard.realms.providers
{
    abstract public function realm(RealmBuilder $realm): RealmBuilder;
}

final class RealmBuilder
{
    public function id(string $id): static;                                   // ^[a-z0-9][a-z0-9-]{0,63}$
    public function label(string $label): static;
    public function permissions(string|CatalogProvider ...$sources): static;   // enum/Permission class-string или провайдер
    public function roles(string ...$roleDefinitions): static;                // class-string<RoleDefinition>
    public function contexts(ContextPolicy $policy): static;
    public function constraints(string ...$constraintKeys): static;           // 'vendor/name'
    public function build(): Realm;                                            // вызывает реестр, не хост
}

final readonly class Realm
{
    public function id(): string;
    public function label(): string;
    public function contextPolicy(): ContextPolicy;
    /** @return list<class-string<RoleDefinition>> */ public function roleDefinitions(): array;
    /** @return list<string> */ public function constraintKeys(): array;
}

final readonly class ContextPolicy
{
    public static function inherit(string ...$types): self;
    public static function isolated(string ...$types): self;
    public static function required(string ...$types): self;
    public static function none(): self;
    public function requireMembership(bool $required = true): self;
    public function accepts(?ContextRef $context): bool;
}

interface RealmRegistry   // AzGuard\Contracts\Realms\RealmRegistry
{
    public function get(string $id): Realm;              // @throws UnknownRealmException
    public function find(string $id): ?Realm;
    /** @return array<string, Realm> */ public function all(): array;
    public function register(RealmProvider|string $provider): void;   // до freeze; дубликат → DuplicateRealmException
    public function replace(RealmProvider|string $provider): void;    // до freeze
    public function isFrozen(): bool;
}
```

## 7. Каталог

```php
namespace AzGuard\Contracts\Catalog;

interface PermissionCatalog
{
    /** @return list<PermissionDefinition> */ public function all(string $realm): array;
    public function find(PermissionKey $key): ?PermissionDefinition;             // точное или динамическое
    public function owns(PermissionKey $key): bool;                               // O(1)
    /** @return array<string, list<PermissionDefinition>> */ public function groups(string $realm): array;
    public function fingerprint(): string;
}

final readonly class PermissionDefinition   // AzGuard\Catalog\PermissionDefinition
{
    public PermissionKey $key; public ?string $label; public ?string $group; public ?string $description;
    public bool $dynamic; public string $provider;    // 'azguard/enum', 'azguard/filament', …
    /** @var array<string, scalar> */ public array $meta;
}

namespace AzGuard\Attributes;
#[Attribute(Attribute::TARGET_CLASS)] final readonly class Realm { public function __construct(public string $id) {} }
#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_CLASS)]
final readonly class Describe { public function __construct(public ?string $label = null, public ?string $group = null, public ?string $description = null) {} }
```

Класс-право: `AzGuard\Contracts\Permissions\Permission` — `public static function key(): string` (локальная часть) +
`#[Realm('app')]`.

## 8. Host-модель и видимость

```php
namespace AzGuard\Contracts;

interface AzGuardSubject
{
    public function hasPermission(PermissionKey|string|UnitEnum $permission, ContextRef|Model|null $context = null, ?object $resource = null): bool;
    public function hasRole(RoleKey|string $role, ContextRef|Model|null $context = null): bool;
    public function permissions(string $realm, ContextRef|Model|null $context = null): PermissionSet;
    public function isSuperadmin(string $realm): bool;
    public function azguardRef(): SubjectRef;
}

namespace AzGuard\Concerns;
trait HasAzGuard { /* реализует AzGuardSubject через AzGuard::for($this) */ }

trait ContextAware   // на модели, которая служит контекстом (Project, Workspace)
{
    /** @param Builder<static> $query */
    public function scopeVisibleTo(Builder $query, mixed $subject, PermissionKey|string|UnitEnum $permission): void;
    public function azguardContext(): ContextRef;
}

namespace AzGuard\Authorization;
final class Visibility
{
    /** @template T of Builder */
    public function constrain(Builder $query, mixed $subject, PermissionKey|string|UnitEnum $permission, ?string $contextType = null): Builder;
}
```

`hasPermission()` не глотает ошибки (было `checkPermission()` с `catch Throwable`): отказ — `false`, ошибка
конфигурации — исключение; в Blade используется `@can`.

## 9. Исключения

| Класс | Код | Родитель | Когда |
|---|---|---|---|
| `InvalidConfigurationException` | `invalid_configuration` (+ подкод в сообщении) | `AzGuardException` | boot-валидация |
| `DuplicateRealmException` | `duplicate_realm` | `DefinitionException` | повторная регистрация |
| `UnknownRealmException` | `unknown_realm` | `DefinitionException` | ключ/роль неизвестного realm |
| `RegistryFrozenException` | `registry_frozen` | `DefinitionException` | регистрация после boot |
| `DuplicatePermissionException` | `duplicate_permission` | `DefinitionException` | коллизия провайдеров каталога |
| `RoleDefinitionException` | `role_definition_invalid` | `DefinitionException` | sync/запись code-роли |
| `InvalidRealmIdException` | `invalid_realm_id` | `InvalidIdentityException` | грамматика realm |
| `InvalidPermissionKeyException` | `invalid_permission_key` | `InvalidIdentityException` | грамматика ключа/шаблона |
| `UnqualifiedPermissionException` | `unqualified_permission` | `InvalidIdentityException` | строка без realm |
| `InvalidRoleKeyException` | `invalid_role_key` | `InvalidIdentityException` | |
| `InvalidContextException` | `invalid_context` | `InvalidIdentityException` | тип с `:`, пустой id |
| `UnknownPermissionException` | `unknown_permission` | `AccessManagementException` | выдача ключа вне каталога |
| `UnknownRoleException` | `unknown_role` | `AccessManagementException` | назначение несуществующей роли (было молчание) |
| `UnsupportedContextException` | `unsupported_context` | `AccessManagementException` | тип контекста не принят realm |
| `ImmutableRoleException` | `immutable_role` | `AccessManagementException` | изменение code-роли |
| `StaleSelectionException` | `stale_selection` | `AccessManagementException` | устаревший fingerprint |
| `MissingActorException` | `missing_actor` | `AccessManagementException` | запись без актора |
| `AccessManagementDeniedException` | `delegation_denied` | `Illuminate\Auth\Access\AuthorizationException` | делегирование (403) |
| `UnsupportedDirectWriteException` | `unsupported_direct_write` | `AzGuardException` | запись модели вне `mutate()` (local/testing) |
| `PermissionSourceException` | `source_failed` | `AuthorizationEngineException` | ошибка источника (наружу, fail-closed) |

Отказ доступа — `Illuminate\Auth\Access\AuthorizationException` (из Gate/`AzGuard::authorize()`), с
`Decision` в `$exception->response()->code()` = `DecisionReason::value`.
