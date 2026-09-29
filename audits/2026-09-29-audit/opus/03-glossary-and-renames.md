# 03 — Словарь и карта «было → стало»

Файл обязателен для исполнителей: новое имя берётся **только** отсюда. Если символа нет в таблицах, он либо переезжает
в свою зону ([04](04-packages-and-layout.md)) без смены короткого имени, либо удаляется по решению из
[02](02-decisions.md).

Принцип: **язык продукта сохраняется** (Panel, PanelProvider, Permission, Role, GrantSource, HasAzGuard, Context,
политики). Публичные методы модели — как в Spatie Permission, чтобы переход был прямым. Новое имя появляется только
там, где старое вводило в заблуждение (`ModelHasScope`, `ContextRole`, `PermissionLayer`, `GrantBuilder`) или где
понятия не было (Schema, Hooks, Restriction, Storage, Plugin). Совместимости с 0.3 нет: старые имена удаляются, а
не остаются алиасами ([D01](02-decisions.md#d01)).

## 1. Словарь

| Термин | Простыми словами | Точно | Где в коде |
|---|---|---|---|
| **Panel** | Конструктор прав для части приложения: кабинет, админка, API, модуль | Неизменяемое после загрузки определение: субъекты, права, механики, контексты, суперадмин, хуки, хранилище, плагины | `Panels\Panel`, `PanelBuilder`, `PanelProvider`, `PanelRegistry` |
| **Default panel** | Панель, которую не нужно указывать в проверке | Панель с `->default()` для модели или единственная панель модели | `Panels\PanelResolver` |
| **Current panel** | Панель группы маршрутов | Ставится middleware `azguard.panel` или Filament; шаг 2 правила выбора панели | `Panels\CurrentPanel` |
| **Permission** | То, что можно разрешить | Определение в каталоге панели | `Catalog\PermissionDefinition` |
| **Local name** | Имя права внутри панели | `orders.view` (≥ 2 сегментов) | `PermissionKey::local()` |
| **Full name** | Имя права с панелью | `admin:orders.view` | `Kernel\Identity\PermissionKey` |
| **Pattern** | Выдача сразу многих прав | `orders.*`, `orders.**` — только в выдаче | `Kernel\Identity\PermissionPattern` |
| **Catalog** | Все права панели | Собирается построителями при загрузке | `Contracts\Catalog\PermissionCatalog` |
| **Grant source (механика)** | Откуда берутся права: код, БД, связи, свои | Шаг «решение» пайплайна проверки | `Contracts\Sources\GrantSource` |
| **Code role** | Роль, описанная классом; права меняются только в коде | `CodeRole` с `key()`, `permissions()` | `Roles\CodeRole` |
| **Automatic role** | Роль, которую получает каждый, кто подходит под правило | `AssignedAutomatically::appliesTo()` | `Roles\AssignedAutomatically` |
| **Database role** | Роль, созданная во время работы; права редактируются | Строка `{p}roles` | `Storage\Models\Role` |
| **Role assignment** | «Анне выдана роль» (в сущности, до даты, кем-то) | Строка назначения; роль — по ключу | `Storage\Models\RoleAssignment` |
| **Direct permission** | «Борису выдано право напрямую» | Строка прямого права или шаблона | `Storage\Models\DirectPermission` |
| **Policy (механика)** | Метод Laravel-политики решает право с учётом объекта | `#[Decides(Perm::X)]` на методе | `Policies\Decides`, `Policies\ConsultsGrants` |
| **Relation (механика)** | Права из связи моделей приложения | `->relation(Project::class, via:, role:)` | `Sources\Relations\RelationSource` |
| **Context** | Сущность, в которой действует право: проект, магазин | `global` или `{type}:{id}` | `Kernel\Identity\ContextRef` |
| **Resource** | Объект, о котором спрашивают (заказ); передаётся политикам | Модель из `on:`, не являющаяся контекстом | `AccessRequest::$resource` |
| **Context policy** | Как панель относится к сущностям | `inherit` / `isolated` / `required` / `none` (+ членство) | `Contexts\ContextPolicy` |
| **Subject** | У кого права | Любая модель с трейтом: пользователь, проект, команда | `Kernel\Identity\SubjectRef`, `Concerns\HasAzGuard` |
| **Actor** | Кто меняет права (если известен) | Пользователь или system с причиной | `Kernel\Identity\ActorRef` |
| **Super admin** | Тот, кому в панели разрешено всё | Правило панели: роль, класс или замыкание | `Contracts\Authorization\SuperAdminRule` |
| **Contribution** | «Право есть, потому что роль Y в сущности Z до даты D» | Элемент результата сбора | `Kernel\Decision\Contribution` |
| **Restriction** | Правило, которое умеет только запрещать | Шаг «ограничения» | `Contracts\Authorization\Restriction` |
| **Hook** | Точка вмешательства в проверку или изменение | `before`, `after`, `changing`, `changed` | `Contracts\Hooks\*` |
| **Access pipeline** | Путь проверки | Панель → подготовка → before → решение → ограничения → after | `Authorization\Pipeline\AccessPipeline` |
| **Change pipeline** | Путь изменения | Проверка данных → `changing` → запись → события и `changed` | `Changes\ChangePipeline` |
| **Change** | Описание изменения | Неизменяемое значение | `Changes\Change` |
| **Schema** | Описание панели для интерфейсов | Права, роли, поля, сущности и как их заполнять | `Schema\PanelSchema` |
| **Plugin** | Упаковка возможностей для панели | `id()`, `register(PanelBuilder)`, `boot(Panel)` | `Contracts\Plugins\Plugin` |
| **Storage** | Где лежат данные панели | Подключение + префикс + тип ключей + модели | `Storage\Storage` |
| **Decision** | Ответ | `Effect` + причина + `StateToken` | `Kernel\Decision\Decision` |
| **State token** | Версия прав панели | `{panel, version, generation, fingerprint}` | `Kernel\Decision\StateToken` |
| **Ability** | Строка Laravel Gate | Для AzGuard — локальное или полное имя права | только `Laravel\Gate\GateBridge` |
| **Visibility** | «Какие записи субъект видит по выдачам» | Явный фильтр запроса | `Authorization\Visibility`, `Contexts\ContextAware` |

Слова, которые не используются в публичных именах: **scope** (кроме Eloquent), **ability** (кроме Gate), **guard**
(кроме auth guard и `guardPanel` в Filament-пакете), **level**, **rank**, **realm**, **grant** как глагол (есть
`givePermissionTo`).

## 2. Правила именования

Колонка «Экосистема» отмечает общие инженерные правила, одинаковые с Vaulter (D43).

| Что | Правило | Пример | Экосистема |
|---|---|---|---|
| Методы модели | как в Spatie Permission | `assignRole()`, `givePermissionTo()`, `hasPermissionTo()` | — |
| Сущность в методе | именованный аргумент `on:` | `hasPermissionTo('projects.edit', on: $project)` | — |
| «От чьего имени» | `AzGuard::actingAs($actor, fn)`; строка — system с причиной | `AzGuard::actingAs('import: crm', …)` | ✓ (форма отличается: актор необязателен) |
| Результат | существительное без суффикса | `DecisionSet`, `ChangeResult` | ✓ |
| Событие | прошедшее время `<Noun><Verb>ed` | `RoleAssigned`, `PermissionGiven` | ✓ |
| Тип события | `noun.verb_past` | `role.assigned` | ✓ |
| Исключение | `<Condition>Exception`, код `snake_case` | `PanelNotResolvedException` / `panel_not_resolved` | ✓ |
| Контракт и реализация | одно короткое имя в разных namespace | `Contracts\Sources\GrantSource` / `Sources\Database\DatabaseSource` | ✓ |
| Ключ плагина, механики, ограничения | `vendor/name` | `azguard/database`, `acme/blog` | ✓ |
| id панели | `^[a-z0-9][a-z0-9-]{0,63}$` | `admin`, `cabinet`, `seller` | ✓ (та же грамматика ключей реестров) |
| Имя права | локальное `resource.action` (≥ 2 сегментов), полное `panel:local` | `orders.view_any`, `admin:orders.view_any` | — |
| Имя роли | локальное в kebab-case, полное `panel:key` | `support`, `admin:support` | — |
| Команда | `azguard:<area>:<verb>` | `azguard:roles:assign` | ✓ |
| Генератор | `azguard:make:<thing>` | `azguard:make:panel` | ✓ |
| Middleware alias | `azguard.<noun|verb>` | `azguard.panel`, `azguard.can` | — |
| Конфиг | файл на пакет, `snake_case` | `config/azguard.php` | ✓ |
| Таблица | `<prefix><plural_noun>` | `azg_role_assignments` | ✓ |
| Getter значений | существительное без `get` (кроме методов Spatie) | `$panel->id()`, `->label()` | — |

Словарь аргументов: `permission` (имя, enum), `role` (имя, enum, класс), `on` (сущность или ресурс), `panel` (id —
только когда нужно указать явно), `expiresAt`, `fields` (свои поля), `reason`.

## 3. Пакеты и namespace

| Было | Стало |
|---|---|
| `axioma-studio/azguard-core` (`AzGuard\`) | `axiomasoft/azguard` (`AzGuard\`) |
| `axioma-studio/azguard-context` (`AzGuard\Context\`) | влит в ядро: `AzGuard\Contexts\` |
| `axioma-studio/azguard-filament` (`AzGuard\Filament\`) | `axiomasoft/azguard-filament` |

## 4. Классы: core

| Было | Стало | Примечание |
|---|---|---|
| `AzGuardServiceProvider`, `AzGuardManager`, `Facades\AzGuard` | те же | менеджер — корень фасада без состояния |
| `Contracts\AzGuardManagerInterface` | — | `Contracts\PanelAccess` + фасад |
| `Guard\Authorizer` | `Authorization\Authorizer` (internal) + `Laravel\Gate\GateBridge` | |
| `Registry\Resolver\EffectivePermissionResolver` | `Authorization\Pipeline\AccessPipeline` | |
| `Contracts\PermissionResolverInterface` | — | расширение — механики и хуки |
| `Registry\Resolver\PermissionCache` | `Authorization\Cache\PermissionSetCache` | без эпох |
| `Registry\Resolver\PermissionStateRevision` | `Storage\PanelState` + `Storage\Storage::mutate()` | версия на панель |
| `Registry\Resolver\SubjectIdentity` | `Kernel\Identity\SubjectRef` + `IdentityCodec` | |
| `Registry\Values\PermissionSet` | `Kernel\Permissions\PermissionSet` | |
| `Registry\Contracts\GrantSource` | `Contracts\Sources\GrantSource` | имя сохраняется, новая сигнатура |
| `Registry\Contracts\GrantPriority` | — | объединение не зависит от порядка |
| `Registry\Sources\ClassRoleGrantSource` | `Sources\Code\CodeSource` | механика `azguard/code` |
| `Registry\Sources\DatabaseRoleGrantSource`, `DirectGrantSource` | `Sources\Database\DatabaseSource` | механика `azguard/database` |
| — | `Sources\Relations\RelationSource` | механика `azguard/relations` |
| `Registry\Contracts\PermissionCatalog`, `PermissionCatalogBuilder` | `Contracts\Catalog\PermissionCatalog`, `PermissionCatalogBuilder` | |
| `Registry\Contracts\PermissionDefinition`, `PermissionMeta`, `Registry\Definitions\*` | `Catalog\PermissionDefinition` | |
| `Registry\Builders\CompositePermissionCatalog` | `Catalog\PanelCatalog` | |
| `Registry\Builders\EnumPermissionCatalogBuilder` | `Catalog\Builders\EnumCatalogBuilder` | |
| `Registry\Builders\PolicyAbilityCatalogBuilder` | `Policies\PolicyBindings` | привязки политик к правам |
| `Registry\Matching\*`, `Contracts\PermissionMatcher` | `Kernel\Grammar\PatternMatcher` | одна грамматика |
| `Registry\Validation\*`, `Contracts\RolePermissionValidator` | — | проверка — шаг пайплайна изменений |
| `Permissions\PermissionKey` (константы, `WILDCARD`) | `Kernel\Identity\PermissionKey` (значение) | звёздочки нет |
| `Permissions\PermissionGrammar` | `Kernel\Grammar\PermissionGrammar` | |
| `Permissions\PermissionName`, `CatalogKeyMatcher` | `Panels\PanelResolver`, `Catalog\PanelCatalog` | одно правило выбора панели |
| `Permissions\InteractsWithPanel` | `Concerns\BelongsToPanels` | enum знает свои панели |
| `Contracts\Permission` | `Contracts\Permission` | `key(): string` — локальное имя |
| `Panels\Panel` | `Panels\PanelBuilder` (описание) + `Panels\Panel` (readonly) | |
| `Panels\PanelProvider` | `Panels\PanelProvider` | метод `panel(PanelBuilder)` |
| `Panels\PanelResolver`, `Runtime\CurrentPanelState` | `Panels\PanelResolver` (правило выбора), `Panels\CurrentPanel` | |
| `Contracts\RoleInterface`, `Roles\BaseRole` | `Roles\CodeRole` | `getName()` → `key()`; `getLevel()` удалён |
| `Roles\SuperAdminRole` | `Roles\SuperAdminRole` | код-роль `superadmin` для правила по умолчанию |
| `Roles\RolePermissionSynchronizer`, `…Selection`, `…SyncResult` | `Changes\RoleManager::syncPermissions()`, `ChangeResult` | |
| `Roles\RolePermissionSyncConflictException` | `Exceptions\StaleSelectionException` | |
| `Support\RoleIdentity` | `Kernel\Identity\RoleKey` | |
| `Support\RoleSyncPlanner`, `Commands\SyncRolesCommand` | — | роли из кода не копируются в БД |
| `Grants\GrantBuilder` | — | `givePermissionTo()` |
| `Models\Role`, `Models\RolePermission` | `Storage\Models\Role`, `RolePermission` | только роли из БД |
| `Models\DirectGrant` | `Storage\Models\DirectPermission` | |
| `Models\ModelHasScope` (+ `model_has_roles`) | `Storage\Models\RoleAssignment` | |
| `Concerns\HasAzGuard`, `HasRoles`, `HasPermissions`, `HasDirectGrants` | `Concerns\HasAzGuard` | один трейт: проверки и изменения |
| `Concerns\HasScopedRoles`, `ResolvesRole` | — | `on:` в методах трейта; видимость — `ContextAware` |
| `Concerns\RevisionedPermissionModelWrites` | `Storage\Concerns\GuardsDirectWrites` | |
| `Contracts\AzGuardUser`, `HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles` | `Contracts\AzGuardSubject` | |
| `Contracts\PermissionLayer` | `Contracts\Authorization\Restriction` | |
| `Contracts\ContextGuard`, `ContextGrantBuilder`, `ContextGrantBuilderFactory`, `PermissionContext` | — | сущность — аргумент `on:` |
| `Contracts\ScopeInterface` | — | `Authorization\Visibility` |
| `Contracts\AbilitiesResolver`, `Abilities\*` | — | `SubjectAccess::abilities()` |
| `Attributes\GateAbility` | `Policies\Decides` | привязка метода политики к праву |
| `Attributes\GuardPolicy` | — | модель берётся из сигнатуры метода |
| `Attributes\CheckPermission`, `SkipGuardCheck`, `RoleOnly` | — | |
| — | `Attributes\Describe` | подпись, группа, описание права |
| `Policies\AuthorizesPermission` | `Policies\ConsultsGrants` | `granted()` вместо `allows()` |
| `Guard\PolicyDiscovery` | `Policies\PolicyDiscovery` | только по `->discoverPolicies()`, результат в кэше каталога |
| `Auth\PolicyAttributeRegistrar`, `Auth\DirectGrantPolicy`, `Auth\BladeHelper` | — | политики вызываются внутри пайплайна; `Gate::define` не нужен |
| `Guard\AzGuardDiagnostics` | `Diagnostics\Doctor` + `Contracts\Diagnostics\DoctorCheck` | |
| `Http\Middleware\SetCurrentPanel` | `Laravel\Http\Middleware\EnterPanel` (`azguard.panel`) | вход в панель, не только маршрутизация |
| `Http\Middleware\CheckAccess`, `PanelCheckAccess` | `Laravel\Http\Middleware\Authorize` (`azguard.can`) | |
| `Http\Middleware\CheckDirectGrant`, `LoadAzGuardRoles` | — | |
| `Configuration\Config` | `Configuration\AzGuardConfig` | без нормализатора |
| `Database\Schema\MorphColumns` | `Storage\Schema\HostKeyColumns` | |
| `Database\Schema\NullSafeUniqueIndex`, `AssignmentDeduplicator` | — | в идентичности нет NULL |
| `Runtime\RequestState`, `ScopedRoleCache` | `Internal\RequestMemo` | |
| `Scaffold\GuardScaffoldGenerator` | `Laravel\Console\Scaffold\*` | |
| `Testing\AzGuardFake`, `Testing\FakeGrantSource` | те же | новые ассерты (§11) |
| `Testing\FakeAzGuardUser`, `Testing\Recorded` | `Testing\FakeSubject`, `RecordedCheck`/`RecordedChange` | |
| — | `Schema\PanelSchema`, `PermissionSchema`, `RoleSchema`, `FieldSchema` | D54 |
| — | `Contracts\Hooks\BeforeHook`, `AfterHook`, `ChangingHook`, `ChangedHook` | D55 |
| — | `Contracts\Plugins\Plugin`, `BasePlugin`, `DependsOnPlugins`, `PrefixesKeys` | D47 |

## 5. Классы: бывший `azguard-context`

| Было | Стало |
|---|---|
| `AuthorizationContext` | `Kernel\Identity\ContextRef` (без `panelId`) |
| `AuthorizationContextManager` | `Contexts\CurrentContext` (scoped) |
| `ContextGuard`, `ContextPermissionLayer`, `ContextGrantBuilder(Factory)`, `ContextNotSetException` | — (сущность — аргумент `on:`, выдачи — трейт, применение — пайплайн) |
| `Contracts\MergeStrategy`, `Strategies\*` | `Contexts\ContextPolicy` (`inherit`/`isolated`/`required`/`none`) |
| `Contracts\ResolvesContext` | `Contracts\Contexts\ContextResolver` |
| `Middleware\SetAuthorizationContext` | часть `azguard.panel` (резолверы панели) |
| `Models\ContextRole` | `Storage\Models\DirectPermission` с сущностью |
| `Events\ContextGrantGiven/Revoked` | `Events\PermissionGiven/PermissionRevoked` (с `context`) |
| `Commands\*` | `azguard:permissions:give|revoke --on=` |

## 6. Классы: filament

| Было | Стало |
|---|---|
| `AzGuardPlugin::forPanel($id)` | `AzGuardPlugin::guardPanel(string $id)` + `manages(array $panels)` |
| `source('policy')`, `source('enum')` | удаляется; права ресурсов — плагином `azguard/filament` в каталоге `guardPanel` |
| `Resources\DirectGrantResource` | `Resources\DirectPermissionResource` |
| — | `Resources\RoleAssignmentResource` |
| `Resources\RoleResource` | тот же, по схеме панели, без `class_name` |
| `Permissions\ResourceGate` | `Authorization\FilamentGate` |
| `Permissions\FilamentPermissionCatalogBuilder` | `Catalog\FilamentCatalogBuilder` |
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
| `AzGuard::isSuperAdmin($user, $panel)` | `$user->isSuperAdmin()` / `$user->inPanel($p)->isSuperAdmin()` |
| `AzGuard::abilitiesFor($user, $panel, $keys)` | `$user->inPanel($p)->abilities($keys)` |
| `AzGuard::registerGrantSource()`, `registerCatalogBuilder()` | на панели: `->source()`, `->catalogBuilders()` или плагин |
| `AzGuard::forUser($u)->on($p)->ttl()->grant($k)` | `$u->inPanel($p)->givePermissionTo($k, expiresAt:)` |
| `…->revoke($k)`, `->revokeAll()`, `->grants()` | `revokePermissionTo()`, `syncPermissions([])`, `->inPanel($p)->directPermissions()` |
| `…->inContext($t, $id)` | `on: $model` или `on: ContextRef::of($t, $id)` |
| `$user->hasPermission($perm, $panel, $context)` | `$user->hasPermissionTo($perm, on: $c)`; панель — `inPanel()` или полное имя |
| `$user->hasPermissionIn($type, $id, $perm, $panel)` | `$user->hasPermissionTo($perm, on: ContextRef::of($type, $id))` |
| `$user->checkPermission(...)`, `flushPermissions()`, `hasContextGuard()` | — |
| `$user->permissionSet($panel)`, `permissions($panel)` | `$user->getAllPermissions(on:)`, `$user->azguard()->permissions()` |
| `$user->isSuperAdmin($panel)` | `$user->isSuperAdmin()` (панель по правилу выбора) |
| `$user->hasRole($role)` | `$user->hasRole($role, on:)` |
| `$user->assignRole/removeRole/syncRoles(...)` | те же имена + `on:`, `expiresAt:`, `fields:` |
| `$user->assignScopedRole($role, $entity, $panel)` | `$user->inPanel($panel)->assignRole($role, on: $entity)` |
| `$user->removeScopedRole(...)`, `removeScopedRoleEverywhere(...)` | `removeRole($role, on: $entity)`, `removeRole($role, on: AnyContext::all())` |
| `$user->hasScopedRole/hasScopedPermission(...)` | `hasRole/hasPermissionTo(..., on: $entity)` |
| `$user->grant/revoke($perm, $panel)`, `grants()`, `hasGrant()` | `givePermissionTo/revokePermissionTo`, `->directPermissions()`; `hasGrant` удаляется |
| `$user->roles()`, `scopes()`, `directGrants()`, `getRoleNames()` | `->inPanel($p)->assignments()`, `getRoleNames(on:)` |

## 8. Конфигурация

Старый конфиг не читается ([D01](02-decisions.md#d01)); таблица — для ориентира исполнителю при удалении.

| Было (`az-guard.*`) | Стало (`azguard.*`) |
|---|---|
| файлы `az-guard.php`, `az-guard-context.php`, `az-guard-filament.php` | `azguard.php`, `azguard-filament.php` |
| `panels` | `panels.providers` |
| `default_panel` | `->default()` на панели |
| `strict_panels`, `require_permission_attributes`, `scope.on_missing_panel`, `middleware.*` | — |
| `manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator` | — (расширение — механики и хуки) |
| `models.*` | `defaults.models.*` (панель переопределяет `->models()`) |
| `table_names.*` | `storages.default.table_prefix` |
| `column_names.morph_type` | `ids.host_keys` |
| `cache.store`, `expiration_time`, `generation` | `defaults.cache.store`, `.ttl`, `.generation`, `defaults.consistency.*` |
| `grant_sources`, `features.direct_grants` | механики на панели; `defaults.database` |
| `fail_on_source_exception`, `features.wildcard_permission`, `features.teams`, `teams.*`, `features.validate_role_permissions` | — |
| `features.audit_log` | `defaults.trace_decisions` |
| `prune_expired_daily` | `schedule.prune_expired` |
| `az-guard-context.merge_strategy` | `ContextPolicy` на панели |
| `az-guard-context.resolvers` | `->contextResolvers()` / `defaults.contexts.resolvers` |
| `az-guard-filament.panel`, `user_label_column`, `super_admin` | `AzGuardPlugin::guardPanel()`, директория субъектов панели, правило суперадмина панели |

## 9. Таблицы и колонки (общее хранилище, префикс `azg_`)

| Было | Стало |
|---|---|
| `roles(name, class_name, level)` | `azg_roles(panel, key, label, description, meta)` — только роли из БД |
| `az_guard_role_permissions(role_id, permission_key, panel_id)` | `azg_role_permissions(role_id, permission)` |
| `model_has_roles(role_id, model_*)` | `azg_role_assignments(panel, role, …, context_key = 'global')` |
| `model_has_scopes(model_*, scope_entity_*, scope_class, role_id, panel_id)` | `azg_role_assignments(… context_key = '{type}:{id}')` |
| `az_direct_grants(grantable_*, permission_key, panel_id, expires_at)` | `azg_direct_permissions(panel, subject_*, permission, context_key = 'global', …)` |
| `az_guard_context_roles(model_*, context_*, panel_id, permission_key, expires_at)` | `azg_direct_permissions(… context_key = '{type}:{id}')` |
| `az_guard_permission_state(id, revision)` | `azg_panel_state(panel, version)` + `azg_storage_state` |
| — | `azg_audit_log` (плагин `azguard/audit`) |

## 10. Команды, middleware, Blade, события, исключения

| Было | Стало |
|---|---|
| `guard:install`, `guard:doctor`, `guard:catalog:validate` | `azguard:install`, `azguard:doctor` |
| `guard:catalog`, `guard:list-permissions` | `azguard:catalog:list` |
| — | `azguard:panels:list`, `azguard:catalog:cache|clear`, `azguard:storage:migration` |
| `guard:sync-roles` | — (роли из кода не синхронизируются); смена ключа — `azguard:roles:rename-key` |
| `guard:role-permissions`, `guard:role assign|detach` | `azguard:roles:permissions`, `azguard:roles:assign|remove` |
| — | `azguard:roles:create|delete|list` |
| `guard:list-scoped-roles` | `azguard:assignments:list` |
| `guard:grant`, `guard:revoke-grant`, `guard:grants`, `guard:context:grant|revoke` | `azguard:permissions:give|revoke [--on=]`, `azguard:assignments:list` |
| `guard:prune-grants` | `azguard:assignments:prune` |
| `guard:cache-reset` | `azguard:state:reset {panel}` |
| `guard:super-admin` | `azguard:roles:assign {subject} superadmin` (при правиле по роли) |
| `guard:explain`, `guard:abilities` | `azguard:explain`, `azguard:permissions:show` |
| `make:guard-panel`, `make:guard-domain`, `make:guard-permission`, `make:guard-role`, `make:guard-policy` | `azguard:make:panel`, `azguard:make:permissions`, `azguard:make:role`, `azguard:make:policy` |
| `make:guard-abilities` | — |
| — | `azguard:make:plugin`, `azguard:make:source`, `azguard:make:restriction`, `azguard:make:hook`, `azguard:make:models {panel}` |
| `azguard.panel` | `azguard.panel` (вход в панель) |
| `azguard.check`, `azguard.panel_check`, `azguard.grant`, `azguard.roles`, `check.access` | `azguard.can` |
| `azguard.context` | часть `azguard.panel` |
| `@azcan`, `@elseazcan`, `@unlessazcan`, `@azrole`, `@azdirect` | `@can`/`@cannot` |
| `GrantGiven`, `GrantRevoked` | `PermissionGiven`, `PermissionRevoked` |
| `RoleAttached`, `RoleDetached` | `RoleAssigned`, `RoleRemoved` |
| `AccessDecision` | `AccessDecided` (событие) + `Explanation` (результат `explain`) |
| — | `RoleCreated`, `RoleUpdated`, `RoleDeleted`, `RolePermissionsSynced`, `AssignmentExpired`, `PanelStateTouched` |
| `PanelNotSetException`, `PanelNotFoundException`, `PanelIdTooLongException` | `PanelNotResolvedException`, `UnknownPanelException`, `InvalidPanelIdException`, `AmbiguousPanelException` |
| `InvalidPermissionSyntaxException` | `InvalidPermissionKeyException` |
| `InvalidRoleClassException`, `InvalidRoleIdentityException` | `UnknownRoleException`, `InvalidRoleKeyException` |
| `InvalidMorphTypeException`, `InvalidCacheConfigException`, `InvalidModelConfigException` | `InvalidConfigurationException` (коды различают) |
| `MissingPermissionAttributeException`, `ContextPackageNotInstalledException`, `IdentityIndexException` | — |

## 11. Тестовые ассерты

| Было | Стало |
|---|---|
| `AzGuardFake::assertGranted($user, $key)` | `assertPermissionGiven($subject, $permission, on:)` |
| `AzGuardFake::assertDenied($user, $key)` (проверял **отзыв**) | `assertPermissionRevoked($subject, $permission, on:)` |
| — | `assertRoleAssigned`, `assertRoleRemoved`, `assertDecided($subject, $permission, Effect)` |
| `assertChecked($key)` | `assertChecked($permission)` |
