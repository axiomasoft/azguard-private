# 03 — Словарь и карта переименований

Файл нормативный для исполнителей: новое имя берётся **только** отсюда. Если символа нет в таблицах, он либо
переезжает в `Internal\` без смены короткого имени, либо удаляется по решению из [02](02-decisions.md).

## 1. Словарь

| Термин | Определение | Где в коде |
|---|---|---|
| **Realm** | Именованное пространство прав и ролей (`app`, `admin`, `billing`): каталог, роли, политика контекстов, constraints. Первый сегмент каждого ключа права | `AzGuard\Realms\Realm`, `RealmBuilder`, `RealmProvider`, `RealmRegistry` |
| **Permission** | Объявленная в каталоге возможность, которую можно выдать | `AzGuard\Catalog\PermissionDefinition` |
| **Permission key** | Каноническая идентичность права: `realm.segment[.segment…]` | `AzGuard\Kernel\Identity\PermissionKey` |
| **Permission pattern** | Выражение в выдаче, покрывающее ключи (`app.docs.*`, `app.**`) | `AzGuard\Kernel\Identity\PermissionPattern` |
| **Dynamic permission** | Определение каталога с плейсхолдером (`app.team.{id}.admin`) | `PermissionDefinition::isDynamic()` |
| **Catalog** | Все определения прав realm, собранные провайдерами | `AzGuard\Contracts\Catalog\PermissionCatalog` |
| **Catalog provider** | Поставщик определений (enum, классы, конфиг, Filament) | `AzGuard\Contracts\Catalog\CatalogProvider` |
| **Role** | Именованный набор шаблонов прав одного realm | `azg_roles`, `Models\Role` |
| **Role key** | Идентичность роли: `realm:key` | `AzGuard\Kernel\Identity\RoleKey` |
| **Code role / DB role** | Роль, права которой объявлены классом (`origin=code`) / хранятся в БД (`origin=database`) | `azg_roles.origin` |
| **Role definition** | Класс code-роли (привязка, не идентичность) | `AzGuard\Contracts\Roles\RoleDefinition`, `azg_roles.definition` |
| **Subject** | Тот, чьи права проверяются или меняются (пользователь, API-клиент, сервисная модель) | `AzGuard\Kernel\Identity\SubjectRef` |
| **Actor** | Инициатор изменения прав: пользователь или system с причиной | `AzGuard\Kernel\Identity\Actor`, `ActorRef` |
| **Context** | Область действия назначения: глобальная или конкретная сущность хоста (workspace, project) | `AzGuard\Kernel\Identity\ContextRef` |
| **Context policy** | Как realm применяет контексты: `inherit`/`isolated`/`required`/`none` | `AzGuard\Realms\ContextPolicy` |
| **Role assignment** | Субъект держит роль в контексте до срока | `azg_role_assignments`, `Models\RoleAssignment` |
| **Grant** | Субъекту напрямую выдан шаблон права в контексте до срока | `azg_grants`, `Models\Grant` |
| **Permission source** | Поставщик вкладов при чтении (роли, гранты, внешняя система) | `AzGuard\Contracts\Authorization\PermissionSource` |
| **Contribution** | Шаблон права из источника с происхождением и сроком | `AzGuard\Kernel\Decision\Contribution` |
| **Constraint** | Обязательная проверка после вкладов (членство, ресурс, лицензия) | `AzGuard\Contracts\Authorization\Constraint` |
| **Superadmin** | Субъект, для которого отсутствие гранта не приводит к отказу в realm (или во всех realm) | `AzGuard\Contracts\Authorization\SuperadminPolicy` |
| **Access request** | Вопрос движку: субъект × ключ × контекст × ресурс | `AzGuard\Kernel\Decision\AccessRequest` |
| **Decision** | Ответ движка: `Effect` (`Allow`/`Deny`/`NotApplicable`) + `DecisionReason` + `StateToken` | `AzGuard\Kernel\Decision\Decision` |
| **State token** | Версия состояния прав: ревизия + generation + отпечаток политики | `AzGuard\Kernel\Decision\StateToken` |
| **Ability** | Строка Laravel Gate; для AzGuard — квалифицированный ключ права | только `Laravel\Gate\GateBridge` |
| **Delegation policy** | Кто вправе менять чьи права | `AzGuard\Contracts\Administration\DelegationPolicy` |
| **Operation context** | Неизменяемый контекст записи: актор, correlation id, причина | `AzGuard\Administration\OperationContext` |
| **Visibility** | Фильтр запроса «какие записи субъект видит по назначениям в контексте» | `AzGuard\Authorization\Visibility`, `Concerns\ContextAware` |

Запрещённые слова в публичных именах: **panel** (кроме Filament), **scope** (кроме Eloquent), **ability** (кроме
Gate), **direct grant**, **context role**, **guard** (кроме auth guard), **level**.

## 2. Правила именования (согласованы с Vaulter)

| Что | Правило | Пример AzGuard | Пример Vaulter |
|---|---|---|---|
| Use case записи | `<Verb><Noun>Action::execute(OperationContext, <Verb><Noun>Input)` (internal) | `AssignRoleAction` | `MoveNodeAction` |
| Публичный метод записи | глагол на `AccessManager` | `assignRole()`, `issueGrant()` | `nodes()->move()` |
| Handle с актором | `actingAs($user)` / `asSystem(string $reason)` | `AzGuard::access()->actingAs($admin)` | `Vaulter::drive($o)->actingAs($u)` |
| Результат | существительное без суффикса | `RoleAssignmentResult`, `DecisionSet` | `NodePage` |
| Событие | прошедшее время `<Noun><Verb>ed` | `RoleAssigned`, `GrantIssued` | `NodeMoved` |
| Тип события | `noun.verb_past` | `role.assigned`, `grant.issued` | `node.moved` |
| Исключение | `<Condition>Exception`, код `snake_case` | `UnqualifiedPermissionException` / `unqualified_permission` | `NodeNameConflictException` |
| Контракт и реализация | одно короткое имя в разных namespace | `Contracts\Authorization\Authorizer` / `Authorization\Authorizer` | `Contracts\Drive\DriveHandle` / `Drive\DriveHandle` |
| Ключ расширения | `vendor/name`, `[a-z0-9-]` | `azguard/context-membership` | `vaulter/rich-text` |
| Ключ реестра (realm, профиль) | `^[a-z0-9][a-z0-9-]{0,63}$` | `admin`, `billing` | профиль `default` |
| Команда | `<pkg>:<area>:<verb>` | `azguard:roles:sync` | `vaulter:blobs:gc` |
| Генератор | `<pkg>:make:<thing>` | `azguard:make:realm` | `vaulter:make:profile` |
| Middleware alias | `<pkg>.<verb>` | `azguard.can`, `azguard.context` | — |
| Конфиг | файл на пакет, ключи `snake_case` | `config/azguard.php` | `config/vaulter.php` |
| Таблица | `<prefix><plural_noun>` | `azg_role_assignments` | `v_nodes` |
| Ключ права | `realm.resource.action`, сегменты `[a-z0-9_-]` | `app.documents.view_any` | (Vaulter не объявляет ключи) |
| Getter | существительное без `get` на readonly-значениях | `$realm->id()`, `->label()` | `Profile::getKey()` в builder (у Vaulter builder) |

Правило getter'ов отличается от Vaulter осознанно: у Vaulter `Profile` — fluent builder с `get*` readers; у AzGuard
builder и значение разделены (`RealmBuilder` пишет, `Realm` читает), поэтому у `Realm` нет конфликта имён.

Словарь аргументов публичных методов:

| Аргумент | Смысл |
|---|---|
| `subject` | Кому проверяем/выдаём (`Model`, `SubjectRef`, `Authenticatable`) |
| `permission` | `PermissionKey`, квалифицированная строка, enum case или класс `Permission` |
| `pattern` | Шаблон в выдаче (только запись) |
| `role` | `RoleKey`, строка `realm:key` или класс `RoleDefinition` |
| `context` | `ContextRef`, модель-контекст или `null` (= глобально / ambient для чтения) |
| `resource` | Объект, о котором спрашивают (для constraints); не участвует в назначениях |
| `expiresAt` | `DateTimeInterface|null` — абсолютный срок (ttl в секундах удаляется) |
| `reason` | Причина изменения (аудит) |

`panelId`, `$panel`, `contextType`, `contextId`, `ttl`, `$user` (в значении субъекта) из публичных сигнатур удаляются.

## 3. Пакеты и namespace

| Было | Стало |
|---|---|
| `axioma-studio/azguard-core` (`AzGuard\`) | `axioma-studio/azguard` (`AzGuard\`) |
| `axioma-studio/azguard-context` (`AzGuard\Context\`) | влит в `axioma-studio/azguard` (`AzGuard\Context\` — только ambient-контекст и резолверы) |
| `axioma-studio/azguard-filament` (`AzGuard\Filament\`) | без изменений |
| (Vaulter) `axioma-studio/vaulter-azgard` (`Vaulter\Azgard\`) | `axioma-studio/vaulter-azguard` (`Vaulter\AzGuard\`) — рекомендация для Vaulter, [10](10-ecosystem-vaulter.md) |

## 4. Классы: core

| Было | Стало | Примечание |
|---|---|---|
| `AzGuardServiceProvider` | `AzGuardServiceProvider` | тоньше: регистрирует модули |
| `AzGuardManager` | `AzGuardManager` (internal, корень фасада) | без состояния, только диспетчер |
| `Contracts\AzGuardManagerInterface` | — | удалить; контракты разнесены (ниже) |
| `Facades\AzGuard` | `Facades\AzGuard` | новая поверхность, [05 §1](05-php-api.md#1-фасад) |
| `Guard\Authorizer` | `Authorization\Authorizer` (impl) + `Laravel\Gate\GateBridge` | контракт `Contracts\Authorization\Authorizer` |
| `Registry\Resolver\EffectivePermissionResolver` | `Authorization\PermissionSetResolver` (internal) | |
| `Contracts\PermissionResolverInterface` | — | заменён `Authorizer` |
| `Registry\Resolver\PermissionCache` | `Authorization\Cache\PermissionSetCache` (internal) | без эпох |
| `Registry\Resolver\PermissionStateRevision` | `Database\AzGuardDatabase` + `State\StateRevision` (internal) | |
| `Registry\Resolver\SubjectIdentity` | `Kernel\Identity\SubjectRef` + `Kernel\Identity\IdentityCodec` | |
| `Registry\Values\PermissionSet` | `Kernel\Permissions\PermissionSet` | шаблоны + `validUntil` + вклады |
| `Registry\Contracts\GrantSource` | `Contracts\Authorization\PermissionSource` | новая сигнатура, [06 §2](06-extension-points.md#2-permission-source) |
| `Registry\Contracts\GrantPriority` | — | порядок источников не влияет на результат |
| `Registry\Sources\ClassRoleGrantSource`, `DatabaseRoleGrantSource` | `Authorization\Sources\RolesSource` (`azguard/roles`) | одна загрузка назначений, роли из кода и БД |
| `Registry\Sources\DirectGrantSource` | `Authorization\Sources\GrantsSource` (`azguard/grants`) | |
| `Registry\Contracts\PermissionCatalog` | `Contracts\Catalog\PermissionCatalog` | |
| `Registry\Contracts\PermissionCatalogBuilder` | `Contracts\Catalog\CatalogProvider` | |
| `Registry\Contracts\PermissionDefinition`, `PermissionMeta`, `Registry\Definitions\*` | `Catalog\PermissionDefinition` (final readonly) | метаданные — поля определения |
| `Registry\Builders\CompositePermissionCatalog` | `Catalog\Catalog` (impl) | |
| `Registry\Builders\EnumPermissionCatalogBuilder` | `Catalog\Providers\EnumCatalogProvider` | без сканирования ФС |
| `Registry\Builders\PolicyAbilityCatalogBuilder` | — | удалить (D36) |
| `Registry\Matching\HierarchicalPermissionMatcher` | `Kernel\Grammar\PatternMatcher` | единственная грамматика |
| `Registry\Matching\WildcardPermissionMatcher`, `Contracts\PermissionMatcher` | — | удалить (D18) |
| `Registry\Validation\CatalogRolePermissionValidator`, `Contracts\RolePermissionValidator` | — | валидация встроена в `AccessManager` |
| `Registry\Exceptions\InvalidPermissionKeyException` | `Exceptions\UnknownPermissionException` | |
| `Permissions\PermissionKey` (константы) | `Kernel\Identity\PermissionKey` (VO) | `WILDCARD` удаляется |
| `Permissions\PermissionGrammar` | `Kernel\Grammar\PermissionGrammar` | |
| `Permissions\PermissionName` | — | `PermissionKey::from()` |
| `Permissions\CatalogKeyMatcher` | `Catalog\Ownership` (internal) | O(1) индексы |
| `Permissions\InteractsWithPanel` | — | удалить |
| `Contracts\Permission` | `Contracts\Permissions\Permission` | `ability()` → `key(): string` (локальная часть) + `#[Realm]` |
| `Panels\Panel` | `Realms\RealmBuilder` (сборка) + `Realms\Realm` (readonly) | |
| `Panels\PanelProvider` | `Realms\RealmProvider` | без policy discovery |
| `Panels\PanelResolver`, `Runtime\CurrentPanelState` | — | удалить (D05) |
| `Contracts\RoleInterface` | `Contracts\Roles\RoleDefinition` | `getName()` → `key()`, `getLevel()` → удалить (rank — в БД) |
| `Roles\BaseRole` | `Roles\CodeRole` (abstract) | `key()` по умолчанию из имени класса — **только** для генератора; хранится явно |
| `Roles\SuperAdminRole` | — | платформенная роль `*:superadmin` (D19) |
| `Roles\RolePermissionSynchronizer` | `Administration\Actions\SetRolePermissionsAction` | fingerprint → `expectedFingerprint` |
| `Roles\RolePermissionSelection`, `RolePermissionSyncResult` | `Administration\Data\PermissionSelection`, `RolePermissionsResult` | |
| `Roles\RolePermissionSyncConflictException`, `RolePermissionConnectionException` | `Exceptions\StaleSelectionException`; второе — удалить (одно соединение) | |
| `Support\RoleIdentity` | `Kernel\Identity\RoleKey` | |
| `Support\RoleSyncPlanner` | `Administration\Roles\RoleSyncPlanner` (internal) | |
| `Grants\GrantBuilder` | — | `AccessManager::issueGrant()` |
| `Models\Role` | `Persistence\Eloquent\Models\Role` | новые колонки |
| `Models\RolePermission` | `Persistence\Eloquent\Models\RolePermission` | без `panel_id` |
| `Models\DirectGrant` | `Persistence\Eloquent\Models\Grant` | + контекст, актор |
| `Models\ModelHasScope` | `Persistence\Eloquent\Models\RoleAssignment` | вместе с `model_has_roles` |
| — | `Persistence\Eloquent\Models\AuditEntry` | при `features.audit` |
| `Concerns\HasAzGuard` | `Concerns\HasAzGuard` | только чтение (D10) |
| `Concerns\HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles`, `ResolvesRole` | — | удалить |
| `Concerns\RevisionedPermissionModelWrites` | `Persistence\Eloquent\Concerns\GuardsDirectWrites` (internal) | D22 |
| `Contracts\AzGuardUser`, `HasRoles`, `HasPermissions`, `HasDirectGrants`, `HasScopedRoles` | `Contracts\AzGuardSubject` | один контракт чтения |
| `Contracts\PermissionLayer` | — | `Constraint` + политика контекстов |
| `Contracts\ContextGuard`, `ContextGrantBuilder`, `ContextGrantBuilderFactory`, `PermissionContext` | — | удалить (D09, D16) |
| `Contracts\ScopeInterface` | — | `Visibility` (D31) |
| `Contracts\AbilitiesResolver`, `Abilities\*` | — | `AzGuard::for($s)->abilities(array $keys)` |
| `Attributes\CheckPermission`, `SkipGuardCheck`, `GateAbility`, `GuardPolicy`, `RoleOnly` | — | удалить (D26, D32, D36) |
| — | `Attributes\Realm`, `Attributes\Describe` | привязка enum к realm, метаданные case |
| `Auth\BladeHelper`, `Auth\DirectGrantPolicy`, `Auth\PolicyAttributeRegistrar` | — | удалить |
| `Policies\AuthorizesPermission` | — | политики хоста вызывают `AzGuard::check()` |
| `Guard\PolicyDiscovery` | — | удалить |
| `Guard\AzGuardDiagnostics` | `Diagnostics\Doctor` + `Diagnostics\Checks\*` | расширяемые проверки |
| `Http\Middleware\CheckAccess`, `PanelCheckAccess`, `CheckDirectGrant`, `SetCurrentPanel`, `LoadAzGuardRoles` | `Laravel\Http\Middleware\Authorize` (`azguard.can`) | остальные удалить |
| `Configuration\Config` | `Configuration\AzGuardConfig` (readonly) + `Configuration\ConfigNormalizer` | |
| `Database\Schema\MorphColumns` | `Database\Schema\HostKeyColumns` (internal) | по `ids.host_keys` |
| `Database\Schema\NullSafeUniqueIndex`, `AssignmentDeduplicator` | — | только внутри upgrade-миграции 0.4, затем удалить |
| `Runtime\RequestState` | `Internal\RequestMemo` | |
| `Runtime\ScopedRoleCache` | — | удалить |
| `Scaffold\GuardScaffoldGenerator` | `Laravel\Console\Scaffold\*` | генераторы `azguard:make:*` |
| `Testing\AzGuardFake` | `Testing\AzGuardFake` | новые ассерты (§9) |
| `Testing\FakeAzGuardUser` | `Testing\FakeSubject` | |
| `Testing\FakeGrantSource` | `Testing\FakePermissionSource` | |
| `Testing\Recorded` | `Testing\RecordedCheck`, `RecordedChange` | |

## 5. Классы: context (вливается в core)

| Было | Стало |
|---|---|
| `AuthorizationContext` | `Kernel\Identity\ContextRef` (без `panelId`) |
| `AuthorizationContextManager` | `Context\CurrentContext` (scoped, одно значение) |
| `ContextGuard` | — (контекст — аргумент запроса) |
| `ContextPermissionLayer` | — (движок + `ContextPolicy`) |
| `ContextGrantBuilder`, `ContextGrantBuilderFactory` | — (`AccessManager::issueGrant(..., context:)`) |
| `Contracts\MergeStrategy`, `Strategies\GlobalPlusContextStrategy`, `ContextOnlyStrategy`, `DenyWithoutContextStrategy` | `Realms\ContextPolicy::inherit()`, `::isolated()`, `::required()` |
| `Contracts\ResolvesContext` | `Contracts\Context\ContextResolver` (`resolve(Request): ?ContextRef`, без `panelId()`) |
| `Middleware\SetAuthorizationContext` | `Laravel\Http\Middleware\ResolveContext` (`azguard.context`) |
| `Models\ContextRole` | `Persistence\Eloquent\Models\Grant` (контекстная строка) |
| `Events\ContextGrantGiven`, `ContextGrantRevoked` | `Events\GrantIssued`, `GrantRevoked` (с `context`) |
| `ContextNotSetException` | — |
| `Commands\ContextGrantCommand`, `ContextRevokeCommand` | `azguard:grants:issue|revoke --context=type:id` |
| `AzGuardContextServiceProvider` | — (модуль core) |

## 6. Классы: filament

| Было | Стало |
|---|---|
| `AzGuardPlugin::forPanel($id)` | `AzGuardPlugin::realm(string $realm)` + `manages(array $realms)` |
| `AzGuardPlugin::source('database'|'enum'|'policy')` | `source('database'|'enum')` — `policy` удаляется |
| `Resources\DirectGrantResource` | `Resources\GrantResource` |
| — | `Resources\RoleAssignmentResource` |
| `Resources\RoleResource` | `Resources\RoleResource` (без `class_name`) |
| `Permissions\ResourceGate` | `Authorization\FilamentGate` |
| `Permissions\FilamentPermissionCatalogBuilder` | `Catalog\FilamentCatalogProvider` |
| `Permissions\PageWidgetAccessEvaluator` | `Authorization\PageAccess` (fail-closed) |
| `Permissions\PolicyGenerator` | — |
| `Concerns\HasAzGuardPage`, `HasAzGuardWidget` | `Concerns\AuthorizesPage`, `AuthorizesWidget` |
| `Commands\GenerateFilamentPermissionsCommand` (`guard:filament:generate`) | `azguard:filament:generate` |

## 7. Методы: фасад и трейты

| Было | Стало |
|---|---|
| `AzGuard::registerPanel()`, `getPanels()`, `panel()` | `RealmProvider` в `azguard.realms.providers`; `AzGuard::realms()->all()`, `->get($id)` |
| `AzGuard::currentPanel()`, `setCurrentPanel()` | — |
| `AzGuard::permission($panel, $perm)` | `PermissionKey::from($perm)` |
| `AzGuard::tryPermission()`, `panelIdForPermission()` | — |
| `AzGuard::isSuperAdmin($user, $panel)` | `AzGuard::for($subject)->isSuperadmin($realm)` |
| `AzGuard::abilitiesFor($user, $panel, $keys)` | `AzGuard::for($subject)->in($context)->abilities($keys)` |
| `AzGuard::hasContextGuard()` | — |
| `AzGuard::registerGrantSource($class)` | `azguard.authorization.sources` / `AzGuard::extend()->source(key, class)` до freeze |
| `AzGuard::registerCatalogBuilder($class)` | `RealmBuilder::permissions(CatalogProvider)` / `azguard.catalog.providers` |
| `AzGuard::forUser($u)->on($p)->ttl()->grant($k)` | `AzGuard::access()->actingAs($a)->issueGrant($u, $pattern, context:, expiresAt:)` |
| `…->revoke($k)`, `->revokeAll()`, `->grants()` | `->revokeGrant($u, $pattern, context:)`, `->revokeGrants($u, realm:, context:)`, `AzGuard::for($u)->grants($realm)` |
| `…->inContext($t, $id)` | аргумент `context: ContextRef::of($t, $id)` |
| `$user->hasPermission($perm, $panel, $context)` | `$subject->hasPermission($perm, context: $context)` |
| `$user->hasPermissionIn($type, $id, $perm, $panel)` | `$subject->hasPermission($perm, context: ContextRef::of($type, $id))` |
| `$user->checkPermission(...)` | — (`hasPermission` не бросает на отказ; бросает только на ошибку конфигурации) |
| `$user->permissionSet($panel)`, `permissions($panel)` | `$subject->permissions($realm, context:)` |
| `$user->isSuperAdmin($panel)` | `$subject->isSuperadmin($realm)` |
| `$user->flushPermissions()` | — (ревизия) |
| `$user->hasRole($role)` | `$subject->hasRole(RoleKey|string, context:)` |
| `$user->assignRole/removeRole/syncRoles(...)` | `AzGuard::access()->…->assignRole/unassignRole/syncRoles($subject, …)` |
| `$user->assignScopedRole($role, $entity, $panel)` | `…->assignRole($subject, $role, context: $entity)` |
| `$user->removeScopedRole/removeScopedRoleEverywhere()` | `…->unassignRole($subject, $role, context:)` / `->unassignRole($subject, $role, context: AnyContext::all())` |
| `$user->hasScopedRole($role, $entity)` | `$subject->hasRole($role, context: $entity)` |
| `$user->hasScopedPermission($perm, $entity)` | `$subject->hasPermission($perm, context: $entity)` |
| `$user->grant/revoke($perm, $panel)`, `grants()`, `hasGrant()` | `AccessManager::issueGrant/revokeGrant`; `AzGuard::for($s)->grants($realm)`; `hasGrant` — удалить (права не различаются по источнику) |
| `$user->roles()`, `scopes()`, `directGrants()` | — (читать модели `RoleAssignment`/`Grant` или `AzGuard::for($s)->assignments()`) |
| `$user->getRoleNames()` | `AzGuard::for($s)->roles($realm)` → `list<RoleKey>` |

## 8. Конфигурация

| Было (`az-guard.*`) | Стало (`azguard.*`) |
|---|---|
| файл `az-guard.php`, `az-guard-context.php`, `az-guard-filament.php` | `azguard.php`, `azguard-filament.php` |
| `manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator` | — (seams заменены реестрами источников/constraints) |
| `models.role`, `models.role_permission`, `models.direct_grant`, `models.scope` | `models.role`, `models.role_permission`, `models.grant`, `models.role_assignment` |
| `models_namespace`, `scaffold.domain_models` | `scaffold.*` (только генераторы) |
| `table_names.*` | `database.table_prefix` (upgrade читает старую карту) |
| `column_names.morph_type` | `ids.host_keys` (`int` → `bigint`) |
| `panels` | `realms.providers` |
| `default_panel`, `strict_panels` | — |
| `require_permission_attributes` | — |
| `scope.on_missing_panel` | — (D31) |
| `middleware.check_access_alias`, `middleware.register_middleware_in_appServiceProvider` | — |
| `cache.store`, `cache.expiration_time`, `cache.generation` | `cache.store` (`array` → `null`), `cache.ttl`, `cache.generation`, `cache.state_refresh` |
| `grant_sources` | `authorization.sources` (карта ключ → FQCN) |
| `fail_on_source_exception` | — |
| `prune_expired_daily` | `schedule.prune_expired` |
| `features.wildcard_permission` | — (D18) |
| `features.teams`, `teams.foreign_key` | — (мёртвые, N20) |
| `features.audit_log` | `features.audit` (журнал изменений) + `authorization.trace_decisions` (`AccessDecided`) |
| `features.direct_grants` | `features.grants` |
| `features.validate_role_permissions` | — (всегда валидируется) |
| `az-guard-context.merge_strategy` | `ContextPolicy` на realm |
| `az-guard-context.resolvers` | `contexts.resolvers` |
| `az-guard-context.table_names.context_roles` | — (в `azg_grants`) |
| `az-guard-filament.panel` | `AzGuardPlugin::realm()` (конфиг — только fallback) |
| `az-guard-filament.user_label_column` | `azguard.subjects.directory` (`SubjectDirectory`) |
| `az-guard-filament.super_admin` | — (D19) |

## 9. Таблицы и колонки

| Было | Стало |
|---|---|
| `roles(name, class_name, level)` | `azg_roles(realm, key, label, description, origin, definition, is_superadmin, rank)` |
| `az_guard_role_permissions(role_id, permission_key, panel_id)` | `azg_role_permissions(role_id, permission)` |
| `model_has_roles(role_id, model_type, model_id)` | `azg_role_assignments(… context_key = 'global')` |
| `model_has_scopes(model_*, scope_entity_*, scope_class, role_id, panel_id)` | `azg_role_assignments(… context_key = '{type}:{id}')`; `scope_class` и `panel_id` удаляются |
| `az_direct_grants(grantable_*, permission_key, panel_id, expires_at)` | `azg_grants(subject_*, realm, permission, context_key = 'global', expires_at, …)` |
| `az_guard_context_roles(model_*, context_type, context_id, panel_id, permission_key, expires_at)` | `azg_grants(… context_key = '{type}:{id}')` |
| `az_guard_permission_state(id, revision)` | `azg_state(id, revision, schema)` |
| — | `azg_audit_log` (опционально) |

## 10. Команды, middleware, Blade, события, исключения

| Было | Стало |
|---|---|
| `guard:install` | `azguard:install` |
| `guard:doctor`, `guard:catalog:validate` | `azguard:doctor` |
| `guard:catalog`, `guard:list-permissions` | `azguard:catalog:list` |
| — | `azguard:catalog:cache`, `azguard:catalog:clear` |
| `guard:sync-roles` | `azguard:roles:sync` |
| `guard:role-permissions` | `azguard:roles:permissions` |
| `guard:role assign|detach` | `azguard:roles:assign`, `azguard:roles:unassign` |
| `guard:list-scoped-roles` | `azguard:assignments:list` |
| `guard:grant`, `guard:context:grant` | `azguard:grants:issue [--context=]` |
| `guard:revoke-grant`, `guard:context:revoke` | `azguard:grants:revoke [--context=]` |
| `guard:grants` | `azguard:grants:list` |
| `guard:prune-grants` | `azguard:assignments:prune` |
| `guard:cache-reset` | `azguard:state:reset` |
| `guard:super-admin` | `azguard:superadmin:assign` |
| `guard:explain` | `azguard:explain` |
| `guard:abilities` | `azguard:permissions:show` |
| `make:guard-panel`, `make:guard-domain`, `make:guard-permission`, `make:guard-role` | `azguard:make:realm`, `azguard:make:permissions`, `azguard:make:permissions --add`, `azguard:make:role` |
| `make:guard-policy`, `make:guard-abilities` | — |
| — | `azguard:make:constraint`, `azguard:make:source`, `azguard:upgrade` |
| `guard:filament:generate` | `azguard:filament:generate` |
| `azguard.panel`, `azguard.check`, `azguard.grant`, `azguard.panel_check`, `azguard.roles`, `check.access` | `azguard.can` (+ `azguard.context`) |
| `@azcan`, `@elseazcan`, `@unlessazcan`, `@azrole`, `@azdirect` | `@can`/`@cannot` (Gate-мост) |
| `GrantGiven` | `GrantIssued` |
| `GrantRevoked` | `GrantRevoked` (новый payload) |
| `RoleAttached`, `RoleDetached` | `RoleAssigned`, `RoleUnassigned` |
| `AccessDecision` (событие и результат explain) | `AccessDecided` (событие) + `Explanation` (результат) |
| — | `RoleCreated`, `RoleUpdated`, `RoleDeleted`, `RolePermissionsChanged`, `AssignmentExpired`, `AuthorizationStateReset`, `RoleDefinitionMissing` |
| `PanelNotSetException`, `PanelNotFoundException`, `PanelIdTooLongException` | `UnknownRealmException`, `InvalidRealmIdException` |
| `InvalidPermissionSyntaxException` | `InvalidPermissionKeyException` |
| `InvalidRoleClassException`, `InvalidRoleIdentityException` | `RoleDefinitionException`, `InvalidRoleKeyException` |
| `InvalidMorphTypeException`, `InvalidCacheConfigException`, `InvalidModelConfigException` | `InvalidConfigurationException` (коды различают) |
| `MissingPermissionAttributeException`, `ContextPackageNotInstalledException`, `IdentityIndexException` | — |

## 11. Тестовые ассерты

| Было | Стало |
|---|---|
| `AzGuardFake::assertGranted($user, $key)` | `assertGrantIssued($subject, $pattern, context:)` |
| `AzGuardFake::assertDenied($user, $key)` (проверял отзыв!) | `assertGrantRevoked($subject, $pattern, context:)` |
| — | `assertRoleAssigned`, `assertRoleUnassigned`, `assertDecided($subject, $key, Effect)` |
| `assertChecked($key)` | `assertChecked($key)` |
