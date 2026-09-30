# 03 — Словарь и карта «было → стало»

Файл обязателен для исполнителей: новое имя берётся **только** отсюда. Если символа нет в таблицах, он либо переезжает
в свою зону ([04](04-packages-and-layout.md)) без смены короткого имени, либо удаляется по решению из
[02](02-decisions.md).

Принцип: **язык продукта сохраняется** (Panel, PanelProvider, Permission, Role, Source, HasAzGuard, Context,
политики, атрибуты `#[CheckPermission]` и соседи). Имена методов, событий и команд — своя система из нескольких правил ([D57](02-decisions.md#d57)), собранная
из лучших практик Laravel, Spatie, Laratrust, Bouncer и Google Cloud IAM. Новое имя появляется только
там, где старое вводило в заблуждение (`ModelHasScope`, `ContextRole`, `PermissionLayer`, `GrantBuilder`) или где
понятия не было (Schema, Restriction, Storage, Plugin, возможности источников). Совместимости с 0.3 нет: старые имена удаляются, а
не остаются алиасами ([D01](02-decisions.md#d01)).

## 1. Словарь

| Термин | Простыми словами | Точно | Где в коде |
|---|---|---|---|
| **Panel** | Конструктор прав для части приложения: кабинет, админка, API, модуль | Неизменяемое после загрузки определение: субъекты, источники, контексты, хуки, плагины | `Panels\Panel`, `PanelBuilder`, `PanelProvider`, `PanelRegistry` |
| **Default panel** | Панель, которую не нужно указывать в проверке | Панель с `->default()` для модели или единственная панель модели | `Panels\PanelResolver` |
| **Current panel** | Панель группы маршрутов — панель по умолчанию на время запроса | Ставится middleware `azguard.panel` или Filament; шаг 2 правила выбора панели | `Panels\CurrentPanel` |
| **Permission** | То, что можно разрешить | Определение в каталоге панели | `Catalog\PermissionDefinition` |
| **Local name** | Имя права внутри панели | `orders.view` (≥ 2 сегментов) | `PermissionKey::local()` |
| **Full name** | Имя права с панелью | `admin:orders.view` | `Kernel\Identity\PermissionKey` |
| **Pattern** | Выдача сразу многих прав | `orders.*`, `orders.**` — только в выдаче | `Kernel\Identity\PermissionPattern` |
| **Catalog** | Все права панели | Собирается из источников (`ProvidesPermissions`): статичная часть — при загрузке, динамическая — с версией панели | `Contracts\Catalog\PermissionCatalog` |
| **Source (источник)** | Класс, из которого панель берёт права, роли, выдачи или политики | `Source` + нужные возможности: `ProvidesPermissions`, `ProvidesRoles`, `ProvidesGrants`, `ProvidesPolicies`, `StoresGrants`, … | `Contracts\Sources\*`, `Sources\*` |
| **Source factory** | Регистрация источников по имени, как драйверов кэша | `SourceManager` (`Illuminate\Support\Manager`), `AzGuard::sources()->extend()`, `#[AsSource]` | `Sources\SourceManager` |
| **Folder source** | Папка панели как источник: enum, роли, политики, `#[GrantedToAll]` | есть на каждой панели; автопоиск по подпапкам | `Sources\Folder\FolderSource` |
| **Database source** | БД назначений PHP-ролей/enum actions; opt-in dynamic actions, свои модели и поля, хранилище | подключается `DatabaseSource::make()`; писатель панели | `Sources\Database\DatabaseSource` |
| **Writer (писатель)** | Источник, который принимает изменения | `StoresGrants`; не больше одного на панели | `Panel::writer()` |
| **Static role (статичная роль)** | Роль, описанная классом в `Roles/`; права меняются только в коде | `BaseRole`: `permissions()` + атрибуты `#[Role]`, `#[SuperAdmin]`, `#[NotGrantable]`, `#[FormerKeys]` | `Roles\BaseRole`, `Roles\Attributes\*` |
| **Automatic role** | Роль, которую получает каждый, кто подходит под правило | `GrantedAutomatically::appliesTo()` | `Roles\GrantedAutomatically` |
| **Grant (выдача)** | «Анне выдана роль», «Борису выдано право» — в сущности, до даты, кем-то | Действие `grant`/`revoke`, запись о нём и значение «право есть, потому что…» из любого источника | `Kernel\Decision\Grant`; записи — `RoleGrant`, `PermissionGrant` |
| **Role grant** | Выдача роли | Строка выдачи; роль — по ключу | `Storage\Models\RoleGrant` |
| **Permission grant** | Выдача права (или шаблона) без роли | Строка выдачи права | `Storage\Models\PermissionGrant` |
| **Policy** | Метод политики домена: код, который выполняется перед доступом и уточняет выданное | PolicyOnly: sole authority; RequiresGrant: true/null pass, false veto; `#[PolicyFor]`, `#[Decides]` | `Policies\PolicyFor`, `Policies\Decides` |
| **Relation source** | Права из связи моделей приложения | `RelationSource::make(Project::class, via:, role:)` | `Sources\Relation\RelationSource` |
| **Gate source** | Существующая ability Laravel как второй уровень права | `GateSource::make()->map(Perm::X, 'ability')` | `Sources\Gate\GateSource` |
| **Tenant** | Организация — граница данных | TenantRef; global не означает все организации | `Kernel\Identity\TenantRef`, `Scopes\TenantPolicy` |
| **Access scope** | Тенант и проект вместе | Immutable `(TenantRef, AssignmentScopeRef)`; не Eloquent scope | `Kernel\Identity\AccessScope` |
| **Assignment scope definition** | Класс типа проекта в папке панели | AssignmentScopeDefinition SPI: alias/model/resolve; Eloquent query/tenantOf; роль ссылается через scopes() | `Contracts\Scopes\AssignmentScopeDefinition`, `Scopes\BaseAssignmentScope` |
| **Role contribution** | Роль, выданная источником | Scope + role + source/origin + expiresAt; superadmin не требует permissions | `Kernel\Decision\RoleContribution` |
| **Grant condition** | Условие одной выдачи | AND внутри grant до OR между grants | `Contracts\Authorization\GrantCondition` |
| **Origin** | Владелец сохранённого вклада | manual или partition importer; sync/revoke не удаляет чужой origin | `Change::$origin`, grant record |
| **Assignment scope** | Сущность внутри тенанта: проект, магазин | `global` внутри выбранного tenant или `{type}:{id}` | `Kernel\Identity\AssignmentScopeRef` |
| **Resource** | Объект, о котором спрашивают (заказ); передаётся политикам | Модель из `on:`, не являющаяся контекстом | `AccessRequest::$resource` |
| **Assignment scope policy** | Как панель относится к сущностям | `inherit` / `isolated` / `required` / `none` (+ членство) | `Scopes\AssignmentScopePolicy` |
| **Subject** | У кого права | Любая модель с трейтом: пользователь, проект, команда | `Kernel\Identity\SubjectRef`, `Concerns\HasAzGuard` |
| **Actor** | Кто меняет права (если известен) | Пользователь или system с причиной | `Kernel\Identity\ActorRef` |
| **Super admin** | Тот, кому в панели (или в сущности) разрешено всё; ограничения действуют | Держатель scoped роли класса #[SuperAdmin]; policy/common boundaries остаются | `Roles\BaseRole`, `Storage\Models\RoleGrant` |
| **Prefix** | Добавка к именам прав панели, указывающая на панель | по умолчанию id панели: `admin.orders.view`; `->resourcePrefix('x')`, `->resourcePrefix(false)` | `PanelBuilder::resourcePrefix()` |
| **Resource definition** | Enum действий над объектом с metadata; связанные policy/query классы | `Permissions/{Group}` (enum) + `Policies/{Group}` + `Abilities/{Group}` | `Permissions\Resource`, `Policies\PolicyFor` |
| **Panel folder** | Папка панели: провайдер, роли, домены, свои источники, ограничения, pipes, модели | каталог класса провайдера; её читает `FolderSource` | `Panels\PanelProvider`, `Sources\Folder\PanelDiscovery` |
| **Shared folder** | `app/Guards/Shared/`: общее для нескольких панелей | не панель; роли, источники, плагины | — |
| **Static / dynamic permission** | Право из enum (в коде) / право, созданное в БД во время работы | динамические — только при `DatabaseSource::make()->dynamicPermissions()` | `Storage\Models\Permission` |
| **Restriction** | Правило, которое умеет только запрещать | Шаг «ограничения» | `Contracts\Authorization\Restriction` |
| **Hook** | Точка вмешательства в проверку | `before`, `after` — как `Gate::before/after` | `PanelBuilder::before()`, `after()` |
| **Pipe** | Шаг пайплайна изменений: дополнить или отменить изменение | `handle(Change $change, Closure $next)`, как в `Illuminate\Pipeline` | `PanelBuilder::changing()` |
| **Attribute** | PHP-атрибут: удобная запись привязки или признака | существительное, глагол, признак или `As…` (D57) | `Permissions\*`, `Policies\*`, `Roles\Attributes\*`, `Attributes\*` |
| **Access pipeline** | Путь проверки | Панель → подготовка → before → суперадмин → выдачи → политика → ограничения → after | `Authorization\Pipeline\AccessPipeline` |
| **Change pipeline** | Путь изменения | Проверка данных → pipes `changing` → запись писателем → события | `Changes\ChangePipeline` |
| **Change** | Описание изменения | Неизменяемое значение | `Changes\Change` |
| **Schema** | Описание панели для интерфейсов | Права, роли, поля, сущности и как их заполнять | `Schema\PanelSchema` |
| **Plugin** | Готовый набор дополнений для любой панели: источники, ограничения, pipes, поля | `id()`, `register(PanelBuilder, PluginContext)`, `boot(Panel, PluginContext)` | `Contracts\Plugins\Plugin` |
| **Storage** | Где лежат данные `DatabaseSource` панели | Подключение + префикс + тип ключей + модели | `Storage\Storage` |
| **Decision** | Ответ | `Effect` + причина + `StateToken` | `Kernel\Decision\Decision` |
| **State token** | Версия прав панели | `{storageId, panel, incarnation, version, generation, fingerprint}` | `Kernel\Decision\StateToken` |
| **Ability** | Строка Laravel Gate | Для AzGuard — локальное или полное имя права | только `Laravel\Gate\GateBridge` |
| **Visibility** | Какие записи окончательно доступны | Exact query plan; unsupported -> error | `Authorization\Visibility`, `Scopes\ContextAware` |

Слова, которые не используются в публичных именах: **scope** (кроме AccessScope и Eloquent), **ability** (кроме Gate и DTO
`…Abilities`), **guard** (кроме auth guard, суффикса `…GuardPanelProvider` и `guardPanel` в Filament-пакете), **rank**,
**realm**, **assign** (вместо него `grant`), **mechanic** (вместо него source).

## 2. Правила именования

Колонка «Экосистема» отмечает общие инженерные правила, одинаковые с Vaulter (D43).

| Что | Правило | Пример | Экосистема |
|---|---|---|---|
| Проверка | вопрос, без суффиксов | `hasPermission()`, `hasRole()`, `hasAnyRole()`, `isSuperAdmin()` | — |
| Изменение | одна пара `grant`/`revoke` + `sync` для ролей и прав | `grantRole()`, `revokeRole()`, `grantPermission()`, `syncRoles()` | — |
| Запись о выдаче | так же, как действие | `RoleGrant`, `PermissionGrant` | — |
| Список | существительное | `roleNames()`, `permissionNames()`, `permissionSet()` | — |
| Сущность, срок, поля | именованные аргументы `on:`, `until:`, `fields:` | `grantRole('editor', on: $project, until: $date)` | — |
| «От чьего имени» | `AzGuard::actingAs($actor, fn)`; строка — system с причиной | `AzGuard::actingAs('import: crm', …)` | ✓ (форма отличается: актор необязателен) |
| Результат | существительное без суффикса | `DecisionSet`, `ChangeResult` | ✓ |
| Событие | прошедшее время `<Noun><Verb>ed` | `RoleGranted`, `PermissionRevoked` | ✓ |
| Тип события | `noun.verb_past` | `role.granted` | ✓ |
| Исключение | `<Condition>Exception`, код `snake_case` | `PanelNotResolvedException` / `panel_not_resolved` | ✓ |
| Контракт и реализация | одно короткое имя в разных namespace | `Contracts\Changes\RoleCatalog` / `Changes\RoleCatalog` | ✓ |
| Возможность источника | `Provides<Что>`, `Stores<Что>`, `<Глагол>s<Что>` | `ProvidesGrants`, `StoresGrants`, `FiltersQueries` | — |
| Атрибут: что это | существительное | `#[Resource]`, `#[Role]`, `#[Describe]` | — |
| Атрибут: что делает | глагол | `#[Decides]`, `#[CheckPermission]`, `#[SkipPermissionCheck]` | — |
| Атрибут: признак | прилагательное или причастие | `#[SuperAdmin]`, `#[RequiresGrant]`, `#[GrantedToAll]`, `#[NotGrantable]` | — |
| Атрибут: регистрация по имени | `As<Thing>` (как в Laravel/Symfony) | `#[AsSource('ldap')]` | ✓ |
| Класс по роду | суффикс рода | `OrderPermission`, `OrderPolicy`, `ManagerRole`, `LdapSource`, `AuditTrailPlugin`, `AccountLockedRestriction` | — |
| Папка | род классов во множественном числе; группа внутри соответствующего корня | `Roles/`, `Sources/`, `Permissions/Orders/` | — |
| Ключ плагина, ограничения | `vendor/name` | `azguard/audit`, `acme/blog` | ✓ |
| id источника | короткое имя; у связей — `relation:<тип>` | `folder`, `database`, `relation:project`, `ldap` | — |
| id панели | `^[a-z0-9][a-z0-9-]{0,63}$` | `admin`, `cabinet`, `seller` | ✓ (та же грамматика ключей реестров) |
| Имя права | локальное `resource.action` (≥ 2 сегментов), полное `panel:local` | `orders.view_any`, `admin:orders.view_any` | — |
| Имя роли | локальное в kebab-case, полное `panel:key` | `support`, `admin:support` | — |
| Команда | `azguard:<area>:<verb>` | `azguard:roles:grant` | ✓ |
| Генератор | `azguard:make:<thing>` | `azguard:make:panel` | ✓ |
| Middleware alias | `azguard.<noun\|verb>` | `azguard.panel`, `azguard.can` | — |
| Конфиг | файл на пакет, `snake_case` | `config/azguard.php` | ✓ |
| Таблица | `<prefix><plural_noun>` | `azg_role_grants` | ✓ |
| Getter значений | существительное без `get` | `$panel->id()`, `->label()` | — |

Словарь аргументов: `permission` (имя, enum), `role` (имя, enum, класс), `on` (сущность или ресурс), `panel` (id —
только когда нужно указать явно), `until` (срок), `fields` (свои поля), `reason`.

## 3. Пакеты и namespace

| Было | Стало |
|---|---|
| `axioma-studio/azguard-core` (`AzGuard\`) | `axiomasoft/azguard` (`AzGuard\`) |
| `axioma-studio/azguard-context` (`AzGuard\Context\`) | влит в ядро: `AzGuard\Scopes\` |
| `axioma-studio/azguard-filament` (`AzGuard\Filament\`) | `axiomasoft/azguard-filament` |

## 4. Классы: core

| Было | Стало | Примечание |
|---|---|---|
| `AzGuardServiceProvider`, `AzGuardManager`, `Facades\AzGuard` | те же | менеджер — корень фасада без состояния |
| `Contracts\AzGuardManagerInterface` | — | `Contracts\PanelAccess` + фасад |
| `Guard\Authorizer` | `Authorization\Authorizer` (internal) + `Laravel\Gate\GateBridge` | |
| `Registry\Resolver\EffectivePermissionResolver` | `Authorization\Pipeline\AccessPipeline` | |
| `Contracts\PermissionResolverInterface` | — | расширение — источники и хуки |
| `Registry\Resolver\PermissionCache` | `Authorization\Cache\PermissionSetCache` | без эпох |
| `Registry\Resolver\PermissionStateRevision` | `Storage\PanelState` + `Storage\Storage::mutate()` | версия на панель |
| `Registry\Resolver\SubjectIdentity` | `Kernel\Identity\SubjectRef` + `IdentityCodec` | |
| `Registry\Values\PermissionSet` | `Kernel\Permissions\PermissionSet` | |
| `Registry\Contracts\GrantSource` | `Contracts\Sources\Source` + `ProvidesGrants` | источник реализует только нужные возможности |
| `Registry\Contracts\GrantPriority` | — | объединение не зависит от порядка |
| `Registry\Sources\ClassRoleGrantSource` | `Sources\Folder\FolderSource` | роли из папки, автоматические роли, `#[GrantedToAll]` |
| `Registry\Sources\DatabaseRoleGrantSource`, `DirectGrantSource` | `Sources\Database\DatabaseSource` | вся работа с БД |
| — | `Sources\Relation\RelationSource`, `Sources\Gate\GateSource` | связи и Gate как источники |
| — | `Sources\SourceManager` | фабрика источников (`Illuminate\Support\Manager`) |
| — | `Contracts\Sources\Source`, `Provides*`, `StoresGrants`, `FiltersQueries`, `DescribesSchema`, `ChecksHealth` | D52 |
| `Registry\Contracts\PermissionCatalog`, `PermissionCatalogBuilder` | `Contracts\Catalog\PermissionCatalog`, `Contracts\Sources\ProvidesPermissions` | построитель каталога — возможность источника |
| `Registry\Contracts\PermissionDefinition`, `PermissionMeta`, `Registry\Definitions\*` | `Catalog\PermissionDefinition` | |
| `Registry\Builders\CompositePermissionCatalog` | `Catalog\PanelCatalog` | |
| `Registry\Builders\EnumPermissionCatalogBuilder` | `Sources\Folder\FolderSource` | enum из папки панели и из `->permissions([...])` |
| `Registry\Builders\PolicyAbilityCatalogBuilder` | `Sources\Folder\FolderSource` (`ProvidesPolicies`) | привязки политик к правам |
| `Registry\Matching\*`, `Contracts\PermissionMatcher` | `Kernel\Grammar\PatternMatcher` | одна грамматика |
| `Registry\Validation\*`, `Contracts\RolePermissionValidator` | — | проверка — шаг пайплайна изменений |
| `Permissions\PermissionKey` (константы, `WILDCARD`) | `Kernel\Identity\PermissionKey` (значение) | звёздочки нет |
| `Permissions\PermissionGrammar` | `Kernel\Grammar\PermissionGrammar` | |
| `Permissions\PermissionName`, `CatalogKeyMatcher` | `Panels\PanelResolver`, `Catalog\PanelCatalog` | одно правило выбора панели |
| `Permissions\InteractsWithPanel` | `Concerns\BelongsToPanels` | enum знает свои панели |
| `Contracts\Permission` | — | enum прав — обычный string-backed enum: значение — локальное имя; домен — по папке или `#[Resource]` |
| `Panels\Panel` | `Panels\PanelBuilder` (описание) + `Panels\Panel` (readonly) | |
| `Panel::scopedByPanelId()` | `PanelBuilder::resourcePrefix(true\|string\|false)` | по умолчанию включён (id панели), как сейчас; можно свой или выключить |
| `Panel::permissionEnums([...])`, `roleClasses([...])` | `PanelBuilder::permissions([...])`, `roles([...])` | дополнительно к найденным в папке; permissions принимает enums и источники по D73 |
| `Panel::path()`, `namespace()`, `basePath()` | папка и namespace провайдера (как сейчас определяются автоматически) | `->discover($path)` — другая папка |
| `Panels\PanelProvider` | `Panels\PanelProvider` | метод `panel(PanelBuilder)` |
| `Panels\PanelResolver`, `Runtime\CurrentPanelState` | `Panels\PanelResolver` (правило выбора), `Panels\CurrentPanel` | |
| `Contracts\RoleInterface`, `Roles\BaseRole` | `Roles\BaseRole` | `getName()` → `key()` / `#[Role]`; `getLevel()` → `level()` / `#[Role(level:)]` |
| — | `Roles\Attributes\Role`, `SuperAdmin`, `NotGrantable`, `FormerKeys` | атрибуты роли (D14) |
| `Roles\SuperAdminRole` | `Roles\SuperAdminRole` | готовая роль из кода с `#[SuperAdmin]`; подключается явно |
| — | `Roles\GrantedAutomatically` | автоматическая роль (`appliesTo()`) |
| `Roles\RolePermissionSynchronizer`, `…Selection`, `…SyncResult` | `Changes\RoleCatalog::syncPermissions()`, `ChangeResult` | |
| `Roles\RolePermissionSyncConflictException` | `Exceptions\StaleSelectionException` | |
| `Support\RoleIdentity` | `Kernel\Identity\RoleKey` | |
| `Support\RoleSyncPlanner`, `Commands\SyncRolesCommand` | — | роли из кода не копируются в БД |
| `Grants\GrantBuilder` | — | `grantPermission()` |
| `Models\Role`, `Models\RolePermission` | `Storage\Models\Role`, `RolePermission` | только роли из БД |
| `Models\DirectGrant` | `Storage\Models\PermissionGrant` | |
| `Models\ModelHasScope` (+ `model_has_roles`) | `Storage\Models\RoleGrant` | |
| `Concerns\HasAzGuard`, `HasRoles`, `HasPermissions`, `HasDirectGrants` | `Concerns\HasAzGuard` | один трейт: проверки и изменения |
| `Concerns\HasScopedRoles`, `ResolvesRole` | — | `on:` в методах трейта; видимость — `ContextAware` |
| `Concerns\RevisionedPermissionModelWrites` | `Storage\Concerns\GuardsDirectWrites` | |
| `Contracts\AzGuardUser`, `HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles` | `Contracts\AzGuardSubject` | |
| `Contracts\PermissionLayer` | `Contracts\Authorization\Restriction` | |
| `Contracts\ContextGuard`, `ContextGrantBuilder`, `ContextGrantBuilderFactory`, `PermissionContext` | — | сущность — аргумент `on:` |
| `Contracts\ScopeInterface` | — | `Authorization\Visibility` |
| `Contracts\AbilitiesResolver`, `Abilities\AbilitiesDto` | `Abilities\AbilitiesDto` (остаётся) | DTO домена для фронтенда; внутри — `SubjectAccess::abilities()` |
| `Attributes\GateAbility` | `Policies\Decides` | явная привязка метода к праву; обязателен на package policy method; папка не заменяет атрибут |
| — | `Permissions\Resource` | подпись и модель домена на enum прав (D56) |
| `Attributes\GuardPolicy(model:)` | `Policies\PolicyFor(Enum::class)` + `Permissions\Resource(model:)` | `PolicyFor` — для политики вне однозначного pairing D56; модель — у домена |
| `Attributes\CheckPermission` | `Attributes\CheckPermission` (остаётся) | `arguments` → `on:`; наследник Laravel `#[Middleware]` — применяет роутер |
| `Attributes\SkipGuardCheck` | `Attributes\SkipPermissionCheck` | |
| `Attributes\RoleOnly` | `Permissions\RequiresGrant` | право проверяется только выдачами, без политики |
| `az-guard.require_permission_attributes` | `PanelBuilder::requireRouteChecks()` | строгий режим — на панели |
| — | `Permissions\Describe` | подпись, группа, описание права |
| — | `Permissions\GrantedToAll` | право у каждого субъекта панели |
| — | `Attributes\AsSource` | регистрация источника по имени |
| `Policies\AuthorizesPermission` | — | recursive grants fallback удалён; mode dispatcher D83 |
| `Guard\PolicyDiscovery` | `Sources\Folder\PanelDiscovery` | часть `FolderSource`; `->discover(path)` добавляет папку; результат в кэше каталога |
| `Auth\PolicyAttributeRegistrar`, `Auth\DirectGrantPolicy`, `Auth\BladeHelper` | — | политики вызываются внутри пайплайна; `Gate::define` не нужен |
| `Guard\AzGuardDiagnostics` | `Diagnostics\Doctor` + `Contracts\Diagnostics\DoctorCheck` | |
| `Http\Middleware\SetCurrentPanel` | `Laravel\Http\Middleware\EnterPanel` (`azguard.panel`) | вход в панель, не только маршрутизация |
| `Http\Middleware\CheckAccess` (`#[CheckPermission]`) | `Laravel\Http\Middleware\CheckPermission` (`azguard.can`) | роутер Laravel вешает его сам из атрибута; строгий режим проверяет `azguard.panel` |
| `Http\Middleware\PanelCheckAccess` | `Laravel\Http\Middleware\CheckPermission` (`azguard.can`) | |
| `Http\Middleware\CheckDirectGrant`, `LoadAzGuardRoles` | — | |
| `Configuration\Config` | `Configuration\AzGuardConfig` | без нормализатора |
| `Database\Schema\MorphColumns` | `Storage\Schema\HostKeyColumns` | |
| `Database\Schema\NullSafeUniqueIndex`, `AssignmentDeduplicator` | — | в идентичности нет NULL |
| `Runtime\RequestState`, `ScopedRoleCache` | `Internal\RequestMemo` | |
| `Scaffold\GuardScaffoldGenerator` | `Laravel\Console\Scaffold\*` | |
| `Testing\AzGuardFake`, `Testing\FakeGrantSource` | `Testing\AzGuardFake`, `Testing\FakeSource` | новые ассерты (§11) |
| `Testing\FakeAzGuardUser`, `Testing\Recorded` | `Testing\FakeSubject`, `RecordedCheck`/`RecordedChange` | |
| — | `Schema\PanelSchema`, `PermissionSchema`, `RoleSchema`, `FieldSchema` | D54 |
| — | `PanelBuilder::before()`, `after()`, `changing()` | D55: замыкания и invokable-классы, pipes — как в Laravel; своих интерфейсов хуков нет |
| — | `Contracts\Plugins\Plugin`, `BasePlugin`, `DependsOnPlugins`, `PrefixesKeys` | D47 |

## 5. Классы: бывший `azguard-context`

| Было | Стало |
|---|---|
| `AuthorizationContext` | `Kernel\Identity\AssignmentScopeRef` (без `panelId`) |
| `AuthorizationContextManager` | `Scopes\CurrentContext` (scoped) |
| `ContextGuard`, `ContextPermissionLayer`, `ContextGrantBuilder(Factory)`, `AssignmentScopeNotSetException` | — (сущность — аргумент `on:`, выдачи — трейт, применение — пайплайн) |
| `Contracts\MergeStrategy`, `Strategies\*` | `Scopes\AssignmentScopePolicy` (`inherit`/`isolated`/`required`/`none`) |
| `Contracts\ResolvesContext` | `Contracts\Scopes\AssignmentScopeResolver` |
| `Middleware\SetAuthorizationContext` | часть `azguard.panel` (резолверы панели) |
| `Models\ContextRole` | `Storage\Models\PermissionGrant` с сущностью |
| `Events\ContextGrantGiven/Revoked` | `Events\PermissionGranted/PermissionRevoked` (с `context`) |
| `Commands\*` | `azguard:permissions:grant\|revoke --on=` |

## 6. Классы: filament

| Было | Стало |
|---|---|
| `AzGuardPlugin::forPanel($id)` | `AzGuardPlugin::guardPanel(string $id)` + `manages(array $panels)` |
| source(database/enum/policy) | definitions(FilamentDefinitions::Enums/Resources); authority mode отдельно в definition |
| `Resources\DirectGrantResource` | `Resources\PermissionGrantResource` |
| — | `Resources\RoleGrantResource` |
| `Resources\RoleResource` | тот же, по схеме панели, без `class_name` |
| `Permissions\ResourceGate` | `Authorization\FilamentGate` |
| `Permissions\FilamentPermissionCatalogBuilder` | `Sources\FilamentSource` (права ресурсов как источник) |
| `Permissions\PageWidgetAccessEvaluator` | `Authorization\PageAccess` (закрыто без права) |
| `Permissions\PolicyGenerator` | — |
| `Concerns\HasAzGuardPage/Widget` | `Concerns\AuthorizesPage/Widget` |
| — | `Contracts\FilamentFormExtension` |
| — | `Pages\PanelsPage`, `Pages\DoctorPage` |
| `guard:filament:generate` | `azguard:filament:generate` |

## 7. Методы

| Было | Стало |
|---|---|
| `AzGuard::registerPanel()`, `getPanels()`, `panel()` | `azguard.panels.providers` / `AzGuard::registerPanel(Provider::class)`; `AzGuard::panels()`; `AzGuard::panel($id)` (→ `PanelAccess`) |
| `AzGuard::currentPanel()`, `setCurrentPanel()` | `AzGuard::currentPanel()`; установка — middleware `azguard.panel` |
| `AzGuard::permission($panel, $perm)`, `tryPermission()`, `panelIdForPermission()` | `PermissionKey::of($panel, $local)`, `PermissionKey::parse('admin:orders.view')`; выбор панели — `PanelResolver` |
| `AzGuard::isSuperAdmin($user, $panel)` | `$user->isSuperAdmin()` / `$user->guard($p)->isSuperAdmin()` |
| `AzGuard::abilitiesFor($user, $panel, $keys)` | `$user->guard($p)->abilities($keys)` |
| `AzGuard::registerGrantSource()`, `registerCatalogBuilder()` | на панели: `->permissions([...])`; по имени — `AzGuard::sources()->extend()` или `#[AsSource]`; или плагин |
| `AzGuard::forUser($u)->on($p)->ttl()->grant($k)` | `$u->guard($p)->grantPermission($k, until:)` |
| `…->revoke($k)`, `->revokeAll()`, `->grants()` | `revokePermission()`, `syncPermissions([])`, `->guard($p)->permissionGrants()` |
| `…->inContext($t, $id)` | `on: $model` или `on: AssignmentScopeRef::of($t, $id)` |
| `$user->hasPermission($perm, $panel, $context)` | `$user->hasPermission($perm, on: $c)`; панель — `guard()`, префикс или полное имя |
| `$user->hasPermissionIn($type, $id, $perm, $panel)` | `$user->hasPermission($perm, on: AssignmentScopeRef::of($type, $id))` |
| `$user->checkPermission(...)`, `flushPermissions()`, `hasContextGuard()` | — |
| `$user->permissionSet($panel)`, `permissions($panel)` | `$user->permissionSet(on:)`, `$user->azguard()->permissions()` |
| `$user->isSuperAdmin($panel)` | `$user->isSuperAdmin()` (панель по правилу выбора) |
| `$user->hasRole($role)` | `$user->hasRole($role, on:)` |
| `$user->assignRole/removeRole/syncRoles(...)` | `grantRole/revokeRole/syncRoles` + `on:`, `until:`, `fields:` |
| `$user->assignScopedRole($role, $entity, $panel)` | `$user->guard($panel)->grantRole($role, on: $entity)` |
| `$user->removeScopedRole(...)`, `removeScopedRoleEverywhere(...)` | `revokeRole($role, on: $entity)`, `revokeRole($role, on: AnyAssignmentScope::all())` |
| `$user->hasScopedRole/hasScopedPermission(...)` | `hasRole/hasPermission(..., on: $entity)` |
| `$user->grant/revoke($perm, $panel)`, `grants()`, `hasGrant()` | `grantPermission/revokePermission`, `->permissionGrants()`; `hasGrant` удаляется |
| `$user->roles()`, `scopes()`, `directGrants()`, `getRoleNames()` | `->guard($p)->roleGrants()`, `roleNames(on:)` |

## 8. Конфигурация

Старый конфиг не читается ([D01](02-decisions.md#d01)); таблица — для ориентира исполнителю при удалении.

| Было (`az-guard.*`) | Стало (`azguard.*`) |
|---|---|
| файлы `az-guard.php`, `az-guard-context.php`, `az-guard-filament.php` | `azguard.php`, `azguard-filament.php` |
| `panels` | `panels.providers` |
| `default_panel` | `->default()` на панели |
| `strict_panels`, `require_permission_attributes`, `scope.on_missing_panel`, `middleware.*` | — |
| `manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator` | — (расширение — источники и хуки) |
| `models.*` | `defaults.models.*` (панель переопределяет `DatabaseSource::make()->models()`) |
| `table_names.*` | `storages.default.table_prefix` |
| `column_names.morph_type` | `ids.host_keys` |
| `cache.store`, `expiration_time`, `generation` | `defaults.cache.store`, `.ttl`, `.generation`, `defaults.consistency.*` |
| `grant_sources`, `features.direct_grants` | источники на панели: `->permissions([...])`; параметры именованных — `sources.*` |
| `fail_on_source_exception`, `features.wildcard_permission`, `features.teams`, `teams.*`, `features.validate_role_permissions` | — |
| `features.audit_log` | `defaults.trace_decisions` |
| `prune_expired_daily` | `schedule.prune_expired` |
| `az-guard-context.merge_strategy` | `AssignmentScopePolicy` на панели |
| `az-guard-context.resolvers` | `->scopeResolvers()` / `defaults.contexts.resolvers` |
| `az-guard-filament.panel`, `user_label_column`, `super_admin` | `AzGuardPlugin::guardPanel()`, директория субъектов панели, роль с `#[SuperAdmin]` |

## 9. Таблицы и колонки (общее хранилище, префикс `azg_`)

| Было | Стало |
|---|---|
| `roles(name, class_name, level)` | —; definition PHP BaseRole, назначения azg_role_grants |
| `az_guard_role_permissions(role_id, permission_key, panel_id)` | —; BaseRole.permissions() в PHP |
| `model_has_roles(role_id, model_*)` | `azg_role_grants(panel, role, …, context_key = 'global')` |
| `model_has_scopes(model_*, scope_entity_*, scope_class, role_id, panel_id)` | `azg_role_grants(… context_key = '{type}:{id}')` |
| `az_direct_grants(grantable_*, permission_key, panel_id, expires_at)` | `azg_permission_grants(panel, subject_*, permission, context_key = 'global', …)` |
| `az_guard_context_roles(model_*, context_*, panel_id, permission_key, expires_at)` | `azg_permission_grants(… context_key = '{type}:{id}')` |
| `az_guard_permission_state(id, revision)` | `azg_panel_state(panel, version)` + `azg_storage_state` |
| — | `azg_audit_log` (плагин `azguard/audit`) |

## 10. Команды, middleware, Blade, события, исключения

| Было | Стало |
|---|---|
| `guard:install`, `guard:doctor`, `guard:catalog:validate` | `azguard:install`, `azguard:doctor` |
| `guard:catalog`, `guard:list-permissions` | `azguard:catalog:list` |
| — | `azguard:panels:list`, `azguard:catalog:cache\|clear`, `azguard:storage:migration` |
| `guard:sync-roles` | — (роли из кода не синхронизируются); смена ключа — `azguard:roles:rename-key` |
| `guard:role-permissions`, `guard:role assign\|detach` | `azguard:roles:permissions`, `azguard:roles:grant\|revoke` |
| — | `azguard:roles:create\|delete\|list` |
| `guard:list-scoped-roles` | `azguard:grants:list` |
| `guard:grant`, `guard:revoke-grant`, `guard:grants`, `guard:context:grant\|revoke` | `azguard:permissions:grant\|revoke [--on=]`, `azguard:grants:list` |
| `guard:prune-grants` | `azguard:grants:prune` |
| `guard:cache-reset` | `azguard:state:reset {panel}` |
| `guard:super-admin` | `azguard:roles:grant {subject} superadmin` (роль с признаком суперадмина) |
| `guard:explain`, `guard:abilities` | `azguard:explain`, `azguard:permissions:show` |
| `make:guard-panel`, `make:guard-domain`, `make:guard-permission`, `make:guard-role`, `make:guard-policy` | `azguard:make:panel`, `azguard:make:permission` (enum, опционально --policy/--abilities), `azguard:make:role`, `azguard:make:policy` — та же структура папок |
| `make:guard-abilities` | `azguard:make:permission --abilities` |
| — | `azguard:make:plugin`, `azguard:make:source`, `azguard:make:restriction`, `azguard:make:pipe`, `azguard:make:models {panel}` |
| `azguard.panel` | `azguard.panel` (вход в панель) |
| `azguard.check` | `azguard.can` (из `#[CheckPermission]`, вешает роутер) |
| `azguard.panel_check`, `azguard.grant`, `azguard.roles`, `check.access` | `azguard.can` |
| `azguard.context` | часть `azguard.panel` |
| `@azcan`, `@elseazcan`, `@unlessazcan`, `@azrole`, `@azdirect` | `@can`/`@cannot` |
| `GrantGiven`, `GrantRevoked` | `PermissionGranted`, `PermissionRevoked` |
| `RoleAttached`, `RoleDetached` | `RoleGranted`, `RoleRevoked` |
| `AccessDecision` | `AccessDecided` (событие) + `Explanation` (результат `explain`) |
| — | `RoleGranted`, `RoleRevoked`, `RoleGrantUpdated`, `GrantExpired`, `PanelStateTouched` |
| `PanelNotSetException`, `PanelNotFoundException`, `PanelIdTooLongException` | `PanelNotResolvedException`, `UnknownPanelException`, `InvalidPanelIdException`, `AmbiguousPanelException` |
| `InvalidPermissionSyntaxException` | `InvalidPermissionKeyException` |
| `InvalidRoleClassException`, `InvalidRoleIdentityException` | `UnknownRoleException`, `InvalidRoleKeyException` |
| `InvalidMorphTypeException`, `InvalidCacheConfigException`, `InvalidModelConfigException` | `InvalidConfigurationException` (коды различают) |
| `MissingPermissionAttributeException`, `ContextPackageNotInstalledException`, `IdentityIndexException` | — |

## 11. Тестовые ассерты

| Было | Стало |
|---|---|
| `AzGuardFake::assertGranted($user, $key)` | `assertPermissionGranted($subject, $permission, on:)` |
| `AzGuardFake::assertDenied($user, $key)` (проверял **отзыв**) | `assertPermissionRevoked($subject, $permission, on:)` |
| — | `assertRoleGranted`, `assertRoleRevoked`, `assertDecided($subject, $permission, Effect)` |
| `assertChecked($key)` | `assertChecked($permission)` |

## 12. Изменения относительно прошлых проходов досье

Для тех, кто читал третий проход: эти имена были только в досье, в коде их нет.

| Третий проход | Четвёртый проход | Почему |
|---|---|---|
| «механика» | **источник** (`Source`) | владелец описал панель как конструктор из классов-источников |
| `->grantToAll([...])` | `#[GrantedToAll]` на кейсе enum | право «всем» — свойство права; лежит рядом с ним в папке |
| `->database(roles:, permissionGrants:, dynamicPermissions:)` | `DatabaseSource::make()`, `->rolesOnly()`, `->dynamicPermissions()` | вся работа с БД — в одном классе; нет отрицательных флагов |
| `->relation(...)`, `->gates([...])` | `RelationSource::make(...)`, `GateSource::make()->map(...)` | такие же источники, как свои |
| `->storage()`, `->models()`, `->decisionFields()` на панели | те же методы у `DatabaseSource` | это настройки таблиц, а таблицы есть только у БД |
| `->catalogBuilders([...])` | источник с `ProvidesPermissions` | один механизм вместо двух |
| встроенные плагины `azguard/code`, `azguard/policies`, `azguard/database`, `azguard/relations` | встроенные источники `FolderSource`, `DatabaseSource`, `RelationSource`, `GateSource` | плагин — набор для панели, источник — откуда права; это разные вещи |
| `BeforeHook`, `AfterHook`, `ChangingHook`, `ChangedHook` | `before()`/`after()` как у Gate; pipes `changing` как у Pipeline; Laravel-события | механизмы Laravel вместо своих (D58) |
| `->restrict()`, `->changed()` | `->restrictions([...])`; слушатели событий | массивы; события Laravel |
| `->plugin()`, `->withoutPlugin()` | `->plugins([...])`, `->withoutPlugins([...])` | списки — массивами (D57) |
| `GrantSource::contributions()`, `Contribution` | `ProvidesGrants::grants()`, `Grant` | одно слово «выдача» для действия, записи и значения |
| `BaseRole::superAdmin()`, `grantable()` методами | `#[SuperAdmin]`, `#[NotGrantable]` (методы остаются) | атрибуты рядом с классом, как у сегодняшних политик |
| `#[PolicyFor(Enum, model:)]` | `#[Resource(model:)]` на enum; `#[PolicyFor(Enum)]` — для явной привязки по FQCN | модель — свойство домена |
| `#[CheckPermission]` выполняет `azguard.panel` | `#[CheckPermission]` — наследник Laravel `#[Middleware]` | роутер Laravel применяет его сам |
| `Discovery\PanelDiscovery` | `Sources\Folder\PanelDiscovery` | автопоиск — часть `FolderSource` |
| `azguard:make:hook` | `azguard:make:pipe` | хуков-классов изменений больше нет |


## 13. Дополнения пятого прохода (D59–D69)

Новые публичные классы, а не aliases 0.3: TenantRef, AccessScope, RoleContribution, TenantPolicy,
AssignmentScopeDefinition, BaseAssignmentScope, ModelAssignmentScopeDefinition, ModelTenantDefinition, ResourceScopeResolver,
ProvidesAccessScope, TenantMembership, TenantDirectory, TenantResolver, GrantCondition, FiltersAccessQueries,
AccessPredicate, ProvidesRoleGrants, GrantManager, GrantFilter, GrantPage, GrantRecord.
TenantOption/AssignmentScopeOption/SubjectOption — typed значения display lookup; AssignmentScopeTypeSchema/TenantTypeSchema —
definitions для редактора. Request/Model адаптеры находятся в Contracts\Scopes, чистые значения — Kernel.

Методы: inTenant(), SubjectAccess::fromOrigin(), BaseRole::scopes()/scopeRequired(),
PanelBuilder::tenants()/tenantResolvers()/resourceScopes()/grantConditions(), DecisionSet::states().
Папки: Scopes/ (типы областей), Resolvers/ (tenant/resource adapters), Queries/{Group}/ (paired visibility).

Исключения: ConflictingPanelException, TenantRequiredException, TenantMismatchException,
AssignmentScopeRequiredException, ResourceScopeMissingException, VisibilityNotSupportedException,
InvalidSourceContributionException, RecursionDetectedException, ConsistencyException — стабильный snake_case code.
Коды отказов перечислены в [05](05-php-api.md); configuration/change exceptions direct API не скрываются.


D70/D72: Permissions/Users = действия над User; for(model: User::class) = User как обладатель прав.
Permissions/Projects = действия над Project; Scopes/ProjectScope = область назначений; Project как subject
в панели features = обладатель tariff permissions. Эти роли business entity не подразумеваются друг из друга.
Root Sources/ — механика, Permissions/Sources/ — допустимая группа действий. Автопоиск их не смешивает.
Policies/<Group>, Queries/<Group>, Abilities/<Group> — параллельные корни; контейнера Resources нет.

D71: Domain/#[Domain] заменены metadata #[Resource]; генератор D72 — make:permission [--policy] [--abilities].
PanelBuilder::subjects заменён PanelBuilder::for; SubjectRef и directories не переименовываются.
Resource definition (enum с metadata) отличается от Resource instance (Model в AccessRequest)
и Filament Resource (UI-класс). PermissionSchema.domain переименовано resourceGroup; это поле группы UI,
не физический путь папки. JSON snapshot фиксирует shape.

D73: fluent подключение источников теперь PanelBuilder::permissions(array $definitions); этот же метод
регистрирует дополнительные enum definitions. Смешанный список нормализуется по типам, FolderSource один.
AzGuard::sources()/SourceManager — фабрика механизма, config sources.* — настройки именованных источников.
PanelAccess::permissions() — scoped manager данных, PanelSchema::permissions() — schema getter; BaseRole::permissions()
— права роли. Совпавшее имя у разных receivers не создаёт перегрузки одного PHP класса.

D74: селектор модели HasAzGuard::guard(array|string $guarded) возвращает native Model для array, SubjectAccess для string;
SubjectPanels::guard(string) создаёт SubjectAccess без перегрузки; native Eloquent конфликт — 18 §1.
Panel/PanelAccess/PanelBuilder остаются понятиями authorization panels; for(...guard:) — auth guard Laravel.
D75–D78 уточнены D80–D83: BaseAssignmentScope.make/filter/label/directory — immutable config. Нет using/profiles/options.
BaseRole — реальный PHP-класс, без roleModel/field/JSON config. AssignmentScopeRuntime/LookupContext/ChangeContext явно
передают runtime target/actor/scope/contribution/phase; PluginContext только build metadata.
Concrete plugin make имеет named typed parameters; CrmModels — DTO конкретного plugin, BasePlugin factory нет.
BeforeResult Continue/Deny не является native Gate shortcut. PermissionAuthority Policy/Grants задаётся явно
PolicyOnly/RequiresGrant на enum/case. CodeStateToken отличается от storage StateToken.
D79/D83: реальная приёмка R01–R68; документация/архивная reference model не доказывает production readiness.
