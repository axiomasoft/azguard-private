# 04 — Пакеты, зоны, раскладка кода

Решения: [D02](02-decisions.md#d02), [D03](02-decisions.md#d03), [D12](02-decisions.md#d12), [D46](02-decisions.md#d46),
[D47](02-decisions.md#d47), [D52](02-decisions.md#d52)–[D58](02-decisions.md#d58).

## 1. Пакеты

| Пакет | Namespace | Требует | Назначение |
|---|---|---|---|
| `axiomasoft/azguard` | `AzGuard\` | `php ^8.3`, `illuminate/*` `^11\|^12\|^13` | ядро, панели, источники (папка панели, БД, связи, Gate) и их фабрика, пайплайны, схема, хранилища, Laravel-слой, тестовый набор |
| `axiomasoft/azguard-filament` | `AzGuard\Filament\` | `axiomasoft/azguard: self.version`, `filament/filament ^5.0` | редакторы ролей и выдач по схеме панели |
| интеграции (мост Vaulter и др.) | свои | `axiomasoft/azguard: ^1.0` | живут в своих репозиториях, опираются только на `@api`/`@spi` ([10](10-integrations.md)) |

```
      ┌────────────────── приложение ──────────────────┐
      │  папки панелей: провайдеры, enum, роли,         │
      │  политики, свои источники, pipes, модули        │
      └───────┬──────────────────┬─────────────────┬────┘
              ▼                  ▼                 ▼
   axiomasoft/azguard   azguard-filament   мост Vaulter, другие интеграции
              ▲                  │                 │
              └──── @api/@spi ◄──┴─────────────────┘
```

## 2. Зоны ядра — что где лежит и почему

Идея чистой архитектуры здесь простая: **чем ближе к центру, тем меньше зависимостей**. В центре — понятия, которые не
знают ни о Laravel, ни о базе данных. Снаружи — всё, что связывает их с фреймворком и интерфейсами.

```
            ┌──────────────────────────────────────────────────────────────┐
            │ Laravel: фасад, трейт, Gate, middleware, атрибуты, команды    │
            │ ┌──────────────────────────────────────────────────────────┐ │
            │ │ Плагины: готовые наборы для панелей (аудит, модули,        │ │
            │ │ интеграции)                                                │ │
            │ │ ┌──────────────┐ ┌──────────┐ ┌─────────┐ ┌──────────────┐ │ │
            │ │ │Authorization │ │ Changes  │ │ Schema  │ │ Sources,     │ │ │
            │ │ │пайплайн      │ │ пайплайн │ │ описание│ │ Policies     │ │ │
            │ │ │проверки      │ │ изменений│ │ панели  │ │ фабрика      │ │ │
            │ │ └──────┬───────┘ └────┬─────┘ └────┬────┘ └──────┬───────┘ │ │
            │ │        ▼  Panels, Catalog, Contexts ▼             ▼         │ │
            │ │          ┌────────────────────────────┐                     │ │
            │ │          │ Kernel: имена, ссылки,     │ ◄── Storage         │ │
            │ │          │ решение, грамматика        │     (хранилища, БД) │ │
            │ │          └────────────────────────────┘                     │ │
            │ └──────────────────────────────────────────────────────────┘ │
            └──────────────────────────────────────────────────────────────┘
```

| Зона | Простыми словами | Можно зависеть от | Нельзя |
|---|---|---|---|
| `Kernel\` | Словарь и арифметика прав: имя, шаблон, сущность, решение | только PHP | Laravel, Carbon, `app()`, `config()`, `now()` |
| `Contracts\` | Pure protocols и явно Laravel-facing adapters (Contexts/Subject/UI/Plugin/Change inputs) | Kernel + публичные immutable definitions; Laravel-facing — Model/Request/Builder | orchestration implementations |
| `Panels\`, `Catalog\`, `Contexts\` | Описание панелей, их прав, правило выбора панели, политика сущностей | Kernel, Contracts | Storage, Changes |
| `Sources\` | Источники и их фабрика: папка панели (автопоиск), БД, связи, Gate | Kernel, Contracts, Panels; `Database\` — ещё Storage | Changes, Authorization |
| `Policies\` | Атрибуты и вызов политик доменов — второй уровень | Kernel, Contracts, Panels | Storage, Changes |
| `Authorization\` | Как отвечать «можно ли» | Kernel, Contracts, Panels, Catalog, Contexts | Changes (проверка не пишет) |
| `Changes\` | Как менять права | всё выше + Storage | Laravel-слой |
| `Schema\` | Описание панели для интерфейсов | Kernel, Contracts, Panels, Catalog | Storage, Changes |
| `Storage\` | Где и как лежат данные панелей | Kernel, Contracts, Panels | Authorization, Changes |
| `Plugins\` | Упаковка источников, хуков, полей для панелей | Contracts, Kernel, публичные классы зон | `Internal\` других зон |
| `Laravel\` | Перевод между Laravel и ядром | всё | — (от него зависит только провайдер) |
| `Testing\` | Помощники для тестов приложения, плагинов, интеграций | всё публичное | production-код не импортирует `Testing\` |

Arch-правила (Pest arch, блокирующие в CI):

| Правило |
|---|
| `Kernel\` не использует `Illuminate\`, `Carbon\`, `app()`, `config()`, `now()` |
| `Contracts\` не импортирует реализации |
| `Authorization\` и `Schema\` не импортируют `Changes\` |
| Только `Storage\` использует `DB`, `Schema`, `Connection` и статические запросы к моделям AzGuard; из источников `Storage\` использует только `Sources\Database\` |
| Источники/плагины используют @api/@spi; DatabaseSource может использовать Storage как внутренний built-in adapter, это явное исключение. Внешние источники не импортируют внутренний Storage |
| Писать выдачи может только `StoresGrants`, и только из `Changes\ChangePipeline` |
| Все входы выбирают панель только через `Panels\PanelResolver` |
| `config('azguard…')` — только в `Configuration\` |
| Production-код не импортирует `Testing\` |
| `AzGuard\Filament\` импортирует из ядра только `@api`/`@spi` |
| Внутренние коды задач (`C-11`, `P1.4`) в docblock'ах `src` запрещены |

## 3. Раскладка `packages/core/src`

```
packages/core/src/
├── AzGuardServiceProvider.php          # регистрирует зоны, морозит реестры на booted
├── AzGuardManager.php                  # корень фасада, без состояния
├── Facades/AzGuard.php
├── Kernel/
│   ├── Identity/    PermissionKey, PermissionPattern, RoleKey, SubjectRef, ContextRef, AnyContext, ActorRef, IdentityCodec
│   ├── Grammar/     PermissionGrammar, PatternMatcher
│   ├── Permissions/ PermissionSet
│   └── Decision/    AccessRequest, Decision, Effect, DecisionReason, DecisionSet, Grant,
│                    RestrictionResult, BeforeResult, PermissionAuthority, CodeStateToken, StateToken, Explanation
├── Contracts/
│   ├── PanelAccess.php, AzGuardSubject.php                             (@api)
│   ├── Panels/        PanelRegistry (@api)
│   ├── Catalog/       PermissionCatalog (@api)
│   ├── Sources/       Source, ProvidesPermissions, ProvidesRoles, ProvidesGrants, ProvidesPolicies,
│   │                  StoresGrants, FiltersQueries, DescribesSchema, ChecksHealth, Volatility,
│   │                  PermissionDefinition, RoleDefinition, PolicyBinding, SourceDescription (@spi)
│   ├── Authorization/ Restriction (@spi), EvaluationContext (@api)
│   ├── Contexts/      ContextResolver, ContextMembership, ContextDirectory, ProvidesContext (@spi)
│   ├── Subjects/      SubjectResolver, SubjectDirectory (@spi)
│   ├── Changes/       GrantManager, PermissionManager; Roles/RoleCatalog read-only (@api)
│   ├── Plugins/       Plugin, DependsOnPlugins, PrefixesKeys (@spi)
│   └── Diagnostics/   DoctorCheck (@spi)
├── Panels/            Panel, PanelBuilder, PanelProvider, PanelRegistry, PanelResolver, CurrentPanel, PanelSettings
├── Catalog/           PanelCatalog (статичная часть из источников + динамическая с версией)
├── Contexts/          ContextPolicy, CurrentContext, WithinContext, MembershipRestriction,
│                      RouteParameterResolver, ContextAware (trait)
├── Sources/
│   ├── SourceManager.php               # Illuminate\Support\Manager: имена → источники; AsSource
│   ├── Folder/        FolderSource, PanelDiscovery (папка провайдера: */Permissions/, */Policies/, Roles/ — D56)
│   ├── Database/      DatabaseSource (классы ролей, назначения и дополнительные динамические права, выдачи, запись, свои модели)
│   ├── Relation/      RelationSource, RelationBinding
│   └── Gate/          GateSource
├── Permissions/       Resource, Describe, RequiresGrant, PolicyOnly, GrantedToAll (атрибуты enum прав)
├── Policies/          PolicyFor, Decides, PolicyDecider
├── Roles/             BaseRole, GrantedAutomatically, SuperAdminRole, Attributes/{Role,SuperAdmin,NotGrantable,FormerKeys}
├── Authorization/     Authorizer, SubjectAccess, SubjectPanels, Visibility, BatchEvaluation,
│                      Pipeline/{AccessPipeline, Stages/*}, Cache/PermissionSetCache
├── Changes/           Change, ChangeResult, ChangePipeline (Illuminate\Pipeline), GrantManager, PermissionManager, Operations/*
├── Schema/            PanelSchema, PermissionSchema, RoleSchema, FieldSchema, ContextTypeSchema,
│                      SubjectTypeSchema, Field, SchemaBuilder
├── Storage/           Storage, StorageRegistry, PanelState, Schema/HostKeyColumns,
│                      Models/{RoleGrant,PermissionGrant,Permission},
│                      Concerns/{GuardsDirectWrites,BelongsToStorage}      # используется только DatabaseSource
├── Plugins/           BasePlugin, Audit/{AuditPlugin, AuditEntry}
├── Events/            AccessEvent, EventType, RoleGranted … PanelStateTouched, AccessDecided
├── Exceptions/
├── Concerns/          HasAzGuard, BelongsToPanels
├── Attributes/        CheckPermission (extends Laravel #[Middleware]), SkipPermissionCheck, AsSource
├── Configuration/     AzGuardConfig
├── Diagnostics/       Doctor, Checks/*
├── Laravel/
│   ├── Gate/GateBridge.php
│   ├── Http/Middleware/{EnterPanel, CheckPermission}.php     # azguard.panel, azguard.can
│   └── Console/{Commands/*, Scaffold/*}
├── Testing/           InteractsWithAzGuard, AzGuardFake, FakeSubject, FakeSource,
│                      RecordedCheck, RecordedChange, Contracts/*ContractTests
└── Internal/          RequestMemo, …
packages/core/database/migrations/        # общее хранилище default
packages/core/stubs/                      # panel-provider, permission, policy, abilities, role, source, plugin,
                                          # restriction, change-pipe, panel-models, storage-migration
```

Имена папок пакета совпадают с именами папок приложения там, где это одно понятие: `Permissions/`, `Policies/`,
`Roles/`, `Sources/`, `Plugins/`. Кто открыл пакет, узнаёт в нём структуру своей панели.

## 4. Раскладка приложения: панель — папка

Целевая структура утверждена в [D72](02-decisions.md#d72); правила обнаружения — [D56](02-decisions.md#d56). Генераторы 1.0 создают её, текущие генераторы 0.3 ещё требуют перевода.
Папка панели — каталог её провайдера; всё, что относится к панели, лежит внутри; enum прав, политики и роли панель
находит сама. Общее для нескольких панелей — в `Shared/`.

```
app/Guards/
├── Cabinet/
│   ├── CabinetGuardPanelProvider.php
│   ├── Roles/ProjectEditorRole.php
│   ├── Contexts/ProjectContext.php
│   ├── Permissions/
│   │   ├── Orders/OrderPermission.php
│   │   └── Profile/ProfilePermission.php
│   └── Policies/Orders/OrderPolicy.php
├── Seller/
│   ├── SellerGuardPanelProvider.php
│   ├── Roles/SellerRole.php
│   └── Permissions/{Orders,Products}/…
├── Admin/
│   ├── AdminGuardPanelProvider.php
│   ├── Permissions/{Orders,Users}/…
│   ├── Policies/Orders/OrderPolicy.php
│   ├── Abilities/Orders/OrderAbilities.php
│   ├── Queries/Orders/OrderVisibility.php
│   ├── Roles/{SuperAdmin,Manager}Role.php
│   ├── Contexts/ProjectContext.php
│   ├── Sources/LdapSource.php
│   ├── Restrictions/{AccountLocked,TokenAbilities}Restriction.php
│   ├── Changes/{RequireReason,AuthorizeAccessChange}.php
│   └── Models/AdminRoleGrant.php
└── Shared/
    ├── Roles/RootRole.php
    ├── Sources/LdapSource.php
    └── Plugins/AuditTrailPlugin.php
Modules/Blog/Guards/
├── Permissions/Posts/PostPermission.php
├── Policies/Posts/PostPolicy.php
└── BlogAccessPlugin.php
```

| Папка | Что внутри | Как попадает в панель |
|---|---|---|
| `{Panel}GuardPanelProvider.php` | описание панели | `config('azguard.panels')` или `AzGuard::registerPanel()` |
| `Roles/` | статичные роли | автопоиск (`FolderSource`) |
| `Permissions/{Group}/` | enum прав домена | автопоиск |
| `Policies/{Group}/` | политика — второй уровень | pairing по D56 либо явный PolicyFor/Decides |
| `Abilities/{Group}/` | DTO прав для фронтенда | автопоиск |
| `Contexts/` | классы ContextDefinition (ProjectContext), на них ссылаются роли | автопоиск, явный выбор ContextPolicy |
| `Resolvers/` | tenant и resource scope adapters | явно: tenantResolvers/resourceScopes |
| `Queries/{Group}/` | парная query semantics policy | явно: FiltersAccessQueries adapter |
| `Sources/` | свои источники | явно: `->permissions([...])` (порядок и настройки важны); имя из `#[AsSource]` |
| `Restrictions/` | ограничения | явно: `->restrictions([...])` |
| `Changes/` | pipes изменений | явно: `->changing([...])` |
| `Models/` | свои модели выдач | явно: `DatabaseSource::make()->models(...)` |
| `Plugins/` | плагины панели | явно: `->plugins([...])` |
| `Shared/` | общее для панелей | явно в провайдерах или `AzGuard::configurePanels()`; `#[AsSource]` регистрируется сам |

## 5. Провайдер ядра: порядок загрузки

`register()`:
1. конфиг и `AzGuardConfig`;
2. `StorageRegistry`, `PanelRegistry`, `IdentityCodec`, `Doctor` — singleton;
3. scoped: `CurrentPanel`, `CurrentTenant`, `CurrentContext`, runtime sources с request dependencies, `RequestMemo`, кэш запроса, память версий;
4. контракты → реализации.

`boot()`:
1. проверки конфига;
2. регистрация `PanelProvider`'ов из конфига (провайдеры модулей регистрируются сами);
3. миграции общего хранилища, публикации (`azguard-config`, `azguard-migrations`, `azguard-stubs`);
4. `Gate::before(GateBridge)`, middleware-alias'ы, планировщик, команды;
5. `$app->booted(...)`: `configurePanels()` и `configurePanel()` → сборка панелей (`register()` плагинов, источники:
   `FolderSource` первым, затем Source/name элементы `->permissions([...])` через `SourceManager`; enum элементы входят в FolderSource) → проверки (хранилища, модели, каталоги,
   коллизии, писатель, зависимости, панели по умолчанию) → заморозка → `boot()` плагинов → отпечатки панелей;
6. `optimizes(optimize: 'azguard:catalog:cache', clear: 'azguard:catalog:clear')` и раздел в `php artisan about`.

## 6. Сравнение с Vaulter — только инженерная часть

| Аспект | Vaulter | AzGuard | Комментарий |
|---|---|---|---|
| Точка входа Composer | `axioma-studio/vaulter` (metapackage) → `axiomasoft/vaulter` | `axiomasoft/azguard` | единый vendor `axiomasoft` (Q23); переход Vaulter — его задача |
| Версии пакетов внутри продукта | `self.version` | `self.version` | общее правило |
| Ядро владеет схемой | да | да, по хранилищам | |
| Именованные единицы конфигурации | профили (политики для drives) | панели (конструкторы прав) | разные предметные понятия (D43) |
| Драйверы | драйвер прав на профиль | источники на панель, в любом сочетании, через `Manager` Laravel | у AzGuard источники складываются |
| Слой чистых значений | нет | `Kernel\` | алгебра прав выигрывает от детерминированных unit-тестов |
| Расширения | реестры по ключу | плагины панели + реестры по ключу | в AzGuard расширения собираются на уровне панели |


## 7. Границы пятого прохода

TenantRef/AccessScope/RoleContribution/AccessPredicate — pure Kernel values. ContextDefinition/ResourceScopeResolver,
Directories и BaseContext — Laravel-facing SPI/adapter, аналогично существующим model subjects.
При выборе namespace arch-правила проверяют реальную dependency closure: Panel readonly value допустим в SPI,
ChangePipeline/Storage implementation — нет. Sources\Database — инфраструктурное исключение, не обещание,
что Eloquent-писатель стороннего storage поддержан в 1.0.

StoresGrants::apply принимает уже validated Change и пишет под transaction; orchestration/pipes/events owns Changes.
Он не вызывает обратно ChangePipeline: иначе текущие стрелки Sources -> Changes -> Sources образуют цикл.
SourceManager хранит factories, runtime instances со scoped зависимостями создаёт execution scope.
Version-specific controller attribute adapter не extends missing Laravel13 class на Laravel11/12.
