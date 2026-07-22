# Recon: публичная API-поверхность и долги azguard (2026-07-18)

> Слой 1. Отчёт Explore-субагента (read-only разведка репо azguard). Вердикт: repo-grounded.

Статус базы: все F1–F54 из IMPROVEMENT_PLAN.md закрыты; план 2026.07.17-AZGUARD-TAILS
(T1–T7) закрыт целиком; ACTIVE был «—». Версии: core php ^8.3, Laravel ^11|^12|^13;
пакеты 0.2.0 (path-repo), vendor `axioma-studio/azguard-*`.

## 1. Публичная API-поверхность

**core:**
- Фасад `AzGuard\Facades\AzGuard` — 18 @method (панели, permission/tryPermission,
  registerGrantSource/registerCatalogBuilder, isSuperAdmin, abilitiesFor,
  hasContextGuard, forUser/grant/revoke/grants; grant-методы с `?string $panelId`).
- Контракты `src/Contracts/` — 20 интерфейсов (AzGuardUser, AzGuardManagerInterface,
  Has*-двойники трейтов, Permission*, RoleInterface, ScopeInterface, ContextGuard,
  AbilitiesResolver…) + `src/Registry/Contracts/` — 7 (GrantSource, GrantPriority,
  PermissionCatalog(Builder), PermissionDefinition, PermissionMeta).
- Testing: FakeAzGuardUser, FakeGrantSource (@api).
- Config az-guard.php: manager/resolver/matcher/abilities_resolver/
  role_permission_validator (все swappable), models, table/column_names, panels,
  strict_panels, middleware, cache, grant_sources, features, teams.
- 22 artisan-команды (префикс guard: унифицирован), 5 events, 5 attributes,
  5 миграций, 5 middleware-алиасов.

**filament:** AzGuardPlugin, RoleResource/DirectGrantResource, DoctorPage, opt-in
трейты HasAzGuardPage/HasAzGuardWidget + PageWidgetAccessEvaluator, ResourceGate через
Gate::before, GenerateFilamentPermissionsCommand; config az-guard-filament.php.

**context:** AuthorizationContext(Manager), ContextGuard, ContextPermissionLayer,
ContextGrantBuilder, ContextRole; MergeStrategy (3 стратегии, дефолт
GlobalPlusContextStrategy), ResolvesContext; middleware azguard.context; 2 команды.

**Граница @api/@internal:** ЕСТЬ — @api на всех контрактах, PermissionSet,
Support/Panel, PermissionKey, фасаде, Testing/*; @internal на PolicyDiscovery,
DefaultAbilitiesResolver, ScopedRoleCache, RequestState, EffectivePermissionResolver,
PermissionCache. Enforced reflection-тестом `tests/Unit/ApiBoundaryTest.php` (2 теста).

## 2. Интеграционная поверхность

Трейт-агрегат Concerns/HasAzGuard (композит 4 трейтов) + контракт AzGuardUser;
5+1 middleware-алиасов; Gate-интеграция Guard/Authorizer, policy autodiscovery
#[GateAbility]+PolicyDiscovery; Filament-панели (плагин, enforcement); frontend
abilities — AbilitiesDto::make(), abilitiesFor (курированный список),
docs/recipes/inertia-permissions.md; context-пакет для multi-workspace.

**Слабые места / оговорки:**
- В src/ НЕТ TODO/FIXME/@deprecated — маркеров незавершённости нет.
- T1 закрыт строго аддитивно (D5 TAILS): cross-panel изоляция query-scope НЕ действует
  при неустановленной панели.
- T6: epoch-bump сериализован Cache::lock(), но epoch растёт unbounded (нет reset);
  реальный кросс-процессный Redis race-тест — незакрытый follow-up.
- T7 (resolveFor пересчёт per-role в цикле) закрыт как YAGNI без кода.
- INTEGRATION_FEEDBACK: п.4 (headless-путь) и п.8 (id/morph) закрыты только
  документацией; п.2 снят осознанно; п.18 «под вопросом».

## 3. Известные долги по документам

- ARCHITECT_REVIEW.md (F1–F54): отложено на 1.0/breaking (deprecate-first): F4
  (narrowing toArray), F22 (wildcard grammar `**`), F40 (flush() в PermissionCatalog),
  F51 (rename префикса). §6 «What NOT to Do» — 12 анти-паттернов-инвариантов
  (union-only, code-first/in-process core, курированный frontend, контракты только на
  реальных швах, explain() вне hot-path).
- REMAINDER_REPORT.md: follow-ups (не блокеры): Redis race-тест (T6), верхняя
  граница/reset epoch; удаление публичного InvalidCatalogException (breaking);
  OOM голого `composer test` локально.
- TAILS D-лог: D5 аддитивный query-scope; D9 фикс рекурсии eager-load scopeEntity;
  D10/D11 breaking-семантика removeScopedRole. open-questions Q1 Resolved (вариант B).
- docs/ VitePress EN + полное RU-зеркало, parity-гейты в CI.

## 4. Доменная структура packages/core/src

18 подпапок + 4 корневых файла (AzGuardManager, AzGuardServiceProvider, PanelProvider,
PermissionKey). God-классов нет (максимум 367 LOC). Registry — вложенный субдомен со
своими Contracts/Exceptions. Контракт-трейт дублирование имён Has* — осознанный паттерн
(ContractTraitParityTest). **Перекос:** Support/ — слой «всё остальное» (кэши, стейт,
резолверы, хелперы, VO Panel, Schema) — смешанный набор. Arch-инварианты enforced в
tests/ArchTest.php (+FilamentArchTest, ContractTraitParityTest).

## 5. Якоря

Фасад: packages/core/src/Facades/AzGuard.php · Контракты: packages/core/src/Contracts/,
packages/core/src/Registry/Contracts/ · Testing: packages/core/src/Testing/ · Гейты
границы: tests/Unit/ApiBoundaryTest.php, tests/ArchTest.php,
tests/Unit/Contracts/ContractTraitParityTest.php · Конфиги: packages/*/config/*.php ·
SP: packages/*/src/*ServiceProvider.php · Интеграция: packages/core/src/Concerns/,
Guard/Authorizer.php, Abilities/AbilitiesDto.php, Http/Middleware/,
packages/filament/src/AzGuardPlugin.php, packages/context/src/ContextGrantBuilder.php ·
Долги: ARCHITECT_REVIEW.md, IMPROVEMENT_PLAN.md, INTEGRATION_FEEDBACK.md,
REMAINDER_REPORT.md · Корректность-чувствительные: Concerns/HasScopedRoles.php,
Registry/Resolver/PermissionCache.php, Registry/Resolver/EffectivePermissionResolver.php,
Registry/Values/PermissionSet.php, Registry/Contracts/PermissionCatalog.php
