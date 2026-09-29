# 03 — Словарь и карта переименований

Файл нормативный для исполнителей: новое имя берётся **только** отсюда. Если символа нет в таблицах, он либо
переезжает в свою зону ([04](04-packages-and-layout.md)) без смены короткого имени, либо удаляется по решению из
[02](02-decisions.md).

Принцип второго прохода: **язык продукта сохраняется** (Panel, PanelProvider, Permission, Role, DirectGrant,
GrantSource, PermissionCatalogBuilder, Authorizer, HasAzGuard, Context). Новое имя появляется только там, где старое
вводило в заблуждение (`ModelHasScope`, `ContextRole`, `PermissionLayer`, `GrantBuilder` с записью в БД) или где
понятия не было (Actor, Subject, Restriction, Plugin, Pipeline, Storage).

## 1. Словарь

| Термин | Простыми словами | Точно | Где в коде |
|---|---|---|---|
| **Panel** | Отдельное пространство прав со своими настройками | Неизменяемое после загрузки определение: id (первый сегмент ключей), каталог, роли, субъекты, контексты, хранилище, модели, плагины, пайплайны, кэш, Gate | `Panels\Panel`, `PanelBuilder`, `PanelProvider`, `PanelRegistry` |
| **Panel authorizer** | «Всё про одну панель»: проверить, посмотреть, изменить | Рантайм-вход панели | `Authorization\PanelAuthorizer` (`AzGuard::panel('admin')`) |
| **Current panel** | Панель, к которой относится группа маршрутов | Маршрутизация запроса (guard, резолверы контекста, UI); **не** выбирает панель проверки | `Panels\CurrentPanel`, middleware `azguard.panel` |
| **Permission** | То, что можно разрешить | Определение в каталоге панели | `Catalog\PermissionDefinition` |
| **Permission key** | Полное имя права | `panel.segment[.segment…]` | `Kernel\Identity\PermissionKey` |
| **Local key** | Имя права без панели — так его пишут enum и модули | `orders.refund`; панель добавляет префикс | `PermissionKey::local()` |
| **Permission pattern** | Выдача сразу многих прав | `admin.orders.*`, `admin.**` — только в выдаче | `Kernel\Identity\PermissionPattern` |
| **Catalog** | Все права панели | Собирается построителями при загрузке | `Contracts\Catalog\PermissionCatalog` |
| **Catalog builder** | Кто добавляет права в каталог | enum, классы, конфиг, плагины, Filament | `Contracts\Catalog\PermissionCatalogBuilder` |
| **Role** | Именованный набор прав одной панели | `panel:key`, из кода (`code`) или из БД (`database`) | `Storage\Models\Role`, `Kernel\Identity\RoleKey` |
| **Role definition** | PHP-класс роли из кода | Привязка, не идентичность | `Contracts\Roles\RoleDefinition`, `Roles\CodeRole` |
| **Role assignment** | «Анне выдана роль» (где-то, до даты, кем-то) | Строка выдачи роли | `Storage\Models\RoleAssignment` |
| **Direct grant** | «Борису выдано право напрямую» | Строка выдачи права/шаблона без роли | `Storage\Models\DirectGrant` |
| **Context** | Где действует выдача: везде или внутри сущности | `global` или `{type}:{id}` | `Kernel\Identity\ContextRef` |
| **Context policy** | Как панель относится к контекстам | `inherit` / `isolated` / `required` / `none` (+ членство) | `Plugins\Contexts\ContextPolicy` |
| **Subject** | Кому проверяем/выдаём | Пользователь, покупатель, токен, сервис | `Kernel\Identity\SubjectRef` |
| **Actor** | Кто меняет права | Субъект или system с причиной | `Kernel\Identity\Actor`, `ActorRef` |
| **Superadmin** | Кому в панели можно всё, кроме ограничений `bypassable = false` | Держатель роли с `is_superadmin` / общий плагин | `Contracts\Authorization\SuperadminPolicy` |
| **Grant source** | Откуда при проверке берутся права | Шаг «сбор» пайплайна доступа | `Contracts\Authorization\GrantSource` |
| **Contribution** | «Право X есть, потому что роль Y в контексте Z до даты D» | Элемент результата сбора | `Kernel\Decision\Contribution` |
| **Restriction** | Обязательная проверка, умеет только запретить | Шаг «ограничения» | `Contracts\Authorization\Restriction` |
| **Access pipeline** | Путь проверки | Подготовка → суперадмин → сбор → ограничения → наблюдение | `Authorization\Pipeline\AccessPipeline` |
| **Change pipeline** | Путь изменения прав | Полномочия → проверка → перехват → запись → журнал → уведомления | `Administration\Pipeline\ChangePipeline` |
| **Change** | Описание операции изменения | Неизменяемое значение | `Administration\Change` |
| **Access manager** | Единственный вход для изменений панели | Handle с актором | `Contracts\Administration\AccessManager` (`->manage()`) |
| **Delegation policy** | Кто кому что может выдавать | Шаг «полномочия» | `Contracts\Administration\DelegationPolicy` |
| **Plugin** | Пакет возможностей для панели | `id()`, `register(PanelBuilder)`, `boot(Panel)` | `Contracts\Plugins\Plugin` |
| **Storage** | Где лежат данные панели | Соединение + префикс + типы ключей + модели | `Storage\Storage` |
| **Decision** | Ответ | `Effect` + причина + `StateToken` | `Kernel\Decision\Decision` |
| **State token** | Версия прав панели | `{panel, revision, generation, policyFingerprint}` | `Kernel\Decision\StateToken` |
| **Ability** | Строка Laravel Gate | Для AzGuard — полный ключ права | только `Laravel\Gate\GateBridge` |
| **Visibility** | «Какие записи субъект видит по выдачам в контексте» | Явный фильтр запроса | `Authorization\Visibility`, `Concerns\ContextAware` |

Слова, которые не используются в публичных именах: **scope** (кроме Eloquent), **ability** (кроме Gate),
**guard** (кроме auth guard и уточнения «guard panel» в Filament-пакете), **level**, **realm**.

## 2. Правила именования

Колонка «Экосистема» отмечает общие инженерные правила, одинаковые с Vaulter (D43); остальное — собственные правила
AzGuard.

| Что | Правило | Пример | Экосистема |
|---|---|---|---|
| Операция изменения (internal) | `<Verb><Noun>Operation` на шаге «Запись» | `AssignRoleOperation` | — |
| Публичный метод изменения | глагол на `AccessManager` | `assignRole()`, `grantPermission()` | — |
| «От чьего имени» | `actingAs($actor)` / `asSystem(string $reason)` | `->manage()->actingAs($vera)` | ✓ |
| Шаг пайплайна (контракт) | `<Verb>s<Noun>` для плагинных шагов | `PreparesAccess`, `ValidatesChange`, `NotifiesChange` | — |
| Результат | существительное без суффикса | `DecisionSet`, `ChangeResult` | ✓ |
| Событие | прошедшее время `<Noun><Verb>ed` | `RoleAssigned`, `PermissionGranted` | ✓ |
| Тип события | `noun.verb_past` | `role.assigned` | ✓ |
| Исключение | `<Condition>Exception`, код `snake_case` | `UnqualifiedPermissionException` / `unqualified_permission` | ✓ |
| Контракт и реализация | одно короткое имя в разных namespace | `Contracts\Authorization\Authorizer` / `Authorization\Authorizer` | ✓ |
| Ключ плагина/расширения | `vendor/name` | `azguard/roles`, `acme/blog` | ✓ |
| id панели | `^[a-z0-9][a-z0-9-]{0,63}$` | `admin`, `site`, `api` | ✓ (та же грамматика ключей реестров) |
| Ключ права | `panel.resource.action`, сегменты `[a-z0-9_-]` | `admin.orders.view_any` | — |
| Ключ роли | `panel:key`, key в kebab-case | `admin:support` | — |
| Команда | `azguard:<area>:<verb>` | `azguard:roles:sync` | ✓ |
| Генератор | `azguard:make:<thing>` | `azguard:make:panel` | ✓ |
| Middleware alias | `azguard.<verb>` | `azguard.can` | — |
| Конфиг | файл на пакет, `snake_case` | `config/azguard.php` | ✓ |
| Таблица | `<prefix><plural_noun>` | `azg_role_assignments` | ✓ |
| Getter значений | существительное без `get` | `$panel->id()`, `->label()` | — |

Словарь аргументов публичных методов: `subject`, `permission` (ключ/enum/класс), `pattern` (только выдача),
`role` (`RoleKey`, `'panel:key'`, класс), `context` (`ContextRef`, модель, `null`), `panel` (id — только когда его
нельзя вывести), `resource` (для ограничений), `expiresAt`, `attributes` (свои поля), `reason`.
Удаляются из публичных сигнатур: `panelId` как обязательный позиционный параметр, `contextType/contextId`, `ttl`.

## 3. Пакеты и namespace

| Было | Стало |
|---|---|
| `axioma-studio/azguard-core` (`AzGuard\`) | `axioma-studio/azguard` (`AzGuard\`) |
| `axioma-studio/azguard-context` (`AzGuard\Context\`) | влит: встроенный плагин `AzGuard\Plugins\Contexts\` |
| `axioma-studio/azguard-filament` (`AzGuard\Filament\`) | без изменений |

## 4. Классы: core

| Было | Стало | Примечание |
|---|---|---|
| `AzGuardServiceProvider`, `AzGuardManager`, `Facades\AzGuard` | те же | менеджер — корень фасада без состояния |
| `Contracts\AzGuardManagerInterface` | — | заменён `Contracts\Authorization\Authorizer` + `PanelAuthorizer` |
| `Guard\Authorizer` | `Authorization\Authorizer` (глобальный маршрутизатор по ключу) + `Authorization\PanelAuthorizer` + `Laravel\Gate\GateBridge` | |
| `Registry\Resolver\EffectivePermissionResolver` | `Authorization\Pipeline\AccessPipeline` | пайплайн доступа |
| `Contracts\PermissionResolverInterface` | — | сменяемого резолвера больше нет; расширение — шагами пайплайна |
| `Registry\Resolver\PermissionCache` | `Authorization\Cache\PermissionSetCache` | без эпох |
| `Registry\Resolver\PermissionStateRevision` | `Storage\StateRevision` (строка на панель) + `Storage\Storage::mutate()` | |
| `Registry\Resolver\SubjectIdentity` | `Kernel\Identity\SubjectRef` + `IdentityCodec` | |
| `Registry\Values\PermissionSet` | `Kernel\Permissions\PermissionSet` | имя сохраняется |
| `Registry\Contracts\GrantSource` | `Contracts\Authorization\GrantSource` | имя сохраняется, новая сигнатура |
| `Registry\Contracts\GrantPriority` | — | объединение не зависит от порядка |
| `Registry\Sources\ClassRoleGrantSource`, `DatabaseRoleGrantSource` | `Plugins\Roles\RoleGrantSource` | встроенный плагин `azguard/roles` |
| `Registry\Sources\DirectGrantSource` | `Plugins\DirectGrants\DirectGrantSource` | встроенный плагин `azguard/direct-grants` |
| `Registry\Contracts\PermissionCatalog`, `PermissionCatalogBuilder` | `Contracts\Catalog\PermissionCatalog`, `PermissionCatalogBuilder` | имена сохраняются |
| `Registry\Contracts\PermissionDefinition`, `PermissionMeta`, `Registry\Definitions\*` | `Catalog\PermissionDefinition` | final readonly |
| `Registry\Builders\CompositePermissionCatalog` | `Catalog\PanelCatalog` | каталог одной панели |
| `Registry\Builders\EnumPermissionCatalogBuilder` | `Catalog\Builders\EnumCatalogBuilder` | без сканирования ФС |
| `Registry\Builders\PolicyAbilityCatalogBuilder` | — | D36 |
| `Registry\Matching\*`, `Contracts\PermissionMatcher` | `Kernel\Grammar\PatternMatcher` | одна грамматика |
| `Registry\Validation\*`, `Contracts\RolePermissionValidator` | — | проверка встроена в пайплайн изменений |
| `Registry\Exceptions\InvalidPermissionKeyException` | `Exceptions\UnknownPermissionException` | |
| `Permissions\PermissionKey` (константы) | `Kernel\Identity\PermissionKey` (значение) | `WILDCARD` удаляется |
| `Permissions\PermissionGrammar` | `Kernel\Grammar\PermissionGrammar` | |
| `Permissions\PermissionName` | — | `PermissionKey::from()` + правило D05 |
| `Permissions\CatalogKeyMatcher` | `Catalog\Ownership` | O(1) |
| `Permissions\InteractsWithPanel` | `Concerns\BelongsToPanels` | enum: `OrderPermission::Refund->in('admin')` |
| `Contracts\Permission` | `Contracts\Permission` | `ability()` → `key(): string` (локальная часть) |
| `Panels\Panel` | `Panels\PanelBuilder` (описание) + `Panels\Panel` (readonly) | |
| `Panels\PanelProvider` | `Panels\PanelProvider` | остаётся ServiceProvider (как в Filament); метод `panel(PanelBuilder)` |
| — | `Panels\PanelRegistry` | freeze, duplicate, `configurePanel()` |
| `Panels\PanelResolver`, `Runtime\CurrentPanelState` | `Panels\CurrentPanel` | только маршрутизация |
| `Contracts\RoleInterface` | `Contracts\Roles\RoleDefinition` | `getName()` → `key()`; `getLevel()` → `rank()` |
| `Roles\BaseRole` | `Roles\CodeRole` | |
| `Roles\SuperAdminRole` | `Plugins\Superadmin\SuperadminRole` | роль `{panel}:superadmin`, флаг суперадмина |
| `Roles\RolePermissionSynchronizer`, `…Selection`, `…SyncResult` | `Administration\Operations\SetRolePermissionsOperation`, `Administration\PermissionSelection`, `ChangeResult` | |
| `Roles\RolePermissionSyncConflictException` | `Exceptions\StaleSelectionException` | |
| `Support\RoleIdentity` | `Kernel\Identity\RoleKey` | |
| `Support\RoleSyncPlanner` | `Plugins\Roles\RoleSyncPlanner` | |
| `Grants\GrantBuilder` | — | `->manage()->grantPermission()` |
| `Models\Role`, `Models\RolePermission`, `Models\DirectGrant` | `Storage\Models\Role`, `RolePermission`, `DirectGrant` | базовые модели; панели наследуют |
| `Models\ModelHasScope` (+ `model_has_roles`) | `Storage\Models\RoleAssignment` | |
| `Concerns\HasAzGuard` | `Concerns\HasAzGuard` | только чтение |
| `Concerns\HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles`, `ResolvesRole` | — | D10 |
| `Concerns\RevisionedPermissionModelWrites` | `Storage\Concerns\GuardsDirectWrites` | |
| `Contracts\AzGuardUser`, `HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles` | `Contracts\AzGuardSubject` | |
| `Contracts\PermissionLayer` | `Contracts\Authorization\Restriction` | реестр вместо одного binding |
| `Contracts\ContextGuard`, `ContextGrantBuilder`, `ContextGrantBuilderFactory`, `PermissionContext` | — | контекст — аргумент |
| `Contracts\ScopeInterface` | — | `Authorization\Visibility` |
| `Contracts\AbilitiesResolver`, `Abilities\*` | — | `->for($s)->abilities([...])` |
| `Attributes\CheckPermission`, `SkipGuardCheck`, `GateAbility`, `GuardPolicy`, `RoleOnly` | — | |
| — | `Attributes\Describe` | метаданные case |
| `Auth\*`, `Policies\AuthorizesPermission`, `Guard\PolicyDiscovery` | — | |
| `Guard\AzGuardDiagnostics` | `Diagnostics\Doctor` + `Contracts\Diagnostics\DoctorCheck` | |
| `Http\Middleware\SetCurrentPanel` | `Laravel\Http\Middleware\UsePanel` (`azguard.panel`) | |
| `Http\Middleware\CheckAccess`, `PanelCheckAccess` | `Laravel\Http\Middleware\Authorize` (`azguard.can`) | |
| `Http\Middleware\CheckDirectGrant`, `LoadAzGuardRoles` | — | |
| `Configuration\Config` | `Configuration\AzGuardConfig` + `ConfigNormalizer` | |
| `Database\Schema\MorphColumns` | `Storage\Schema\HostKeyColumns` | |
| `Database\Schema\NullSafeUniqueIndex`, `AssignmentDeduplicator` | — | только внутри upgrade 0.4 |
| `Runtime\RequestState` / `ScopedRoleCache` | `Internal\RequestMemo` / — | |
| `Scaffold\GuardScaffoldGenerator` | `Laravel\Console\Scaffold\*` | |
| `Testing\AzGuardFake`, `Testing\FakeGrantSource` | те же | новые ассерты (§11) |
| `Testing\FakeAzGuardUser`, `Testing\Recorded` | `Testing\FakeSubject`, `RecordedCheck`/`RecordedChange` | |

## 5. Классы: бывший `azguard-context`

| Было | Стало |
|---|---|
| `AuthorizationContext` | `Kernel\Identity\ContextRef` (без `panelId`) |
| `AuthorizationContextManager` | `Plugins\Contexts\CurrentContext` (scoped) |
| `ContextGuard`, `ContextPermissionLayer`, `ContextGrantBuilder(Factory)`, `ContextNotSetException` | — (контекст — аргумент, выдачи — менеджер, применение — пайплайн) |
| `Contracts\MergeStrategy`, `Strategies\*` | `Plugins\Contexts\ContextPolicy` (`inherit`/`isolated`/`required`/`none`) |
| `Contracts\ResolvesContext` | `Contracts\Contexts\ContextResolver` |
| `Middleware\SetAuthorizationContext` | `Laravel\Http\Middleware\ResolveContext` (`azguard.context`) |
| `Models\ContextRole` | `Storage\Models\DirectGrant` с контекстом |
| `Events\ContextGrantGiven/Revoked` | `Events\PermissionGranted/PermissionRevoked` (с `context`) |
| `Commands\*` | `azguard:grants:* --context=` |

## 6. Классы: filament

| Было | Стало |
|---|---|
| `AzGuardPlugin::forPanel($id)` | `AzGuardPlugin::guardPanel(string $id)` + `manages(array $panels)` |
| `source('policy')` | удаляется; `database`/`enum` |
| `Resources\DirectGrantResource` | `Resources\DirectGrantResource` (выдачи прав, с контекстом) |
| — | `Resources\RoleAssignmentResource` |
| `Resources\RoleResource` | тот же, без `class_name` |
| `Permissions\ResourceGate` | `Authorization\FilamentGate` |
| `Permissions\FilamentPermissionCatalogBuilder` | `Catalog\FilamentCatalogBuilder` |
| `Permissions\PageWidgetAccessEvaluator` | `Authorization\PageAccess` (fail-closed) |
| `Permissions\PolicyGenerator` | — |
| `Concerns\HasAzGuardPage/Widget` | `Concerns\AuthorizesPage/Widget` |
| — | `Contracts\FilamentFormExtension` (свои поля панели и плагинов в формах) |
| — | `Contracts\HasFilamentFields` (поля своей модели панели в формах) |
| — | `Pages\PanelsPage`, `Pages\DoctorPage` |
| `guard:filament:generate` | `azguard:filament:generate` |

## 7. Методы

| Было | Стало |
|---|---|
| `AzGuard::registerPanel()`, `getPanels()`, `panel()` | `azguard.panels.providers` / `AzGuard::registerPanel(Provider::class)`; `AzGuard::panels()`; `AzGuard::panel($id)` (→ `PanelAuthorizer`) |
| `AzGuard::currentPanel()`, `setCurrentPanel()` | `AzGuard::currentPanel()` (маршрутизация), установка — middleware `azguard.panel` |
| `AzGuard::permission($panel, $perm)` | `PermissionKey::from($perm, panel: $id)` |
| `AzGuard::tryPermission()`, `panelIdForPermission()` | — (правило D05 внутри) |
| `AzGuard::isSuperAdmin($user, $panel)` | `AzGuard::panel($id)->for($s)->isSuperadmin()` |
| `AzGuard::abilitiesFor($user, $panel, $keys)` | `AzGuard::panel($id)->for($s)->abilities($keys)` |
| `AzGuard::registerGrantSource()`, `registerCatalogBuilder()` | плагин (`$panel->grantSources()`, `->catalogBuilders()`) |
| `AzGuard::forUser($u)->on($p)->ttl()->grant($k)` | `AzGuard::panel($p)->manage()->actingAs($a)->grantPermission($u, $pattern, context:, expiresAt:)` |
| `…->revoke($k)`, `->revokeAll()`, `->grants()` | `->revokePermission()`, `->revokePermissions()`, `AzGuard::panel($p)->for($u)->directGrants()` |
| `…->inContext($t, $id)` | `context: ContextRef::of($t, $id)` |
| `$user->hasPermission($perm, $panel, $context)` | `$user->hasPermission($perm, context: $c, panel: $p)` (панель — только если нельзя вывести) |
| `$user->hasPermissionIn($type, $id, $perm, $panel)` | `$user->hasPermission($perm, context: ContextRef::of($type, $id))` |
| `$user->checkPermission(...)`, `flushPermissions()`, `hasContextGuard()` | — |
| `$user->permissionSet($panel)`, `permissions($panel)` | `$user->permissions($panel, context:)` |
| `$user->isSuperAdmin($panel)` | `$user->isSuperadmin($panel)` |
| `$user->hasRole($role)` | `$user->hasRole('panel:key', context:)` |
| `$user->assignRole/removeRole/syncRoles(...)` | `->manage()->…->assignRole/revokeRole/syncRoles($subject, …)` |
| `$user->assignScopedRole($role, $entity, $panel)` | `->manage()->…->assignRole($subject, $role, context: $entity)` |
| `$user->removeScopedRole(...)`, `removeScopedRoleEverywhere(...)` | `->revokeRole($subject, $role, context: $entity)`, `context: AnyContext::all()` |
| `$user->hasScopedRole/hasScopedPermission(...)` | `hasRole/hasPermission(..., context: $entity)` |
| `$user->grant/revoke($perm, $panel)`, `grants()`, `hasGrant()` | `->manage()->grantPermission/revokePermission`, `->for($s)->directGrants()`; `hasGrant` удаляется |
| `$user->roles()`, `scopes()`, `directGrants()`, `getRoleNames()` | `->for($s)->assignments()`, `->roles()` |

## 8. Конфигурация

| Было (`az-guard.*`) | Стало (`azguard.*`) |
|---|---|
| файлы `az-guard.php`, `az-guard-context.php`, `az-guard-filament.php` | `azguard.php`, `azguard-filament.php` |
| `panels` | `panels.providers` |
| `default_panel`, `strict_panels`, `require_permission_attributes`, `scope.on_missing_panel`, `middleware.*` | — |
| `manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator` | — (расширение — плагины и шаги пайплайнов) |
| `models.*` | `defaults.models.*` (панель переопределяет `->models()`) |
| `table_names.*` | `storages.default.table_prefix` (upgrade читает старую карту) |
| `column_names.morph_type` | `ids.host_keys` (`int → bigint`) |
| `cache.store`, `expiration_time`, `generation` | `defaults.cache.store` (`array` → `null`), `.ttl`, `.generation`, `defaults.consistency.state_refresh` |
| `grant_sources` | плагины панели; выключение — `withoutPlugin()` |
| `fail_on_source_exception`, `features.wildcard_permission`, `features.teams`, `teams.*`, `features.validate_role_permissions` | — |
| `features.direct_grants` | `defaults.plugins` (`azguard/direct-grants` в списке) |
| `features.audit_log` | `defaults.trace_decisions` |
| `prune_expired_daily` | `schedule.prune_expired` |
| `az-guard-context.merge_strategy` | `ContextPolicy` на панели |
| `az-guard-context.resolvers` | `->contextResolvers()` на панели / `defaults.contexts.resolvers` |
| `az-guard-filament.panel`, `user_label_column`, `super_admin` | `AzGuardPlugin::guardPanel()`, директория субъектов панели, — |

## 9. Таблицы и колонки (общее хранилище, префикс `azg_`)

| Было | Стало |
|---|---|
| `roles(name, class_name, level)` | `azg_roles(panel, key, label, description, origin, definition, is_superadmin, rank, meta)` |
| `az_guard_role_permissions(role_id, permission_key, panel_id)` | `azg_role_permissions(role_id, permission)` |
| `model_has_roles(role_id, model_*)` | `azg_role_assignments(… context_key = 'global')` |
| `model_has_scopes(model_*, scope_entity_*, scope_class, role_id, panel_id)` | `azg_role_assignments(… context_key = '{type}:{id}')` |
| `az_direct_grants(grantable_*, permission_key, panel_id, expires_at)` | `azg_direct_grants(panel, subject_*, permission, context_key = 'global', …)` |
| `az_guard_context_roles(model_*, context_*, panel_id, permission_key, expires_at)` | `azg_direct_grants(… context_key = '{type}:{id}')` |
| `az_guard_permission_state(id, revision)` | `azg_panel_state(panel, revision, schema)` |
| — | `azg_audit_log` (плагин `azguard/audit`) |

## 10. Команды, middleware, Blade, события, исключения

| Было | Стало |
|---|---|
| `guard:install`, `guard:doctor`, `guard:catalog:validate` | `azguard:install`, `azguard:doctor` |
| `guard:catalog`, `guard:list-permissions` | `azguard:catalog:list` |
| — | `azguard:panels:list`, `azguard:catalog:cache|clear`, `azguard:storage:migration`, `azguard:storage:move`, `azguard:changes:pending`, `azguard:upgrade` |
| `guard:sync-roles`, `guard:role-permissions`, `guard:role assign|detach` | `azguard:roles:sync`, `azguard:roles:permissions`, `azguard:roles:assign|revoke` |
| `guard:list-scoped-roles` | `azguard:assignments:list` |
| `guard:grant`, `guard:revoke-grant`, `guard:grants`, `guard:context:grant|revoke` | `azguard:grants:issue|revoke|list [--context=]` |
| `guard:prune-grants` | `azguard:assignments:prune` |
| `guard:cache-reset` | `azguard:state:reset` |
| `guard:super-admin` | `azguard:roles:assign {subject} {panel}:superadmin` (+ `--all-panels`) |
| `guard:explain`, `guard:abilities` | `azguard:explain`, `azguard:permissions:show` |
| `make:guard-panel`, `make:guard-domain`, `make:guard-permission`, `make:guard-role` | `azguard:make:panel`, `azguard:make:permissions`, `azguard:make:role` |
| `make:guard-policy`, `make:guard-abilities` | — |
| — | `azguard:make:plugin`, `azguard:make:restriction`, `azguard:make:source`, `azguard:make:models {panel}` |
| `azguard.panel` | `azguard.panel` (только маршрутизация) |
| `azguard.check`, `azguard.panel_check`, `azguard.grant`, `azguard.roles`, `check.access` | `azguard.can` |
| `azguard.context` | `azguard.context` |
| `@azcan`, `@elseazcan`, `@unlessazcan`, `@azrole`, `@azdirect` | `@can`/`@cannot` |
| `GrantGiven`, `GrantRevoked` | `PermissionGranted`, `PermissionRevoked` |
| `RoleAttached`, `RoleDetached` | `RoleAssigned`, `RoleRevoked` |
| `AccessDecision` | `AccessDecided` (событие) + `Explanation` (результат `explain`) |
| — | `RoleCreated`, `RoleUpdated`, `RoleDeleted`, `RolePermissionsChanged`, `AssignmentExpired`, `ChangePending`, `AccessStateReset`, `RoleDefinitionMissing` |
| `PanelNotSetException`, `PanelNotFoundException`, `PanelIdTooLongException` | `UnknownPanelException`, `InvalidPanelIdException`, `AmbiguousPanelException` |
| `InvalidPermissionSyntaxException` | `InvalidPermissionKeyException` |
| `InvalidRoleClassException`, `InvalidRoleIdentityException` | `RoleDefinitionException`, `InvalidRoleKeyException` |
| `InvalidMorphTypeException`, `InvalidCacheConfigException`, `InvalidModelConfigException` | `InvalidConfigurationException` (коды различают) |
| `MissingPermissionAttributeException`, `ContextPackageNotInstalledException`, `IdentityIndexException` | — |

## 11. Тестовые ассерты

| Было | Стало |
|---|---|
| `AzGuardFake::assertGranted($user, $key)` | `assertPermissionGranted($subject, $pattern, context:)` |
| `AzGuardFake::assertDenied($user, $key)` (проверял **отзыв**) | `assertPermissionRevoked($subject, $pattern, context:)` |
| — | `assertRoleAssigned`, `assertRoleRevoked`, `assertChangePending`, `assertDecided($subject, $key, Effect)` |
| `assertChecked($key)` | `assertChecked($key)` |
