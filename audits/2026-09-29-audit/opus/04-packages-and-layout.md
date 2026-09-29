# 04 — Пакеты, зависимости, раскладка

Решения: [D02](02-decisions.md#d02), [D03](02-decisions.md#d03), [D12](02-decisions.md#d12).

## 1. Пакеты

| Пакет | Namespace | Требует | Назначение |
|---|---|---|---|
| `axioma-studio/azguard` | `AzGuard\` | `php ^8.3`, `illuminate/{contracts,support,database,console,http,routing,auth,cache,events}` `^11\|^12\|^13` | Движок, каталог, realm, контексты, администрирование, Laravel-адаптеры, тестовый kit |
| `axioma-studio/azguard-filament` | `AzGuard\Filament\` | `axioma-studio/azguard: self.version`, `filament/filament ^5.0` | Админ-UI поверх Administration API, каталог Filament-ресурсов |

`azguard-core`, `azguard-context` — `abandoned` → `axioma-studio/azguard` на Packagist; `replace` в новом
`composer.json` не используется (разные namespace-обязательства после канона).

```
             ┌──────────────────────┐
             │ axioma-studio/azguard│  ← host app, vaulter-azguard
             └──────────▲───────────┘
                        │ self.version
          ┌─────────────┴──────────────┐
          │ axioma-studio/azguard-     │
          │ filament                   │
          └────────────────────────────┘
```

Правила владения:

1. **Ядро** владеет схемой, миграциями, моделями, ревизией, событиями, каталогом, реестрами, всеми контрактами.
2. **Filament** не содержит доменной логики и моделей; пишет только через `AccessManager`, читает через
   публичные модели (read) и `Authorizer`; поставляет `FilamentCatalogProvider`.
3. Внешние мосты (например `vaulter-azguard`) зависят только от `@api`/`@spi` ядра.

## 2. Слои ядра и направление зависимостей

```
Laravel\ (провайдер, фасад, Gate, middleware, команды, трейт) ──┐
Filament (другой пакет) ────────────────────────────────────────┤
                                                                ▼
                       Contracts\ (@api/@spi интерфейсы)
                     ▲           ▲             ▲
        Authorization\   Administration\   Catalog\, Realms\, Context\
                     ▲           ▲             ▲
                     └───── Persistence\Eloquent\, Database\, State\
                                        ▲
                                     Kernel\  (без Illuminate, без app()/config())
```

Arch-правила (Pest arch, обязательны в CI):

| Правило | Проверка |
|---|---|
| `Kernel\` не импортирует `Illuminate\`, `Carbon\`, не вызывает `app()`, `config()`, `now()` | `arch()->expect('AzGuard\Kernel')->not->toUse(['Illuminate', 'Carbon'])` + запрет функций |
| `Contracts\` не импортирует реализации (`Authorization`, `Administration`, `Persistence`) | arch |
| `Authorization\` не импортирует `Administration\` (чтение не пишет) | arch |
| Только `Database\`, `Persistence\` используют `DB`/`Schema`/`Connection`; фасад `DB` в `src` запрещён | arch |
| `config('azguard…')` только в `Configuration\` | arch (grep-правило) |
| Production-код не импортирует `Testing\` | arch |
| `AzGuard\Filament\` импортирует из ядра только `Contracts\`, `Kernel\`, `Realms\Realm`, `Catalog\PermissionDefinition`, `Events\`, `Exceptions\`, `Persistence\Eloquent\Models\*` (read), `Facades\AzGuard` | arch по allowlist |
| Внутренние коды задач (`C-11`, `P1.4`) в docblock'ах `src` запрещены | lint |

## 3. Раскладка `packages/core/src`

```
packages/core/src/
├── AzGuardServiceProvider.php            # регистрация модулей, freeze реестров на booted
├── AzGuardManager.php                    # internal: корень фасада, без состояния
├── Facades/AzGuard.php
├── Kernel/                               # чистые значения и алгебра
│   ├── Identity/   PermissionKey, PermissionPattern, RealmId, RoleKey, SubjectRef, ContextRef,
│   │               Actor, ActorRef, IdentityCodec
│   ├── Grammar/    PermissionGrammar, PatternMatcher
│   ├── Permissions/PermissionSet
│   └── Decision/   AccessRequest, Decision, Effect, DecisionReason, DecisionSet, Contribution,
│                   ConstraintResult, StateToken, Explanation
├── Contracts/
│   ├── Authorization/  Authorizer (@api), PermissionSource (@spi), Constraint (@spi),
│   │                   SuperadminPolicy (@spi), SubjectResolver (@spi), EvaluationContext (@api)
│   ├── Administration/ AccessManager (@api), DelegationPolicy (@spi)
│   ├── Catalog/        PermissionCatalog (@api), CatalogProvider (@spi)
│   ├── Realms/         RealmRegistry (@api)
│   ├── Context/        ContextResolver (@spi), ContextMembership (@spi), ContextDirectory (@spi)
│   ├── Subjects/       SubjectDirectory (@spi)
│   ├── Permissions/    Permission (@api, для классов-прав)
│   ├── Roles/          RoleDefinition (@spi)
│   └── AzGuardSubject.php (@api)
├── Realms/            Realm, RealmBuilder, RealmProvider, RealmRegistry, ContextPolicy
├── Catalog/           Catalog, PermissionDefinition, Ownership, Providers/{Enum,Class,Config}CatalogProvider
├── Context/           CurrentContext, WithinContext
├── Authorization/     Authorizer, PermissionSetResolver, Evaluation, BatchEvaluation, Visibility,
│                      Cache/PermissionSetCache, Sources/{RolesSource,GrantsSource},
│                      Constraints/ContextMembershipConstraint, Superadmin/AssignmentSuperadminPolicy,
│                      Subjects/ModelSubjectResolver
├── Administration/    AccessManager, OperationContext, DefaultDelegationPolicy, EventRecorder,
│                      Actions/{AssignRole,UnassignRole,SyncRoles,IssueGrant,RevokeGrant,RevokeGrants,
│                               CreateRole,UpdateRole,DeleteRole,SetRolePermissions,
│                               AssignPlatformSuperadmin,PruneExpired,ResetState}Action,
│                      Data/*Input, *Result, PermissionSelection,
│                      Roles/{RoleSyncPlanner,RoleSynchronizer}
├── Database/          AzGuardDatabase, Schema/HostKeyColumns
├── Persistence/Eloquent/
│   ├── Models/        Role, RolePermission, RoleAssignment, Grant, AuditEntry
│   └── Concerns/      GuardsDirectWrites, UsesAzGuardConnection
├── State/             StateRevision, PolicyFingerprint
├── Events/            AccessEvent, EventType, RoleCreated … AuthorizationStateReset, AccessDecided
├── Exceptions/        AzGuardException + ветки (05 §9)
├── Concerns/          HasAzGuard, ContextAware
├── Attributes/        Realm, Describe
├── Permissions/       AccessPermission (enum мета-прав D23)
├── Configuration/     AzGuardConfig, ConfigNormalizer, sections/*
├── Diagnostics/       Doctor, DoctorCheck (@spi), Checks/*
├── Laravel/
│   ├── Gate/GateBridge.php
│   ├── Http/Middleware/{Authorize,ResolveContext}.php
│   └── Console/{Commands/*, Scaffold/*}
├── Testing/           InteractsWithAzGuard, AzGuardFake, FakeSubject, FakePermissionSource,
│                      RecordedCheck, RecordedChange, Contracts/*ContractTests
└── Internal/          RequestMemo, …
packages/core/database/migrations/
├── 2026_10_01_000100_create_azguard_tables.php          # fresh-схема 0.4
└── 2026_10_01_000200_upgrade_azguard_03_to_04.php       # no-op на свежей установке
packages/core/config/azguard.php
packages/core/stubs/{realm-provider,permissions-enum,role,constraint,source}.stub
```

`Roles\CodeRole` (abstract, `@api`) живёт в `Roles\` рядом с `Realms\`: это удобная база для `RoleDefinition`.

## 4. Раскладка `packages/filament/src`

```
packages/filament/src/
├── AzGuardFilamentServiceProvider.php
├── AzGuardPlugin.php                          # состояние — в экземпляре; без записи в config()
├── Authorization/{FilamentGate,PageAccess}.php
├── Catalog/{FilamentCatalogProvider,ResourceDiscovery,KeySchema}.php
├── Concerns/{AuthorizesPage,AuthorizesWidget}.php
├── Resources/
│   ├── RoleResource.php (+ Pages/, RelationManagers/RolePermissions, RoleHolders)
│   ├── RoleAssignmentResource.php (+ Pages/)
│   └── GrantResource.php (+ Pages/)
├── Forms/{SubjectPicker,ContextPicker,PermissionPicker}.php   # поверх SubjectDirectory/ContextDirectory/каталога
├── Pages/DoctorPage.php
└── Console/GenerateFilamentPermissionsCommand.php   # azguard:filament:generate (enum-source)
```

## 5. Провайдер ядра: порядок регистрации

`register()`:
1. merge `config/azguard.php`; `AzGuardConfig` (singleton, читает репозиторий конфига);
2. `AzGuardDatabase`, `IdentityCodec`, `RealmRegistry`, `Catalog`, реестры источников/constraints (singleton,
   заморозка на `booted`);
3. scoped: `CurrentContext`, `RequestMemo`, `PermissionSetCache` (request-слой), `StateRevision` (memo);
4. контракты → реализации (`Authorizer`, `AccessManager` — immutable handle factory, `DelegationPolicy`,
   `SuperadminPolicy`, `SubjectResolver`, `SubjectDirectory`, `ContextMembership` — если задан).

`boot()`:
1. `ConfigNormalizer` + валидация (исключения безопасности — во всех окружениях);
2. провайдеры realm из `azguard.realms.providers` → `RealmRegistry`;
3. миграции (`loadMigrationsFrom`), публикации (`azguard-config`, `azguard-migrations`, `azguard-stubs`);
4. `Gate::before(GateBridge)` при `azguard.gate.enabled`; alias'ы `azguard.can`, `azguard.context`;
5. планировщик (`azguard.schedule.enabled`), `about`, команды;
6. `$app->booted(fn () => freeze реестров + вычислить PolicyFingerprint)`.

Никаких слушателей Octane/Queue для «текущей панели» (понятие удалено); scoped-сервисы сбрасывает контейнер.

## 6. Сравнение с Vaulter

| Аспект | Vaulter (D02 Vaulter) | AzGuard |
|---|---|---|
| Число пакетов | 6 + metapackage | 2 |
| Точка входа Composer | `axioma-studio/vaulter` (metapackage) | `axioma-studio/azguard` |
| Ядро владеет схемой | да | да |
| Мосты без доменной логики | azgard, filament | filament |
| Зависимости между пакетами | `self.version` | `self.version` |
| Слой чистых значений | нет (Laravel-native) | `Kernel\` (алгебра прав выигрывает от детерминированных unit-тестов) |
