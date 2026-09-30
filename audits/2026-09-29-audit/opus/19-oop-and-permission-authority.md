# 19 — Явная ООП-конфигурация и источник решения права

Пересмотр по уточнениям владельца 2026-09-30. Это нормативная целевая спецификация, не runtime implementation.
D80–D83 уточняют прежнюю расширенную схему. [18](18-contexts-and-runtime-inputs.md) — inputs/filters,
[16](16-crm-and-workflows.md) — CRM; [17](17-crm-acceptance-tests.md) — реальная приёмка.

## 1. Определение и назначение — разные объекты

| Что | Где определяется | Что можно изменить в БД |
|---|---|---|
| Право ClientPermission::Update | PHP enum/class, stable key/mode/policy | кому/на какой tenant/project/до какого срока назначено |
| Роль SellerRole | PHP BaseRole: permissions/context/superAdmin | назначения этой роли; состав не редактируется |
| ProjectContext/SellerProjects | PHP descriptor/filter с typed constructor | business Project/User данные; не DSL фильтра |
| Дополнительное campaigns.export | opt-in scoped dynamic catalogue | наличие/label и назначения; mode всегда Grants |
| Plugin | собственная typed factory/class configuration | разрешённые business data, не произвольный plugin options JSON |

Enum definitions не копируются в permissions table для назначения. DatabaseSource без dynamicPermissions хранит
role_grants/permission_grants известного PHP-каталога. Folder/GrantedAutomatically/RelationSource могут вычислять
назначения этих же классов без assignment DB. Наличие grant store не означает definitions из БД.

## 2. Для одного права — один authority mode

```php
#[Resource(label: 'Клиенты', model: Client::class)]
#[RequiresGrant]
enum ClientPermission: string
{
    case View = 'clients.view';
    case Update = 'clients.update';
    #[PolicyOnly] case ViewOwnProfile = 'clients.view_own_profile';
}

#[PolicyFor(ClientPermission::class)]
final class ClientPolicy
{
    public function update(User $user, Client $client): bool
    {
        return !$client->do_not_call; // pass/veto; без Update grant не разрешает
    }
    public function viewOwnProfile(User $user, Client $client): bool
    {
        return $client->owner_user_id === $user->getKey(); // sole authority этого action
    }
}
```

| Mode | Где authority | Значение policy true / null / false | Назначение через code/DB |
|---|---|---|---|
| PolicyOnly | ровно одна PHP policy binding | allow / deny / deny | запрещено для этого права |
| RequiresGrant | qualified direct/role/fixed/relation grants, scoped superadmin | pass / pass / deny | разрешено; policy не создаёт отсутствующий grant |

Для grant-side ограничения привязка объявляется отдельно от метода:

```php
$panel->policies([
    PolicyBinding::for(ClientPermission::Update, ClientPolicy::class, method: 'update'),
    PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class, method: 'viewOwnProfile'),
]);
```

Если update переименован/удалён, compiler выдаёт DefinitionException. Grant mode не угадывает наличие veto по
случайно найденному методу. PolicyOnly допускает однозначный folder pairing, но binding обязателен в любом случае.

Для PolicyOnly matchingGrants/matchingRoles пусты, grant sources не вызываются; app business SQL/service DI внутри
policy допустимы. «Без базы назначений» не означает запрет любых запросов к собственной CRM модели.
Для RequiresGrant policy отсутствует — pass; явно declared binding/method, который отсутствует, — compile error.
Response::allow/deny нормализуется через allowed(), denial status/message/code сохраняются. Runtime error — deny.
Policy true никогда не OR с DB grant. PolicyOnly null не означает «поискать альтернативу». Все modes проходят owner,
membership, common active, token cap и mandatory restrictions. Role-specific filters относятся только к contribution.

Modes задаются явно на enum/case (case override enum); отсутствие режима/both annotations — DefinitionException.
Custom PermissionDefinition SPI возвращает PermissionAuthority enum. Dynamic actions всегда Grants; UI не хранит
PolicyOnly/class/method, не создаёт code binding по строке. Для динамического action можно подключить только
серверное ограничение с точной declared action/pattern semantics; runtime policy editing отсутствует.

Before hooks имеют typed BeforeResult::Deny/Continue; exception denies, Continue не является authority. Superadmin role даёт authority только в Grants mode и не снимает attached policy deny/owner/common rules.
After — наблюдение. Native Laravel Gate before может остановить Laravel раньше AzGuard: consumer не ставит permissive
callbacks перед owned bridge и выполняет protected writes через authoritative AzGuard (existing V93/R39).
ConsultsGrants/recursive policy-to-grants fallback исключён из API. PolicyOnly policy, вызывающая AzGuard для этого
же permission, — рекурсия с отказом; другие scoped permission checks — separate guarded frame, без тайного union.

## 3. Статичная роль, три способа назначения

```php
#[Role('seller')]
final class SellerRole extends BaseRole
{
    public function permissions(): array { return [ClientPermission::View, ClientPermission::Update]; }
    public function contexts(): array { return [ProjectContext::make()->query(new SellerProjects())]; }
    public function contextRequired(): bool { return true; }
}

// С БД: определение уже в PHP, строка только назначает класс конкретному subject/project.
$anna->guard('crm')->inTenant($organization)->grantRole(SellerRole::class, on: $project);
// Отдельный enum action можно назначить напрямую, без новой роли.
$anna->guard('crm')->inTenant($organization)->grantPermission(ClientPermission::Update, on: $project);
```

Ключ роли стабильный alias из #[Role]; public class-string нормализуется ровно в registered BaseRole и этот key.
RelationSource возвращает RoleContribution known class по pivot/code resolver. GrantedAutomatically.appliesTo
задаёт code rule; оно не создаёт определение роли при выполнении. Все способы используют одинаковые scope/expiry/
conditions/context filters. В UI role catalogue read-only, assignment editing отдельно. Direct grant не наследует
filter seller: если city требуется для всех, он common/restriction; это явно показано в acceptance cases.

## 4. Плагин: параметры видны в PHP

```php
final readonly class CrmModels
{
    /** @param class-string<Model&Authenticatable> $subject
     *  @param class-string<Model> $organization
     *  @param class-string<Model> $project
     *  @param class-string<Model> $client */
    public function __construct(
        public string $subject,
        public string $organization,
        public string $project,
        public string $client,
    ) {
        foreach ([$subject, $organization, $project, $client] as $class) {
            if (!is_a($class, Model::class, true)) { throw new InvalidArgumentException('Expected Model: '.$class); }
        }
        if (!is_a($subject, Authenticatable::class, true)) {
            throw new InvalidArgumentException('subject must implement Authenticatable');
        }
    }
}

final class CrmAccessPlugin extends BasePlugin
{
    private function __construct(
        private readonly CrmModels $models,
        private readonly ConfigurableContextDefinition $projects,
        private readonly string $membership,
        private readonly string $directory,
        private readonly string $clientScope,
    ) {}

    /** @param class-string<TenantMembership> $membership
     *  @param class-string<ContextDirectory> $directory
     *  @param class-string<ResourceScopeResolver> $clientScope */
    public static function make(
        CrmModels $models,
        ConfigurableContextDefinition $projects,
        string $membership,
        string $directory,
        string $clientScope,
    ): self {
        if (!is_a($membership, TenantMembership::class, true)) { throw new InvalidArgumentException('membership SPI'); }
        if (!is_a($directory, ContextDirectory::class, true)) { throw new InvalidArgumentException('directory SPI'); }
        if (!is_a($clientScope, ResourceScopeResolver::class, true)) { throw new InvalidArgumentException('clientScope SPI'); }
        if ($projects->model() !== $models->project) { throw new InvalidArgumentException('projects model mismatch'); }
        return new self($models, $projects, $membership, $directory, $clientScope);
    }
    public function id(): string { return 'acme/crm-access'; }
    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->for([$this->models->subject], guard: 'web')
            ->tenants(TenantPolicy::required($this->models->organization)->requireMembership($this->membership))
            ->contexts(ContextPolicy::inherit($this->projects->directory($this->directory)))
            ->resourceScopes([$this->models->client => $this->clientScope]);
        // Этот plugin предоставляет tenant/context wiring. Domain permissions/roles подключает app provider.
    }
    public function boot(Panel $panel, PluginContext $context): void {}
}

CrmAccessPlugin::make(
    models: new CrmModels(
        subject: User::class, organization: Organization::class,
        project: Project::class, client: Client::class,
    ),
    projects: ProjectContext::make()->query(new ActiveProjects()),
    membership: OrganizationMembership::class,
    directory: ProjectDirectory::class,
    clientScope: ClientScopeResolver::class,
);
```

CrmModels — только DTO данного plugin, не обязательный core registry. PHP не умеет native class-string subtype,
поэтому есть PHPDoc + boot validation. Тот же подход — Source storage model overrides; parameters explicit.
Абстрактная/несуществующая/неподходящая модель отклоняется до runtime; concrete instantiability/model connection/
required relation/column contracts plugin проверяет compiler/DoctorCheck. CrmModels validation выше показывает
минимальные type checks, не весь host schema qualification. Column/model mapping, если нужен adapter, описывается
отдельным typed классом plugin с конкретными properties, не arbitrary ['user','project'] key/value parser.

У BasePlugin **нет make**: различающиеся factories не переопределяют универсальную сигнатуру и не нарушают LSP.
Настройки передаются напрямую constructor/factory, methods/parameter names считаются public API. Для service DI
concrete factory использует Laravel makeWith с явной named parameter map либо services явно передают аргументом.
Build services не содержат current User/Request; runtime scoped service разрешается при capability invocation.
Blueprint cache не сериализует arbitrary DTO/service; provider создаёт их снова, active build id обязателен.
Array list классов допустим как однородный list<...>; heterogeneous config map требует конкретного typed DTO.
Native Laravel config/rules/meta arrays не запрещены: они имеют ограниченную declared schema и не описывают PHP
поведение. Строгость означает известный контракт, не искусственный запрет всех массивов/строк.

## 5. Кто владеет определениям и как их менять

Один permission key = один owner definition/source + immutable mode. Повтор enum FQCN discovery/manual registration
идемпотентен; другое определение с тем же key/mode или static/dynamic shadow — conflict, не last-wins/OR.
Assignment sources могут дополнять назначения RequiresGrant. Source ids/origins нужны для trace/revoke partitions,
не для скрытого выбора authority. Plugins не перезаписывают mode и чужую definition. Abilities описывают результат
проверки опубликованных enum items, а не отдельный editable authority registry.

Mode switch/code policy change/role edit — deployment c новым fingerprint, ревью migrated grants и cache rebuild.
Switch Grants -> Policy оставляет старые строки неактивными и доступными только admin cleanup; они не попадают в
policy access path. Switch Policy -> Grants требует новых explicit assignments; прежний Allow не мигрирует в grant.
Compile immutable semantic manifest per build; workers проверяют active build и не смешивают cached definitions разных
версий. In-flight old request не отменяется магически: protected write требует host build/revision fencing protocol.

## 6. Удаление и переименование класса роли

role_grants.role содержит stable role key, без FK к PHP. Неизвестная после deploy роль даёт zero authority/diagnostic;
cleanup доступен authorised actor по stored scope. FormerKeys — code metadata для **явной** transactional migration
existing scoped grants, не runtime fuzzy alias. Миграция сохраняет origin/scope/expiry/fields, дедуплицирует collisions
по детерминированным правилам, bumps version/events. Actor authority отдельная; dry-run показывает targets.
Removed context descriptor не делает orphan grant неудаляемым. Stable downgrade/reset меняет storage incarnation.
Нет role create/delete/syncPermissions mutation API и соответствующих definition events. code catalogue update — build
change, не ложный DB RoleUpdated event. Grant update/revoke сохраняют обычные события/locks.

## 7. Что проверять

P1.6/P2.1/P2.4/P2.5/P4.1/P4.2/P4.3/P4.4/P4.6/P4.12/P5.3/P6.8/P7.3/P8.7 реализуют эту границу.
R01–R68 и V117–V120 проверяют code roles/DB assignments, typed factories/filter DI, PolicyOnly независимость от
assignment store, RequiresGrant отсутствие policy bypass, schema/editor/raw HTTP payload, exact SQL и stale build.
До реализации эти cases future. Старый design model с policy OR grants — архив предыдущей гипотезы, не proof
актуального алгоритма; новые требования проверяются actual runtime acceptance.


ClientScopeResolver, переданный plugin, реализует host mapping для выбранной client модели; callable/container
разрешается на operation. CrmModels.client действительно участвует в resourceScopes binding. Resolver с hardcoded
другой моделью/неверной organization/project схемой отклоняет host contract qualification; DTO сам по себе не
обеспечивает совместимость колонок. Каждый вход factory имеет конкретное применение, не «на будущее».
