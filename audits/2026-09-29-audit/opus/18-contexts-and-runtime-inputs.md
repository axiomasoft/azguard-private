# 18 — Настраиваемые контексты и входы расширений

Нормативное API/spec целевой 1.0, не реализованный runtime. Пересмотр 2026-09-30: роли только PHP-классы,
filters — объекты/классы с понятным контрактом, factories — named typed parameters. Строковые profiles и generic
options bags исключены. [19](19-oop-and-permission-authority.md) задаёт authority modes и ownership definitions.
Пример — [16](16-crm-and-workflows.md); реальные acceptance requirements — [17](17-crm-acceptance-tests.md).

## 1. Селектор guard

```php
$user->guard('admin')->hasPermission('orders.refund');
$user->guard('crm')->inTenant($organization)->grantRole('seller', on: $project);
$user->azguard()->guard('crm'); // тот же SubjectAccess
```

guard('admin') выбирает **панель авторизации AzGuard**. `PanelBuilder::for(model: User::class, guard: 'web')`
выбирает **Laravel authentication guard**, а `Auth::guard('web')` принадлежит Laravel. Имена этих пространств
независимы: CRM-панель может использовать web guard, две панели — один web guard.
Panel/PanelBuilder/PanelAccess, `AzGuard::panel()` и middleware panel hints сохраняют свои значения.
Строковый селектор не переключает Auth manager/guard/default и не меняет модель; это immutable SubjectAccess.
**У Eloquent уже есть guard(array $guarded)** для mass assignment. Поэтому string-only override на модели
запрещён: несовместимая PHP сигнатура ломает загрузку класса. HasAzGuard на Eloquent-модели использует адаптер:

```php
public function guard(array|string $guarded): static|SubjectAccess
{
    if (is_array($guarded)) {
        return parent::guard($guarded); // native behavior, возвращает эту модель
    }
    return $this->azguard()->guard($guarded); // immutable selector, не меняет guarded
}
```

Сохранены имя параметра `$guarded`, native named argument, guarded/fill/mergeGuarded behavior. String return type
выводится через PHPDoc conditional return для IDE/PHPStan; runtime signature — union. SubjectPanels::guard(string $id)
не имеет конфликта и возвращает только SubjectAccess. Native guard([]) сохраняет native mass-assignment семантику,
не выбирает панель. Метод приложения, уже переопределяющий guard, требует явного согласованного адаптера/trait alias;
его существующий контракт не заменяется молча. Для такого приложения `$user->azguard()->guard('crm')` доступен
без model selector adapter; HasAzGuard split/alias documented в consumer guide. V108/R03 проверяют обе ветки.
Текущий vendor подтверждает совместимость сигнатуры; весь объявленный Laravel matrix проверяется реальными consumers.

## 2. Контекст как конфигурируемое определение

```php
// app/Guards/Crm/Queries/Projects/ActiveProjects.php
final class ActiveProjects implements AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('is_active', true);
    }
}

// app/Guards/Crm/Queries/Projects/SellerProjects.php
final class SellerProjects implements AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $user = $runtime->user;
        if (!$user instanceof User || $user->city_id === null) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where('city_id', $user->city_id);
    }
}

$panel->scopes(AssignmentScopePolicy::inherit(
    ProjectScope::make()->filter(new ActiveProjects())->directory(ProjectDirectory::class),
));

#[Role('seller')]
final class SellerRole extends BaseRole
{
    public function scopes(): array
    {
        return [ProjectScope::make()->filter(new SellerProjects())];
    }
    public function scopeRequired(): bool { return true; }
    public function permissions(): array { return [ClientPermission::View, ClientPermission::Update]; }
}
```

ProjectScope реализует structural AssignmentScopeDefinition: type/model/resolve; query/tenantOf у Eloquent adapter. Identity и owner не зависят
от фильтров, текущего Auth или активного проекта. Query — настоящий Eloquent Builder; filters добавляют predicate.
Сразу видно, кто задаёт active/city, где поля и откуда пользователь; реестра seller-city/произвольной SQL column нет.
Чтобы фильтру нужны настройки, он объявляет typed constructor, например `new ProjectsInRegion(region: $region)`.
Region — проверенный value object приложения; core не вводит произвольный field/operator JSON язык.

filter принимает AssignmentScopeFilter object, exact class-string этого SPI или Closure. Class-string — FQCN класса,
разрешаемого container на operation, не строковый alias профиля. Объект хранит только immutable config;
если constructor требует request-scoped service, используется class-string/factory собственного класса, не
объект с захваченным Request. Callable injection для Closure — native Container::call с reserved runtime inputs.
Сервисные constructor dependencies class filter разрешаются на operation и не кешируются как build recipe.

Concrete context::make() создаёт новую configuration; BaseAssignmentScope не объявляет универсальную factory. filter/label/directory возвращают clone. Class shorthand
ProjectScope::class означает definition без role-specific добавлений. Role binding не меняет зарегистрированные
model/type/owner и не удаляет common predicates. Defaults/plugin/provider common filters добавляются AND;
identity conflicts отклоняются. Label/directory presentation имеют явный precedence.

## 3. Что получает callback

AssignmentScopeRuntime — immutable operation input, создаваемый отдельно для common/direct/каждой role contribution.

| Вход | Значение |
|---|---|
| user | target subject Model, не implicit Auth::user; nullable при неразрешимом ref |
| role | настоящий BaseRole PHP-класс этой contribution; null у common/direct/policy-only |
| grant | конкретная Grant/RoleContribution или proposed assignment; null при отсутствии |
| actor / actorModel | инициатор операции и его Model; не подменяет target user |
| panel / scope / now / phase | выбранная панель, tenant/context, фиксированное время, назначение операции |
| runtime | все эти входы; query передаётся отдельно |

```php
ProjectScope::make()->filter(
    function (Builder $query, User $user, ?BaseRole $role, AssignmentScopeRuntime $runtime): void {
        $query->where('city_id', $user->city_id);
        if ($role instanceof SellerRole) {
            // У класса есть явно объявленные methods/settings; нет roleModel/field('region_id').
        }
    },
);
```

Compiler проверяет nullable/type контракты. Container::call получает reserved значения по имени и однозначному
типу; остальные сервисы — native DI. Нельзя глобально bind User/BaseRole/AssignmentScopeRuntime или получить пустую ORM
модель вместо отсутствующего target. Required non-null User/BaseRole при null/type mismatch — ошибка/отказ.
Raw модели callback может читать, но не сохранять/мутировать. Readonly frame не делает Model immutable.
Freshness host city/membership/active зависит от adapter/revision/чтения, а не одного panel state version.

## 4. Формула и query boundary

```
boundary = immutable_owner_and_membership AND global_context_eligibility AND mandatory_restrictions

PolicyOnly:    allow = boundary AND policy_allows
RequiresGrant: allow = boundary
                       AND OR(each_qualified_role_or_direct_contribution)
                       AND attached_policy_does_not_deny
```

Scope/expiry/conditions/role-specific filters квалифицируют одну contribution перед OR. Seller city не ограничивает
независимую Analyst contribution. Scoped superadmin в Grants mode тоже проходит свой binding. Direct grant без роли
проходит common filter/own conditions, а seller filter к нему не добавляется неявно. `policy=true` в RequiresGrant
не компенсирует отсутствующее назначение. PolicyOnly вообще не собирает grants/roles AzGuard.
Обязательная assigned-project бизнес-граница должна быть RequiresGrant + context, а не policy-only обходной allow.

Scalar выполняет EXISTS по той же context predicate composition, что exact list/count/export. Client scope resolver
связывает client.project_id с Project identity; идентификаторы Client и Project не сравниваются между собой.
Callback изменяет только дополнительную группу WHERE fresh builder. Core отдельно добавляет outer owner/key/
common predicates; OR фильтра группируется и не снимает границы. Common и role filters имеют отдельные builders.
Model global scopes не объявляются неизменяемой boundary, removable scopes не удаляют core predicates.

Default contract: where/whereIn/whereHas/grouped OR/predicate-only local scopes, bound values. Terminal get/paginate/
write, builder/model/from/connection replacement, select/order/limit/union/root joins требуют отдельного exact
AssignmentScopeAccessAdapter или отклоняются. PHP filter — trusted code, не sandbox. Arbitrary policy/external service/cross
DB/сложный join не обещаются автоматически exact. Unsupported query вызывает исключение, не широкий fallback.

## 5. Фазы: доступ, назначение и отзыв

AssignmentScopePhase: Access, Assignment, Revocation, Inspection. Record/list/count/export/job используют Access при одном
observed frame/now; фаза не зависит от surface. Assignment search и final write получают target user/BaseRole/
proposed fields отдельно от actor. Фильтр autocomplete не заменяет validation под mutation lock после pipes.
Revocation разрешён authorised scoped actor для inactive/expired/orphan grants по stored scope, без обязательной
текущей eligibility. Inspection stale assignments требует admin authority, не grants target user.
Update срока/полей — повторная Assignment validation. Host owner/city/project TOCTOU закрывает host lock/revision
protocol; panel_state сам по себе не блокирует перенос Project другим процессом (09 §14).

## 6. Классы ролей, фильтры и назначения

```php
// Definition и фильтры в PHP:
final class ProjectsInRegion implements AssignmentScopeFilter
{
    public function __construct(private readonly RegionCode $region) {}
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('region_code', $this->region->value);
    }
}

// Assignment известного класса в БД:
$anna->guard('crm')->inTenant($organization)->grantRole(SellerRole::class, on: $project);
// Не grantRole создать SellerRole — она уже объявлена и зарегистрирована в PHP.
```

Нет RoleCatalog.create/update/syncPermissions, roleModel, JSON context profiles или UI изменения состава роли.
RoleCatalog только читает registered definitions. GrantManager/SubjectAccess меняют её назначения, сроки и declared
fields. Relation/code sources могут вычислять назначения того же класса без таблиц AzGuard. Дополнительный dynamic
action включается явно и назначается как PermissionGrant; создание action не создаёт роль/политику/контекст.
Удаление класса из deployment делает старые grants inactive, но не блокирует cleanup. FormerKeys миграция явная.

## 7. Плагины: inputs на этапе сборки и исполнения

```php
$panel->plugins([
    CrmAccessPlugin::make(
        models: new CrmModels(
            subject: User::class, organization: Organization::class,
            project: Project::class, client: Client::class,
        ),
        projects: ProjectScope::make()->filter(new ActiveProjects()),
        membership: OrganizationMembership::class,
        directory: ProjectDirectory::class,
        clientScope: ClientScopeResolver::class,
    ),
    AuditTrailPlugin::make(retentionDays: 90),
]);
```

CrmModels принадлежит конкретному plugin; named class-string fields валидируются против Model/Authenticatable/
required contracts (19 §4). Параметры models/projects/membership/directory существуют в PHP factory signature;
ошибка имени/type/model disagreement даёт actionable boot error. Generic options['models']['user'] нет.
BasePlugin не объявляет make, чтобы у factories были собственные совместимые typed signatures. Есть только
Plugin lifecycle id/register/boot и optional prefix helper. Model/context configuration не является authority.

PluginContext — build id/panel/plugin/namespace/declared dependencies, без общего options getter/service locator.
Plugin получает настройки из собственных typed полей. Runtime capabilities получают AssignmentScopeRuntime/EvaluationContext/
LookupContext/ChangeContext на operation. Scope/user/BaseRole/grant/actor не читаются из boot Auth. Listener получает
committed refs/scalars и при необходимости перечитывает данные; не cached Model/Request.
Factories fresh и не мутируют recipe; custom binding returning shared mutable object отклоняется/явно изолируется.
Разные настройки одного plugin в разных панелях поддерживаются. Duplicate id, requires/removal, listener dedup и
secrets references проверяются; debug/schema не публикуют secrets. Concurrent fibers требуют explicit frames,
request/job scoped container сам по себе их не изолирует.

## 8. Кэш и воспроизводимость

Frozen catalog содержит metadata: aliases/classes/authority modes/filter class identities/display/permissions,
но не Model/Request/Builder/Container/Closure/runtime services. Providers/plugins заново создают PHP definitions;
cache ускоряет reflection/discovery, а не является универсальным serializer произвольных объектов.
Fingerprint includes normalized metadata + active deployment build id. Filter typed config остаётся в исполняемом
provider code, не JSON DSL. Code/config change требует нового build id; отсутствующий/недетерминированный id —
production cache gate error. Closure нельзя захватывать live User/Model/Builder; arbitrary PHP state не sandboxed.

Authority mode неизменяем для static definitions/dynamic action. Для PolicyOnly assignment store не становится
dependency проверки. Grants sources/host dependencies имеют собственные declared freshness contracts; final Allow
не кешируется по DB version, если city/active/policy изменились независимо. SQL план groups branch witnesses,
использует EXISTS/set-based predicates, а не N запросов на N clients. Supported budgets/chunks/revisions явны.
External/cross-connection данные не объявляются одним snapshot.

## 9. Сверка расширяемости всех поверхностей

| Поверхность | Configuration в PHP | Runtime input |
|---|---|---|
| Permission | enum/class + PolicyOnly/RequiresGrant, policy/query adapter | same scope/boundary/authority mode |
| Role | BaseRole.permissions/contexts/required/superAdmin | actual BaseRole и qualifying contribution |
| Source | конкретные constructor/fluent args, storage/models | subject/scope/EvaluationContext; role definitions не DB |
| Context | descriptor + typed filter objects/classes, directory | user/role/grant/actor/now/phase + Builder |
| Policy | exact binding + services | user/resource + EvaluationContext; authority или veto по mode |
| Directory | конкретный class/typed config | LookupContext target отдельно от actor, LIMIT после filters |
| Plugin | собственная named typed factory, CrmModels/definitions | capabilities получают fresh frames при вызове |
| Change pipe | DI class/typed constructor settings | Change::context; retry/final validation re-resolve |
| Editor | schema permissions/modes/code roles/declared fields | меняет grants и opt-in actions, не definitions/filters |
| Job | serialize refs/ids/scalars | fresh resolve/authorize при исполнении |

## 10. Формы входов и правило обновления

```php
namespace AzGuard\Scopes;
final readonly class AssignmentScopeRuntime
{
    public Panel $panel; public AccessScope $scope; public SubjectRef $subject;
    public ?Model $user; public ?BaseRole $role; public Grant|RoleContribution|null $grant;
    public ActorRef $actor; public ?Model $actorModel;
    public DateTimeImmutable $now; public AssignmentScopePhase $phase;
}
enum AssignmentScopePhase: string
{
    case Access = 'access'; case Assignment = 'assignment';
    case Revocation = 'revocation'; case Inspection = 'inspection';
}
namespace AzGuard\Directories;
final readonly class LookupContext
{
    public Panel $panel; public AccessScope $scope;
    public ActorRef $actor; public ?Model $actorModel;
    public ?SubjectRef $subject; public ?Model $user; public ?BaseRole $role;
    public array $proposed; // only schema-validated assignment fields/expiry, not role config
    public AssignmentScopePhase $phase; public DateTimeImmutable $now;
}
namespace AzGuard\Plugins;
final readonly class PluginContext
{
    public function panelId(): string; public function pluginId(): string;
    public function buildId(): string; public function namespace(): ?string;
    /** @return list<string> */ public function dependencies(): array;
}
namespace AzGuard\Changes;
final readonly class ChangeContext
{
    public Panel $panel; public AccessScope $scope;
    public ActorRef $actor; public ?Model $actorModel;
    public ?SubjectRef $subject; public ?Model $user; public ?BaseRole $role;
    public ChangeType $operation; public AssignmentScopePhase $phase;
    public array $proposed; public DateTimeImmutable $now; public CodeStateToken|StateToken $state;
}
```

Query builder передаётся отдельно. BaseRole nullable в common/direct/policy-only frames. Lookup target может
быть null при выборе subject; actual assignment проверяет selected target до context search/write.
Change::withUntil/withFields пересобирает derived input для proposed values; final validation/transaction retry разрешают свежие
references/dependencies. Delete/orphan role/subject не создаёт фиктивные модели и не блокирует authorised cleanup.
Pipeline остаётся handle(Change, Closure next). Readonly моделей глубокую immutability не обещает.


Optional RequiresGrant veto объявляет PolicyBinding(action, policy class); метод обязан иметь #[Decides(action)].
Missing declared class/attributed method — compile error; имя метода можно менять с сохранением атрибута. PolicyOnly binding всегда обязателен;
folder/PolicyFor/Decides pairing допустим при однозначной цели. Метод не переименовывается в silent no-policy pass.
