# 16 — CRM: тенант, класс проекта, роли, политики и динамика

Доработка по запросу владельца от **2026-09-30**. Это пример **целевого API**, не код работающей 0.3.
Обязательные контракты — [05](05-php-api.md), [06](06-extension-points.md), схема — [08](08-data-model-and-migration.md),
семантика — [09](09-authorization-semantics.md). Названия Panel/Role/Source/Policy сохранены.

## 1. Что должен уметь пример

Анна состоит в организациях A и B. В A ей назначена роль `caller` на проекты P1 и P2;
в B — `analyst` на P3. Она может читать и обзванивать клиентов P1/P2 организации A;
в B ей доступен только просмотр P3. Другие проекты, организации и панель `backoffice` не получают этих прав.
Владелец A может менять назначения PHP-классов ролей и, если включено, создать дополнительное действие без нового релиза.
Политика клиента запрещает изменение при `do_not_call`; ограничения аккаунта и токена действуют на все разрешения.

```
Панель crm
  ├── tenant Organization A
  │    ├── Project P1 ── Role caller (класс) ── Анна
  │    ├── Project P2 ── Role caller (класс) ── Анна
  │    └── Project P4 ── Role campaign-lead (БД) ── Борис
  └── tenant Organization B
       └── Project P3 ── Role analyst (класс) ── Анна

Клиент -> его organization + его project -> соответствующая выдача -> policy -> restrictions
```

Класс `ProjectContext` описывает **тип области назначения**, не отдельный проект и не новый Eloquent Project.
Его экземпляры данных — проекты P1/P2/P3. Десять тысяч проектов не создают десять тысяч PHP-классов.
Несколько ролей ссылаются на один класс. Другой пакет может принести собственный класс, реализующий тот же SPI.

## 2. Папка панели

```text
app/Guards/Crm/
├── CrmGuardPanelProvider.php
├── Permissions/Clients/ClientPermission.php
├── Policies/Clients/ClientPolicy.php
├── Queries/Clients/ClientVisibility.php
├── Contexts/ProjectContext.php
├── Roles/{Caller,Analyst}Role.php
├── Resolvers/ClientScopeResolver.php
├── Restrictions/AccountLockedRestriction.php
└── Changes/AuthorizeCrmAccessChange.php

app/Models/{Organization,Project,Client,User}.php  # business models приложения
```

`Permissions/Clients`, `Policies/Clients` и `Queries/Clients` находятся прямо в панели (D72).
В этом примере P1/P2 организации A активны и находятся в городе Анны; P3 организации B может иметь другой город.
Fixture ids/города в 17 заданы отдельно для приёмки, чтобы проверять несовместимые роли.
`Contexts/` обнаруживается `FolderSource`, как `Roles/`. Обнаружение регистрирует descriptor и не включает
его во все панели автоматически. `ContextPolicy` выбирает разрешённые классы явно. Никакая папка не заменяет
tenant membership, grants и принадлежность самого ресурса.

## 3. Свой контракт ProjectContext

Контракт определён ядром как `AzGuard\Contracts\Contexts\ContextDefinition` (`@spi`).
Короткая база `BaseContext` — удобство; контракт можно реализовать напрямую.
Каноническая сигнатура — [06 §7](06-extension-points.md#7-контексты-и-субъекты).

```php
// app/Guards/Crm/Contexts/ProjectContext.php
final class ProjectContext extends BaseContext
{
    public static function make(): self { return new self(); }
    public function type(): string { return 'crm.project'; } // зарегистрированный стабильный alias, не FQCN
    public function model(): ?string { return Project::class; }

    public function exists(ContextRef $context): bool
    {
        return Project::withoutGlobalScopes()->whereKey($context->id())->exists();
    }

    public function tenantOf(ContextRef $context): TenantRef
    {
        $project = Project::withoutGlobalScopes()->findOrFail($context->id());
        return TenantRef::of('crm.organization', $project->organization_id);
    }
}
```

Structural exists/tenantOf читают authoritative host rows без текущих Auth/active/city global scopes.
Это не публичная выдача Project: core проверяет выбранный owner и membership, затем eligibility query.
Soft-deleted/неактивный project не становится доступным от обхода scopes; Access query/host deletion policy
применяются отдельно. Revocation использует сохранённый scope, даже если Project физически удалён.

Core resolver проверяет type, existence и tenantOf перед использованием ref. Класс не выбирает текущего
пользователя, панель, роли или права и не пишет выдачи. Поиск проектов для редактора — отдельный `ContextDirectory`
со scope/actor, чтобы autocomplete не раскрывал проекты другой организации. Для внешнего проекта `model()`
может вернуть null, а `exists`/`tenantOf` реализует adapter внешнего пакета; timeout даёт отказ.
Для горячего пути resolver memo/preloading сокращает повторные запросы, не отменяя declared freshness.

Если локальные Project id повторяются в отдельных БД, пакеты преобразуют их в стабильную host identity либо
используют разные зарегистрированные type namespaces. Одного `Project::class + id=7` недостаточно для двух
разных физических identity domains; это не решается случайным переключением connection у модели.

## 4. Роли связываются с классом проекта

```php
#[Role('caller', label: 'Менеджер обзвона')]
final class CallerRole extends BaseRole
{
    public function contexts(): array { return [ProjectContext::make()->query(new SellerProjects())]; }
    public function contextRequired(): bool { return true; }
    public function permissions(): array
    {
        return [ClientPermission::ViewAny, ClientPermission::View, ClientPermission::Update];
    }
}
#[Role('analyst', label: 'Аналитик проекта')]
final class AnalystRole extends BaseRole
{
    public function contexts(): array { return [ProjectContext::class]; }
    public function contextRequired(): bool { return true; }
    public function permissions(): array { return [ClientPermission::ViewAny, ClientPermission::View]; }
}
```

Оба класса связаны с ProjectContext. Caller добавляет явный SellerProjects filter, Analyst — без city restriction;
common ActiveProjects действует на обе ветки. Empty contexts = tenant-wide only, required требует concrete context.
ProjectContext/TeamContext class-string — FQCN известных definitions, не aliases реестра фильтров.
Роли не создаются/не редактируются в БД; таблица хранит назначения этих классов и их stable keys.

## 5. Статичные действия и политика

```php
#[Resource(label: 'Клиенты', model: Client::class)]
#[RequiresGrant]
enum ClientPermission: string
{
    #[RequiresGrant] case ViewAny = 'clients.view_any';
    #[RequiresGrant] case View = 'clients.view';
    case Update = 'clients.update';
    #[PolicyOnly] case ViewOwnProfile = 'clients.view_own_profile';
}

final class ClientPolicy
{
    public function viewOwnProfile(User $user, Client $client): bool
    {
        return $client->owner_user_id === $user->getKey();
    }
    public function update(User $user, Client $client): ?bool
    {
        return $client->do_not_call ? false : null;
    }
}
```

`null` сохраняет ответ scoped grants. В RequiresGrant policy true не компенсирует отсутствующее назначение CallerRole.
Постоянную business boundary «для каждого allow нужен assigned project» задают restriction/query adapter,
если панель намеренно использует PolicyOnly actions. В показанном минимальном рецепте
View/Update клиентов требуют role/direct grants в project scope. Отдельное PolicyOnly ViewOwnProfile
решает owner policy после common active project/tenant membership/owner checks; role filters к нему не применяются.
Если и этому action нужна project membership, это отдельный host business predicate в policy/common restriction,
а не неявное чтение role grants.

## 6. Конструктор панели

```php
final class CrmGuardPanelProvider extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->id('crm')
            ->for([User::class], guard: 'web')
            ->tenants(TenantPolicy::required(Organization::class)
                ->requireMembership(OrganizationMembership::class))
            ->contexts(ContextPolicy::inherit(ProjectContext::make()
                ->query(new ActiveProjects())
                ->directory(ProjectDirectory::class)))
            ->resourceScopes([Client::class => ClientScopeResolver::class])
            ->permissions([DatabaseSource::make()->dynamicPermissions()])
            ->policies([
                PolicyBinding::for(ClientPermission::Update, ClientPolicy::class, method: 'update'),
                PolicyBinding::for(ClientPermission::ViewOwnProfile, ClientPolicy::class, method: 'viewOwnProfile'),
            ])
            ->restrictions([AccountLockedRestriction::class])
            ->changing([AuthorizeCrmAccessChange::class]);
    }
}
```

Почему `inherit`, а не `required`: `view_any` проверяется без конкретного client/project,
а scoped caller всё равно требует project **при назначении**. Collection permission может иметь отдельную
tenant-wide UI роль/право `clients.view_any`, доступное активным сотрудникам. CallerRole grant в P1
сам по себе не делает `view_any` истинным при context=global. Приложение явно выдаёт tenant-wide `view_any`
или реализует policy «есть хотя бы один доступный проект» с paired query semantics.
Наличие `view_any` не даёт `clients.view` и не снимает фильтр списка.

`ClientScopeResolver` реализует `ResourceScopeResolver`, возвращает:

```php
public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
{
    assert($resource instanceof Client); // production adapter валидирует тип, не полагается на assert
    return AccessScope::in(
        TenantRef::of('crm.organization', $resource->organization_id),
        ContextRef::of('crm.project', $resource->project_id),
    );
}
```

Существование project и совпадение его tenant с client tenant проверяет движок через ProjectContext.
`selected` не позволяет переписать tenant клиента: mismatch -> Deny. Для списка ClientVisibility строит
тот же ownership predicate в SQL. Для create атрибуты проверяются сервером до записи; project dropdown ограничен
выбранным tenant, а payload с project другого tenant отклоняется повторной серверной валидацией.

## 7. Назначения классов ролей и дополнительные права

```php
$anna->guard('crm')->inTenant($organizationA)->grantRole(CallerRole::class, on: $projectP1);
$anna->guard('crm')->inTenant($organizationA)->grantRole(AnalystRole::class, on: $projectP2);
$anna->guard('crm')->inTenant($organizationB)->grantRole(AnalystRole::class, on: $projectP3);

// Enum definition в коде, direct assignment в БД:
$anna->guard('crm')->inTenant($organizationA)->grantPermission(ClientPermission::View, on: $projectP1);

// Необязательный runtime action: панель явно подключила dynamicPermissions().
$panelA = AzGuard::panel('crm')->inTenant($organizationA);
$panelA->permissions()->create('campaigns.export', label: 'Экспорт кампании');
$anna->guard('crm')->inTenant($organizationA)->grantPermission('campaigns.export', on: $projectP1);
```

В tenant B этот dynamic action не появляется. Он имеет RequiresGrant authority; его создание не создаёт роль
и не меняет CallerRole.permissions. По умолчанию enum права + DB assignments уже работают без dynamicPermissions.
Те же BaseRole классы может назначать RelationSource через membership pivot или GrantedAutomatically правило.
RoleContribution сохраняет конкретный tenant/project/origin/witness, expansion централизован по PHP-каталогу.
Role key/FQCN accepted API нормализуются в registered class; неизвестный класс/key не является новой ролью.

## 8. Проверки и ожидаемые результаты

```php
$crmA->hasPermission(ClientPermission::View, on: $clientInA1);   // true
$crmA->hasPermission(ClientPermission::Update, on: $clientInA1); // true, пока do_not_call=false
$crmA->hasPermission(ClientPermission::View, on: $clientInA4);   // false: нет назначения
$crmA->hasPermission(ClientPermission::View, on: $clientInB3);   // false: tenant mismatch
$anna->guard('crm')->inTenant($organizationB)
    ->hasPermission(ClientPermission::Update, on: $clientInB3); // false: analyst даёт только View
$crmA->revokeRole('caller', on: $projectA1);                      // P2 и B/P3 сохраняются
```

| Вход | Grants | Policy / boundary | Итог |
|---|---|---|---|
| A/P1, caller | View/Update | client принадлежит A/P1 | разрешён |
| A/P1, caller, do_not_call | Update | update=false | отказ |
| A/P4, у Анны caller только P1/P2 | нет | update=null | отказ |
| выбран A, resource B/P3 | любые | ownership mismatch | отказ до разрешающей policy |
| B/P3, analyst | View | update=null | просмотр да; изменение нет |
| A/P1 после expiresAt | нет active grant | null | отказ |
| A/P1, global ordinary caller вне tenant | не применимо | tenant boundary | отказ |
| A/P1, locked user / token без ability | есть | restriction deny | отказ |
| tenant admin A, resource B/P3 | superadmin A | mismatch | отказ |
| A/P4, CallerRole + direct campaigns.export | Update + opt-in export action | ClientPolicy только Update | do_not_call запрещает Update; export требует собственного scoped grant |

`AccessScope` в explain показывает tenant и project отдельно. `isSuperAdmin`/permissionNames не заменяют
эти окончательные решения.

## 9. Список клиентов и Filament

```php
$query = AzGuard::panel('crm')->inTenant($organizationA)->visibility()
    ->constrain(Client::query(), $anna, ClientPermission::View);
$clients = $query->orderBy('id')->paginate(50);
```

В CRM exact query adapter имеет tenant-owner predicate и EXISTS активных scoped grants по project клиента,
развёрнутым code-defined role permissions; источник/условия каждой grant находятся в одной EXISTS ветке.
Для `clients.view` политики нет (`RequiresGrant`), поэтому membership/account restrictions также предоставляют
exact predicates или request-wide pass/deny. Для `clients.update` ClientVisibility добавляет `do_not_call=false`.
Нет query adapter хотя бы для одного влияющего компонента -> ошибка, не широкий список с warning.

Conceptual SQL (на одном connection; параметры bound):

```sql
SELECT c.* FROM clients c
WHERE c.organization_id = :tenant
  AND EXISTS (SELECT 1 FROM projects p
              WHERE p.id = c.project_id AND p.organization_id = c.organization_id)
  AND active_membership(:subject, :tenant)
  AND account_not_locked(:subject)
  AND scoped_grant_covers(:panel, :tenant_key, :subject, 'clients.view', c.project_id, :now)
ORDER BY c.id
LIMIT :limit OFFSET :offset;
```

Имена `active_membership`, `scoped_grant_covers` здесь — логические предикаты, не предлагаемые SQL-функции.
Адаптер строит EXISTS/joins; никакой генерации SQL из arbitrary PHP policies нет.

Filament request каждый раз восстанавливает panel+tenant из доверенного server routing/session selection;
Livewire payload заново проверяет actor и target scope. Resource edit/delete, relation attach, bulk actions,
global search, widgets/counts, exports и background jobs используют тот же authority contract.
RoleResource показывает PHP-классы read-only; SubjectGrantsResource редактирует назначения в выбранном tenant.
Выбор «проект» открывает только directory текущего tenant; смена tenant очищает project/role/fields/state формы.
Id строки выдачи проверяется вместе с panel+tenant+origin, иначе возможен IDOR.

## 10. Несколько внешних систем одного тенанта

Приложение/пакет-владелец хранит mappings:

```text
organization_external_links
  id, organization_id, provider_key, installation_key, external_tenant_id, revision, status
  UNIQUE(provider_key, installation_key, external_tenant_id)

external_subject_links
  link_id FK, external_subject_id, host_subject_type, host_subject_id
  UNIQUE(link_id, external_subject_id)

external_memberships
  link_id, host_subject_ref, host_project_ref, role_mapping_key, expires_at, source_revision
  UNIQUE(link_id, host_subject_ref, host_project_ref, role_mapping_key)
```

Это пример данных **пакета интеграции**, не новая обязательная tenant model AzGuard. Provider/installation
отличают CRM X и CRM Y с одинаковым внешним id=7. Несколько links могут указывать на одну host Organization;
объединение identities допускается только явным проверенным mapping. Credentials держит интеграция отдельно.

Источник получает выбранный TenantRef и читает только его mappings. Он может вернуть RoleContribution
из уже синхронизированной host relation либо import пишет scoped grants с `origin='sync.crm-x'`.
Importer обновляет только свой origin; отзыв CRM X не удаляет manual и CRM Y. Revision upstream монотонна;
старый webhook не восстанавливает отозванное membership. Полный sync применяется атомарно лишь после получения
всех страниц; partial snapshot/timeout не считается «пустой источник». Повтор идентичного webhook — no-op.

Source Request/Volatile объявляет окно обновления. Требуется строгий отзыв -> синхронное suspension/revision
или live check; version AzGuard не обновляет внешний LDAP автоматически. Хост назначает единственную authority
каждому отношению, правила union и hard suspension явно. External admin role не преобразуется в SuperAdmin
без разрешённого локального mapping и deployment validation.

## 11. Варианты без изменения ядра

| Потребность | Конфигурация / класс |
|---|---|
| Один project, много разных ролей | Каждый BaseRole.contexts ссылается на ProjectContext; stored assignments сохраняют context alias |
| Назначение role на Project и Team | Две ContextDefinition; Role.contexts=[ProjectContext, TeamContext]; tenantOf каждой проверен |
| Client связан с несколькими Projects | Resolver подтверждает selected project или предоставляет полный набор candidate scopes; existential check оценивает **весь pipeline** на каждом witness; общие hard restrictions действуют на каждый |
| Несколько обязательных измерений (project И region) | Project ContextRef + typed GrantCondition для region и exact query adapter; не union независимых scopes |
| Owner организации видит все projects | Tenant-wide role, ContextPolicy inherit, membership и ownership сохраняются |
| Все проекты в организации должны иметь отдельное членство | Контекстная membership restriction + paired query adapter; tenant-wide role не отменяет её |
| Панель только на policies/relation | DatabaseSource не подключается; те же tenant/context contracts |
| Пакет со своими projects | ContextDefinition с собственным alias, resource/query adapters, plugin namespaces; host выбирает panel |
| Проекты переехали между tenants | Перенос business data под locks, revoke старых grants, новое явное назначение; cached Allow перепроверяется |
| Tenant deactivated / external source outage | Hard restriction/source error; новые grants не открывают отключённый tenant |

Произвольная рекурсивная hierarchy, shared resources между tenant и cross-tenant delegation требуют собственного
resolver/relationship adapter с явной semantics. Они не возникают от указания `parent_id` в meta.
Для перечисленных CRM требований достаточно scoped RBAC и отношений хоста; внешний граф не обязателен.

## 12. Полный обход цепочек

Эта таблица связывает пользовательский поток с контрактом и проверками. Она включает чтение, записи и отказ.

| # | Цепочка | Обязательная граница | Проверки |
|---|---|---|---|
| C01 | Boot -> folders -> plugins -> sources -> freeze | Неповторяющиеся aliases/keys, immutable configs, scopes описаны до query | V77–V79, V102 |
| C02 | HTTP auth -> panel -> tenant -> route-bound project/client | Не доверять id из URL, проверить membership и owner scope | V86–V88, V95 |
| C03 | Subject -> guard -> inTenant -> decide | Все явные сигналы согласованы, wrapper не меняет модель | V86, V102 |
| C04 | Static caller -> grant на project -> client View | Роль связывается с ContextDefinition, project tenant проверен | V89–V90 |
| C05 | Class role definition -> scoped assignment; optional dynamic action -> direct grant | Definition code-owned; grants/action catalogue tenant-scoped, no static shadow | V91–V92 |
| C06 | Grant -> policy false/true/null/Response -> restrictions | Token/ownership не обходятся, denial Response не truthy | V93–V94 |
| C07 | Auto roles / GrantedToAll / relation / external | RoleContribution со scope/сроком; source error не скрывается | V90, V94, V98 |
| C08 | RoleGrant / direct grant / sync / revoke | Полный ключ scope+origin; sync не удаляет соседний tenant/origin | V96–V97 |
| C09 | Role delete / permission delete / rename | State lock первый, нет сирот/new grant after delete | V97, V101 |
| C10 | Pipes -> final validation -> journal -> root commit -> events | Retry-safe, no external effects, rollback не публикует state | V97, V101 |
| C11 | Cold cache -> DB chunks -> before/after version | Нет смешанных снимков; dynamic catalog в том же fence | V99 |
| C12 | Warm/request cache -> expiry -> revoke | Deadline проверяется каждый раз, incarnation после restore | V99–V100 |
| C13 | Collection open -> exact visibleTo -> count/page | view_any отдельно, tenant AND OR grants AND restrictions | V95 |
| C14 | Record read/update/create -> authorize -> write | Подтвердить связанные foreign ids; TOCTOU protocol хоста | V95, V101 |
| C15 | Filament panel -> managed panel/tenant -> form -> mutation | Actor/target scope на каждом Livewire action, guarded record id | V96 |
| C16 | Search/autocomplete/widget/export/bulk/attach | Такой же exact scope до выдачи/агрегации; no raw builders | V95–V96 |
| C17 | Gate / Blade / attributes / middleware | Ownership/ambiguous binding, отказ не fallback | V93, V102 |
| C18 | Package plugin -> aliases/prefix -> permissions | Namespace применяется к binding и role references, не только catalog | V102 |
| C19 | External mapping -> pages/webhook -> contributions/import | Source authority/origin, revision, atomic snapshot | V98 |
| C20 | Queue/Octane/sync/fiber/CLI | Explicit scope, reset/finally, fresh reauthorization | V103 |
| C21 | explain/trace/doctor/schema/UI capabilities | Trace защищена, fields без secrets, schema не authorization | V104 |
| C22 | Migrations/DDL/PK/collations/storage/reset/release | Portable uniqueness, locks, codec, archived consumers | V99, V101, V105 |

V86–V105 — критерии будущей PHP реализации; bounded reference-model checks — отдельное
[evidence](evidence/design-review.md), они не объявляются успешной проверкой runtime 1.0.

## 13. Явные классы фильтров и два authority modes

```php
final class ActiveProjects implements ContextQueryFilter
{
    public function apply(Builder $query, ContextRuntime $runtime): void
    {
        $query->where('is_active', true);
    }
}
final class SellerProjects implements ContextQueryFilter
{
    public function apply(Builder $query, ContextRuntime $runtime): void
    {
        $user = $runtime->user;
        if (!$user instanceof User || $user->city_id === null) { $query->whereRaw('1 = 0'); return; }
        $query->where('city_id', $user->city_id);
    }
}
```

Actual BaseRole находится в $runtime->role, target User — в user, actor — отдельно. Query не получает profile alias,
field/operator map или dynamic role Model. Services применяются через runtime DI, typed config — через constructor.
Общий ActiveProjects AND Caller SellerProjects; Analyst остаётся independent веткой. Direct grant проходит common,
а не неявный Caller filter. Policy Update блокирует do_not_call и не разрешает неassigned client от true.
ClientPermission права View/Update имеют #[RequiresGrant]. Отдельное #[PolicyOnly] право ViewOwnProfile проверяет
owner_user_id через ClientPolicy и не обращается к assignment store. Обе части используют общую tenant/project
boundary. Полный пример режима — [19 §2](19-oop-and-permission-authority.md#2-для-одного-права--один-authority-mode).
