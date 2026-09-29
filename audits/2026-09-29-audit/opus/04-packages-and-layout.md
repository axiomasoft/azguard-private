# 04 — Пакеты, зоны, раскладка кода

Решения: [D02](02-decisions.md#d02), [D03](02-decisions.md#d03), [D12](02-decisions.md#d12), [D46](02-decisions.md#d46),
[D47](02-decisions.md#d47).

## 1. Пакеты

| Пакет | Namespace | Требует | Назначение |
|---|---|---|---|
| `axioma-studio/azguard` | `AzGuard\` | `php ^8.3`, `illuminate/*` `^11\|^12\|^13` | ядро, панели, пайплайны, хранилища, встроенные плагины (роли, прямые права, контексты, суперадмин, доступ, аудит), Laravel-адаптеры, тестовый набор |
| `axioma-studio/azguard-filament` | `AzGuard\Filament\` | `axioma-studio/azguard: self.version`, `filament/filament ^5.0` | админ-UI поверх публичного API |
| интеграции (`vaulter-azguard` и др.) | свои | `axioma-studio/azguard: ^0.4\|^1.0` | живут в своих репозиториях, опираются только на `@api`/`@spi` ([10](10-integrations.md)) |

```
      ┌──────────────── хост-приложение ─────────────────┐
      │  PanelProvider'ы, модули, свои плагины            │
      └───────┬──────────────────┬────────────────┬──────┘
              ▼                  ▼                ▼
   axioma-studio/azguard   azguard-filament   vaulter-azguard, другие интеграции
              ▲                  │                │
              └──── @api/@spi ◄──┴────────────────┘
```

## 2. Зоны ядра — что где лежит и почему

Идея «чистой архитектуры» здесь простая: **чем ближе к центру, тем меньше зависимостей**. В центре — понятия, которые
не знают ни о Laravel, ни о базе данных; снаружи — всё, что связывает их с фреймворком и UI.

```
            ┌────────────────────────────────────────────────────────────┐
            │ Laravel-адаптеры: фасад, Gate, middleware, трейт, команды  │
            │ ┌────────────────────────────────────────────────────────┐ │
            │ │ Плагины: встроенные (роли, прямые права, контексты,     │ │
            │ │ суперадмин, доступ, аудит) и внешние                    │ │
            │ │ ┌──────────────────────┐  ┌──────────────────────────┐ │ │
            │ │ │ Authorization        │  │ Administration           │ │ │
            │ │ │ пайплайн доступа     │  │ пайплайн изменений       │ │ │
            │ │ └──────────┬───────────┘  └────────────┬─────────────┘ │ │
            │ │            ▼   Panels, Catalog   ▼                      │ │
            │ │        ┌────────────────────────────┐                   │ │
            │ │        │ Kernel: ключи, ссылки,     │ ◄── Storage       │ │
            │ │        │ решение, грамматика        │     (хранилища,   │ │
            │ │        └────────────────────────────┘      модели, БД)  │ │
            │ └────────────────────────────────────────────────────────┘ │
            └────────────────────────────────────────────────────────────┘
```

| Зона | Простыми словами | Можно зависеть от | Нельзя |
|---|---|---|---|
| `Kernel\` | Словарь и арифметика прав: что такое ключ, шаблон, контекст, решение | только PHP | Laravel, Carbon, `app()`, `config()`, `now()` |
| `Contracts\` | Разъёмы: интерфейсы для вызова (`@api`) и для расширения (`@spi`) | Kernel | реализации |
| `Panels\`, `Catalog\` | Описание панелей и их каталогов прав | Kernel, Contracts | Storage, Administration |
| `Authorization\` | Как отвечать на вопрос «можно ли» | Kernel, Contracts, Panels, Catalog | Administration (чтение не пишет) |
| `Administration\` | Как менять права | всё выше + Storage | Laravel-адаптеры |
| `Storage\` | Где и как лежат данные панелей | Kernel, Contracts, Panels | Authorization, Administration |
| `Plugins\` | Готовые возможности, собранные из разъёмов | Contracts, Kernel, публичные классы зон | `Internal\` других зон |
| `Laravel\` | Перевод между Laravel и ядром | всё | — (никто не зависит от него, кроме провайдера) |
| `Testing\` | Помощники для тестов хоста, плагинов, интеграций | всё публичное | production-код не импортирует `Testing\` |

Arch-правила (Pest arch, блокирующие в CI):

| Правило |
|---|
| `Kernel\` не использует `Illuminate\`, `Carbon\`, `app()`, `config()`, `now()` |
| `Contracts\` не импортирует реализации |
| `Authorization\` не импортирует `Administration\` |
| Только `Storage\` использует `DB`, `Schema`, `Connection` и **статические запросы к моделям AzGuard**; фасад `DB` в `src` запрещён |
| Встроенные плагины (`Plugins\*`) используют только `Contracts\`, `Kernel\` и публичные классы зон — как внешний плагин (доказательство достаточности API) |
| `config('azguard…')` — только в `Configuration\` |
| Production-код не импортирует `Testing\` |
| `AzGuard\Filament\` импортирует из ядра только `@api`/`@spi` (allowlist) |
| Внутренние коды задач (`C-11`, `P1.4`) в docblock'ах `src` запрещены |

## 3. Раскладка `packages/core/src`

```
packages/core/src/
├── AzGuardServiceProvider.php          # регистрирует зоны, морозит реестры на booted
├── AzGuardManager.php                  # корень фасада, без состояния
├── Facades/AzGuard.php
├── Kernel/
│   ├── Identity/   PermissionKey, PermissionPattern, PanelId, RoleKey, SubjectRef, ContextRef,
│   │               AnyContext, Actor, ActorRef, IdentityCodec
│   ├── Grammar/    PermissionGrammar, PatternMatcher
│   ├── Permissions/PermissionSet
│   └── Decision/   AccessRequest, Decision, Effect, DecisionReason, DecisionSet, Contribution,
│                   RestrictionResult, StateToken, Explanation
├── Contracts/
│   ├── Authorization/  Authorizer, PanelAuthorizer (@api), GrantSource, Restriction, SuperadminPolicy,
│   │                   PreparesAccess, ObservesAccess, SubjectResolver (@spi), EvaluationContext (@api)
│   ├── Administration/ AccessManager (@api), DelegationPolicy, ValidatesChange, InterceptsChange,
│   │                   RecordsChange, NotifiesChange (@spi)
│   ├── Catalog/        PermissionCatalog (@api), PermissionCatalogBuilder (@spi)
│   ├── Contexts/       ContextResolver, ContextMembership, ContextDirectory (@spi)
│   ├── Subjects/       SubjectDirectory (@spi)
│   ├── Plugins/        Plugin, DependsOnPlugins, PrefixesKeys, BasePlugin (@spi)
│   ├── Roles/          RoleDefinition (@spi)
│   ├── Diagnostics/    DoctorCheck (@spi)
│   ├── Permission.php  (@api, класс-право)
│   └── AzGuardSubject.php (@api)
├── Panels/            Panel, PanelBuilder, PanelProvider, PanelRegistry, CurrentPanel, PanelSettings
├── Catalog/           PanelCatalog, PermissionDefinition, Ownership, Builders/{Enum,Class,Config}CatalogBuilder
├── Authorization/     Authorizer, PanelAuthorizer, SubjectAccess, Visibility, BatchEvaluation,
│                      Pipeline/{AccessPipeline, Stages/*}, Cache/PermissionSetCache
├── Administration/    AccessManager, Change, ChangeResult, PendingChange, PermissionSelection,
│                      Pipeline/{ChangePipeline, Stages/*},
│                      Operations/{AssignRole,RevokeRole,SyncRoles,GrantPermission,RevokePermission,
│                                  RevokePermissions,CreateRole,UpdateRole,DeleteRole,SetRolePermissions,
│                                  PruneExpired,ResetState}Operation
├── Storage/           Storage, StorageRegistry, StateRevision, Schema/HostKeyColumns,
│                      Models/{Role,RolePermission,RoleAssignment,DirectGrant},
│                      Concerns/{GuardsDirectWrites,BelongsToStorage}
├── Plugins/           # встроенные плагины — так же, как написал бы сторонний автор
│   ├── Roles/         RolesPlugin, RoleGrantSource, RoleSyncPlanner, RoleSynchronizer
│   ├── DirectGrants/  DirectGrantsPlugin, DirectGrantSource
│   ├── Contexts/      ContextsPlugin, ContextPolicy, CurrentContext, WithinContext,
│   │                  MembershipRestriction, RouteParameterResolver
│   ├── Superadmin/    SuperadminPlugin, SuperadminRole, RoleSuperadminPolicy, GlobalSuperadminPlugin
│   ├── Access/        AccessPlugin, AccessPermission (enum мета-прав), DefaultDelegationPolicy
│   └── Audit/         AuditPlugin, AuditEntry, RecordAuditEntry
├── Events/            AccessEvent, EventType, RoleAssigned … AccessStateReset, AccessDecided
├── Exceptions/
├── Concerns/          HasAzGuard, ContextAware, BelongsToPanels
├── Attributes/        Describe
├── Roles/             CodeRole
├── Configuration/     AzGuardConfig, ConfigNormalizer
├── Diagnostics/       Doctor, Checks/*
├── Laravel/
│   ├── Gate/GateBridge.php
│   ├── Http/Middleware/{UsePanel, Authorize, ResolveContext}.php
│   └── Console/{Commands/*, Scaffold/*}
├── Testing/           InteractsWithAzGuard, AzGuardFake, FakeSubject, FakeGrantSource,
│                      RecordedCheck, RecordedChange, Contracts/*ContractTests
└── Internal/          RequestMemo, …
packages/core/database/migrations/        # общее хранилище default
packages/core/stubs/                      # panel-provider, permissions-enum, role, plugin, restriction,
                                          # grant-source, panel-models, storage-migration
```

## 4. Раскладка хоста (рекомендуемая, генераторы создают её)

```
app/Authorization/
├── Panels/
│   ├── AdminPanelProvider.php
│   └── SitePanelProvider.php
├── Admin/
│   ├── Permissions/OrderPermission.php        # enum, локальные ключи
│   ├── Roles/SupportRole.php
│   ├── Models/AdminRoleAssignment.php         # своя модель панели (поля department_id, approved_by)
│   └── Restrictions/OfficeHours.php
├── Site/…
└── Plugins/ApprovalPlugin.php                 # свой плагин, подключаемый к панелям
Modules/Blog/Authorization/                    # модуль: свой плагин или своя панель
├── BlogAccessPlugin.php
└── BlogPermission.php
```

## 5. Провайдер ядра: порядок загрузки

`register()`:
1. конфиг и `AzGuardConfig`;
2. `StorageRegistry`, `PanelRegistry`, `IdentityCodec`, `Doctor` — singleton;
3. scoped: `CurrentPanel`, `CurrentContext`, `RequestMemo`, request-слой кэша, memo версий состояния;
4. контракты → реализации.

`boot()`:
1. `ConfigNormalizer` и проверки конфига;
2. регистрация `PanelProvider`'ов из конфига (провайдеры модулей регистрируются сами);
3. миграции общего хранилища, публикации (`azguard-config`, `azguard-migrations`, `azguard-stubs`);
4. `Gate::before(GateBridge)`, middleware-alias'ы, планировщик, `about`, команды;
5. `$app->booted(...)`: применить `configurePanel()`-колбэки → собрать панели (плагины `register()`) → проверить
   (хранилища, модели, каталоги, коллизии, зависимости плагинов) → заморозить → плагины `boot()` → вычислить
   отпечатки политики.

## 6. Сравнение с Vaulter — только инженерная часть

| Аспект | Vaulter | AzGuard | Комментарий |
|---|---|---|---|
| Точка входа Composer | `axioma-studio/vaulter` (metapackage) | `axioma-studio/azguard` | одинаковый опыт установки |
| Версии пакетов внутри продукта | `self.version` | `self.version` | общее правило |
| Ядро владеет схемой | да | да, по хранилищам | |
| Именованные единицы конфигурации | профили (политики для drives) | панели (независимые пространства прав) | разные предметные понятия (D43) |
| Слой чистых значений | нет | `Kernel\` | алгебра прав выигрывает от детерминированных unit-тестов |
| Расширения | реестры по ключу | плагины панели + реестры по ключу | в AzGuard расширения собираются на уровне панели |
