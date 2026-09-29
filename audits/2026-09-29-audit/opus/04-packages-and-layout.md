# 04 — Пакеты, зоны, раскладка кода

Решения: [D02](02-decisions.md#d02), [D03](02-decisions.md#d03), [D12](02-decisions.md#d12), [D46](02-decisions.md#d46),
[D47](02-decisions.md#d47), [D52](02-decisions.md#d52)–[D55](02-decisions.md#d55).

## 1. Пакеты

| Пакет | Namespace | Требует | Назначение |
|---|---|---|---|
| `axiomasoft/azguard` | `AzGuard\` | `php ^8.3`, `illuminate/*` `^11\|^12\|^13` | ядро, панели, механики (код, политики, БД, связи), пайплайны, схема, хранилища, Laravel-слой, тестовый набор |
| `axiomasoft/azguard-filament` | `AzGuard\Filament\` | `axiomasoft/azguard: self.version`, `filament/filament ^5.0` | редакторы ролей и выдач по схеме панели |
| интеграции (мост Vaulter и др.) | свои | `axiomasoft/azguard: ^1.0` | живут в своих репозиториях, опираются только на `@api`/`@spi` ([10](10-integrations.md)) |

```
      ┌────────────────── приложение ──────────────────┐
      │  PanelProvider'ы, enum прав, роли, политики,    │
      │  свои механики, хуки, модули                    │
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
            │ Laravel: фасад, трейт, Gate, middleware, команды              │
            │ ┌──────────────────────────────────────────────────────────┐ │
            │ │ Плагины: встроенные механики (code, policies, database,    │ │
            │ │ relations), аудит; внешние плагины                         │ │
            │ │ ┌──────────────┐ ┌──────────┐ ┌─────────┐ ┌──────────────┐ │ │
            │ │ │Authorization │ │ Changes  │ │ Schema  │ │ Sources,     │ │ │
            │ │ │пайплайн      │ │ пайплайн │ │ описание│ │ Policies     │ │ │
            │ │ │проверки      │ │ изменений│ │ панели  │ │ механики     │ │ │
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
| `Contracts\` | Разъёмы: `@api` (вызывать) и `@spi` (реализовывать) | Kernel | реализации |
| `Panels\`, `Catalog\`, `Contexts\` | Описание панелей, их прав, правило выбора панели, политика сущностей | Kernel, Contracts | Storage, Changes |
| `Sources\` | Механики, дающие права: код, БД, связи | Kernel, Contracts, Panels, Storage (только чтение) | Changes, Authorization |
| `Policies\` | Вызов Laravel Policy и Gate как механики решения | Kernel, Contracts, Panels | Storage, Changes |
| `Authorization\` | Как отвечать «можно ли» | Kernel, Contracts, Panels, Catalog, Contexts | Changes (проверка не пишет) |
| `Changes\` | Как менять права | всё выше + Storage | Laravel-слой |
| `Schema\` | Описание панели для интерфейсов | Kernel, Contracts, Panels, Catalog | Storage, Changes |
| `Storage\` | Где и как лежат данные панелей | Kernel, Contracts, Panels | Authorization, Changes |
| `Plugins\` | Упаковка механик и возможностей | Contracts, Kernel, публичные классы зон | `Internal\` других зон |
| `Laravel\` | Перевод между Laravel и ядром | всё | — (от него зависит только провайдер) |
| `Testing\` | Помощники для тестов приложения, плагинов, интеграций | всё публичное | production-код не импортирует `Testing\` |

Arch-правила (Pest arch, блокирующие в CI):

| Правило |
|---|
| `Kernel\` не использует `Illuminate\`, `Carbon\`, `app()`, `config()`, `now()` |
| `Contracts\` не импортирует реализации |
| `Authorization\` и `Schema\` не импортируют `Changes\` |
| Только `Storage\` использует `DB`, `Schema`, `Connection` и статические запросы к моделям AzGuard; `Sources\` читает через `Storage` |
| Встроенные механики (`Sources\*`, `Policies\*`) и плагины используют только `Contracts\`, `Kernel\` и публичные классы зон — как внешний автор |
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
│   └── Decision/    AccessRequest, Decision, Effect, DecisionReason, DecisionSet, Contribution,
│                    RestrictionResult, StateToken, Explanation
├── Contracts/
│   ├── PanelAccess.php, AzGuardSubject.php, Permission.php          (@api)
│   ├── Panels/        PanelRegistry (@api)
│   ├── Catalog/       PermissionCatalog (@api), PermissionCatalogBuilder (@spi)
│   ├── Sources/       GrantSource, Volatility, SourceDescription (@spi)
│   ├── Authorization/ Restriction, SuperAdminRule (@spi), EvaluationContext (@api)
│   ├── Hooks/         BeforeHook, AfterHook, ChangingHook, ChangedHook (@spi)
│   ├── Contexts/      ContextResolver, ContextMembership, ContextDirectory, ProvidesContext (@spi)
│   ├── Subjects/      SubjectResolver, SubjectDirectory (@spi)
│   ├── Changes/       RoleManager (@api)
│   ├── Plugins/       Plugin, DependsOnPlugins, PrefixesKeys (@spi)
│   └── Diagnostics/   DoctorCheck (@spi)
├── Panels/            Panel, PanelBuilder, PanelProvider, PanelRegistry, PanelResolver, CurrentPanel, PanelSettings
├── Catalog/           PanelCatalog, PermissionDefinition, Builders/{Enum,Class}CatalogBuilder
├── Contexts/          ContextPolicy, CurrentContext, WithinContext, MembershipRestriction,
│                      RouteParameterResolver, ContextAware (trait)
├── Sources/
│   ├── Code/          CodeSource (grantToAll, роли из кода, автоматические роли)
│   ├── Database/      DatabaseSource (роли из БД, назначения, прямые права)
│   └── Relations/     RelationSource, RelationBinding
├── Policies/          Decides, ConsultsGrants, PolicyBindings, PolicyDiscovery, PolicyDecider, GateDecider
├── Roles/             CodeRole, AssignedAutomatically, SuperAdminRole
├── Authorization/     Authorizer, SubjectAccess, SubjectPanels, Visibility, BatchEvaluation,
│                      Pipeline/{AccessPipeline, Stages/*}, Cache/PermissionSetCache
├── Changes/           Change, AppliedChange, ChangeResult, ChangePipeline, RoleManager, Operations/*
├── Schema/            PanelSchema, PermissionSchema, RoleSchema, FieldSchema, ContextTypeSchema,
│                      SubjectTypeSchema, Field, SchemaBuilder
├── Storage/           Storage, StorageRegistry, PanelState, Schema/HostKeyColumns,
│                      Models/{Role,RolePermission,RoleAssignment,DirectPermission},
│                      Concerns/{GuardsDirectWrites,BelongsToStorage}
├── Plugins/           BasePlugin, Builtin/{CodePlugin,PoliciesPlugin,DatabasePlugin,RelationsPlugin},
│                      Audit/{AuditPlugin, AuditEntry}
├── Events/            AccessEvent, EventType, RoleAssigned … PanelStateTouched, AccessDecided
├── Exceptions/
├── Concerns/          HasAzGuard, BelongsToPanels
├── Attributes/        Describe
├── Configuration/     AzGuardConfig
├── Diagnostics/       Doctor, Checks/*
├── Laravel/
│   ├── Gate/GateBridge.php
│   ├── Http/Middleware/{EnterPanel, Authorize}.php
│   └── Console/{Commands/*, Scaffold/*}
├── Testing/           InteractsWithAzGuard, AzGuardFake, FakeSubject, FakeGrantSource,
│                      RecordedCheck, RecordedChange, Contracts/*ContractTests
└── Internal/          RequestMemo, …
packages/core/database/migrations/        # общее хранилище default
packages/core/stubs/                      # panel-provider, permissions-enum, code-role, policy, plugin,
                                          # grant-source, restriction, hook, panel-models, storage-migration
```

## 4. Раскладка приложения (рекомендуемая; генераторы создают её)

```
app/Authorization/
├── Panels/
│   ├── CabinetPanelProvider.php
│   ├── SellerPanelProvider.php
│   └── AdminPanelProvider.php
├── Cabinet/
│   ├── CabinetPermission.php                 # enum, локальные имена
│   └── Policies/OrderPolicy.php              # методы с #[Decides]
├── Seller/
│   ├── SellerPermission.php
│   └── Roles/SellerRole.php                  # автоматическая роль
├── Admin/
│   ├── AdminPermission.php
│   ├── Roles/ManagerRole.php
│   ├── Models/AdminRoleAssignment.php        # своя модель: department_id, weekdays
│   └── Restrictions/WeekdaysRestriction.php
├── Hooks/NoEscalation.php                    # рецепт «не больше своего»
└── Plugins/…
Modules/Blog/Authorization/                   # модуль: своя панель или плагин в чужую
├── BlogAccessPlugin.php
└── BlogPermission.php
```

## 5. Провайдер ядра: порядок загрузки

`register()`:
1. конфиг и `AzGuardConfig`;
2. `StorageRegistry`, `PanelRegistry`, `IdentityCodec`, `Doctor` — singleton;
3. scoped: `CurrentPanel`, `CurrentContext`, `RequestMemo`, кэш запроса, память версий;
4. контракты → реализации.

`boot()`:
1. проверки конфига;
2. регистрация `PanelProvider`'ов из конфига (провайдеры модулей регистрируются сами);
3. миграции общего хранилища, публикации (`azguard-config`, `azguard-migrations`, `azguard-stubs`);
4. `Gate::before(GateBridge)`, middleware-alias'ы, планировщик, `about`, команды;
5. `$app->booted(...)`: `configurePanels()` и `configurePanel()` → сборка панелей (`register()` плагинов, привязки
   политик, связи) → проверки (хранилища, модели, каталоги, коллизии, зависимости, панели по умолчанию) → заморозка →
   `boot()` плагинов → отпечатки панелей.

## 6. Сравнение с Vaulter — только инженерная часть

| Аспект | Vaulter | AzGuard | Комментарий |
|---|---|---|---|
| Точка входа Composer | `axioma-studio/vaulter` (metapackage) | `axiomasoft/azguard` | vendor различается ([Q23](15-owner-questions.md)) |
| Версии пакетов внутри продукта | `self.version` | `self.version` | общее правило |
| Ядро владеет схемой | да | да, по хранилищам | |
| Именованные единицы конфигурации | профили (политики для drives) | панели (конструкторы прав) | разные предметные понятия (D43) |
| Драйверы | драйвер прав на профиль | механики прав на панель, в любом сочетании | у AzGuard механики складываются |
| Слой чистых значений | нет | `Kernel\` | алгебра прав выигрывает от детерминированных unit-тестов |
| Расширения | реестры по ключу | плагины панели + реестры по ключу | в AzGuard расширения собираются на уровне панели |
