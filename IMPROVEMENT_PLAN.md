# AzGuard — Execution Plan (0.2.0 → «идеальный» 0.3.0)

> **Рабочий документ. Идём фазами, сверху вниз.** Источник находок: [ARCHITECT_REVIEW.md](ARCHITECT_REVIEW.md) (81 находка, F1–F54; код-скетчи — в §4.x ревью, ссылки в фазах).
> Ветка: `refactor/integration-polish`. Режим: **`azguard-dev-loop`** (срез → tests-to-green → review-gate).

## Правила прогона

1. **Одна фаза за раз.** Скоуп срезов фазы отдаётся в `azguard-dev-loop` через `args`.
2. **Пакет ещё не используется вовне** (только локальная разработка) → **ломающие изменения делаем сразу, без deprecated-шимов**. Что поменялось — в конце фазы фиксируем в `CHANGELOG.md` затронутого под-пакета (core/filament/context). Потребители заберут при апдейте, миграция минимальна.
3. **Ритуал завершения фазы** (обязателен, иначе фаза не закрыта):
   - `composer check` зелёный (Pint `--test` · PHPStan · Rector dry-run · type-coverage `--min=98` · Pest);
   - `phpstan-baseline.neon` **не растёт** (в идеале — ужимается);
   - обновлён `CHANGELOG.md` каждого затронутого пакета;
   - один commit на фазу (Conventional Commits), **без PR**;
   - `/clear` → следующая фаза.
4. **Инварианты** (форсить в review-gate каждого среза): union-only модель прав (никакого forbid-precedence как центра) · ядро остаётся code-first / in-process / panel-centric (ReBAC/OpenFGA/DSL — только опц. grant-source) · во фронт — курированное подмножество, не весь каталог · контракт добавляем только на реальном шве (не прятать всё за интерфейсами) · `explain()` — отдельный re-run, не в hot-path `check()`. Полный список — §6 ревью.
5. **Порядок фаз обязателен** — он выстроен по зависимостям (P1 разблокирует P4/P7/P8; P3 разблокирует доки P8).

**Легенда:** Sev 🔴 critical · 🟠 high · 🟡 medium · ⚪ low. Файлы — от корня репо; `core/` = `packages/core/src/`, `fila/` = `packages/filament/src/`, `ctx/` = `packages/context/src/`.

---

## Фаза 1 — Critical shipped bugs 🔴

**Цель:** убрать отгруженные баги на рекламируемых поверхностях. Разблокирует F18, F19, F23, F37. Скетчи: §4.8, §4.9.

| ID | Sev | Действие | Файлы | AC / тест |
|---|---|---|---|---|
| F1 | 🔴 | `model_type`/`model_id` → `grantable_type`/`grantable_id` в grant-CLI; **удалить** соответствующие строки из `phpstan-baseline.neon` | `core/Commands/GrantsListCommand.php`, `core/Commands/RevokeCommand.php` | Новый Feature-тест: `grant → guard:grants выводит → guard:revoke убирает`; PHPStan зелёный без снятых baseline-строк |
| F2 | 🔴 | `AbilitiesDto::make(...)`-фабрика: `resolveFlags()` → spread именованных аргументов в конструктор сабкласса; поправить stub | `core/Abilities/AbilitiesDto.php`, `packages/core/stubs/panel/domain-abilities.stub` | Юнит: сабкласс инстанцируется `::make()`, bool-флаги резолвятся из Gate |
| F4 | 🟡 | `toArray()` отдаёт только резолвнутую bool-карту (`array_filter(get_object_vars(...), 'is_bool')`) — без утечки прочих свойств | `core/Abilities/AbilitiesDto.php` | Юнит: не-bool/приватные поля не попадают в результат |

**Commit:** `fix(core): grant-CLI columns, AbilitiesDto factory & toArray leak`

---

## Фаза 2 — Correctness & security 🟠

**Цель:** устранить тихие false-negative/positive на идиоматичных путях и утечку изоляции панелей. Скетчи: §4.4, §4.9, §4.1.

| ID | Sev | Действие | Файлы | AC / тест |
|---|---|---|---|---|
| F3 | 🟠 | `hasScopedPermission`: резолвить объявленные enum-кейсы через панель **до** `in_array` (сейчас enum-scoped-роль молча деньется) | `core/Concerns/HasScopedRoles.php` | Тест: enum-based scoped-роль реально разрешает |
| F6 | 🟠 | Facade Grants API: `panelId=null` по умолчанию → через `PanelResolver::resolveDefault` (убрать хардкод `'app'`, обходящий `default_panel`) | `core/Facades/AzGuard.php` | Тест: грант без явной панели уважает `config('az-guard.default_panel')` |
| F8 | 🟡 | Nullable `panel_id` на scoped-роли: миграция + фильтр в scoped-проверках (null = любая панель) — устранить утечку между панелями | новая миграция `core database/migrations/`, `core/Concerns/HasScopedRoles.php`, `core/Models/ModelHasScope.php` | Тест: scoped-роль панели A не даёт доступ в панели B |
| F17 | 🟡 | `DirectGrantSource` уважает `Config::directGrantsEnabled()`; модель — через `Config::directGrantModel()` (сейчас захардкожена) | `core/Registry/Sources/DirectGrantSource.php` | Тест: выключенный флаг → источник не выдаёт грантов; кастомная модель подхватывается |
| F27 | 🟡 | `class_exists`-guard в policy-catalog-builder (иначе boot-краш на отсутствующем классе); инжектить manager для паритета с GrantSource-ами | `core/Registry/Builders/PolicyAbilityCatalogBuilder.php`, `core/Registry/Builders/CompositePermissionCatalog.php` | Тест: несуществующий policy-класс не роняет boot |
| F28 | 🟡 | Учесть `PermissionDefinition::isDynamic()` в `filterAgainstCatalog` — **или** убрать метод из контракта, если не нужен | `core/Registry/Resolver/EffectivePermissionResolver.php`, `core/Registry/Contracts/PermissionDefinition.php` | Тест на выбранное поведение; контракт не содержит мёртвых методов |
| F30 | 🟡 | Per-user epoch-префикс ключа кэша; инкремент на `forgetForUser` — устранить staleness context-дискриминированных сетов при infinite-TTL | `core/Registry/Resolver/PermissionCache.php` | Тест: инвалидация пользователя сбрасывает и context-ветку |
| F48 | ⚪ | `scope_class` nullable; для logic-less ролей хранить `null`, а не sentinel анонимного класса | `core/Models/ModelHasScope.php` (+миграция при необходимости) | Тест: logic-less роль хранит `null`, резолв не падает |

**Commit:** `fix(core): scoped-role panel isolation, catalog & cache correctness`

---

## Фаза 3 — Extension API (приоритет №1) 🟠

**Цель:** превратить заявленные тезисы в реальные симметричные швы. Разблокирует доки F23/F24. Скетчи: **§4.3** (главная), §4.8.

| ID | Sev | Действие | Файлы | AC / тест |
|---|---|---|---|---|
| F5 | 🟠 | Config-ключи `az-guard.resolver` / `az-guard.manager`; биндить через них; фасад/DI смотрят на **интерфейс**, не на конкрет | `core/AzGuardServiceProvider.php`, `config/az-guard.php`, `core/Facades/AzGuard.php` | Тест: подменённый в конфиге resolver/manager реально используется |
| F7 | 🟠 | `AzGuardManager::CATALOG_BUILDERS_TAG` + `AzGuard::registerCatalogBuilder()`; заменить 3 магические строки; симметрия с `GrantSource` | `core/AzGuardManager.php`, `core/Facades/AzGuard.php`, tag-сайты Registry | Тест: кастомный catalog-builder регистрируется публичным API и участвует в резолве |
| F16 | 🟡 | Opt-in событие `AccessDecision` из `Authorizer`; сделать флаг `audit_log` честным (сейчас не читается нигде). Событие **вне** быстрого пути по умолчанию | `core/Guard/Authorizer.php`, новый `core/Events/AccessDecision.php`, `core/Support/Config.php` | Тест: при включённом флаге решение эмитит событие с ключом/панелью/вердиктом |
| F21 | 🟡 | Контракт `PermissionMatcher` (wildcard-матчинг swappable, config-ключ); мемоизировать скомпилированные паттерны | новый `core/Contracts/PermissionMatcher.php`, `core/Registry/Values/PermissionSet.php` | Тест: кастомный matcher подменяется; регэксп не рекомпилится на ключ |
| F22 | 🟡 | Грамматика wildcard: `*` → `[^.]*` (не пересекать точки); добавить рекурсивный `**`; задокументировать | `core/Registry/Values/PermissionSet.php` (или matcher из F21) | Тесты level-crossing: `a.*` не матчит `a.b.c`, `a.**` матчит |
| F37 | 🟡 | Swappable `AbilitiesResolver` + `AzGuard::abilitiesFor($user, $panel, array $keys)`; **курированный список ключей, дефолт — не `all()`** | новый `core/Abilities/AbilitiesResolver.php` (+контракт), `core/AzGuardManager.php` | Тест: проекция отдаёт только запрошенные ключи; полный каталог не утекает |
| F40 | ⚪ | `flush()` в контракт `PermissionCatalog`; ленивые panelIds (не морозить список на boot) | `core/Registry/Contracts/PermissionCatalog.php` + реализации | Тест: `flush()` сбрасывает; panelIds видят панель, зарегистрированную после boot |
| F46 | ⚪ | Opt-in swappable `saving()`-валидатор `*`-строк ролей (**default lenient**) | `core/Models/RolePermission.php` / валидатор, config-ключ | Тест: включённый валидатор ловит неизвестный ключ; выключенный — молчит |
| F47 | ⚪ | Opt-in `strict_panels` → `PanelNotFoundException` на незарегистрированной панели (default — текущее lenient) | `core/Support/PanelResolver.php`, `config/az-guard.php` | Тест: strict-режим кидает на неизвестной панели |

**Commit:** `feat(core): first-class extension surface — swappable resolver/manager/matcher, catalog-builder API, decision event, abilities projection`

---

## Фаза 4 — Typing & static analysis 🟠

**Цель:** зафиксировать SemVer-контракт и убрать спрятанные баги. Требует, чтобы F1 уже был (снятие baseline). Скетчи: §4.2, §4.1.

| ID | Sev | Действие | Файлы | AC / тест |
|---|---|---|---|---|
| F9 | 🟠 | Reparent всех Registry/panel-исключений к `AzGuardException`; arch-тест на инвариант | `core/Registry/Exceptions/*`, `core/Exceptions/*`, `tests/ArchTest.php` | Arch-тест: любое пакетное исключение `extends AzGuardException` |
| F25 | 🟠 | Привести `advanced/exceptions.md` в соответствие (становится правдой после F9) | `docs/**/advanced/exceptions.md` | Пример в доке компилируется/верен |
| F10 | 🟠 | Определить границу `@api`/`@internal` (сейчас по одному символу); PHPStan-правило: `@internal` нельзя в сигнатурах `@api` | пакетные докблоки, `phpstan.neon` | PHPStan правило активно и зелёное |
| F18 | 🟡 | `reportUnmatchedIgnoredErrors: true`; blanket Builder/Model-игноры заскоупить по путям (после фикса F1) | `phpstan.neon`, `phpstan-baseline.neon` | Нет неиспользуемых игноров; baseline ужат |
| F34 | 🟡 | `PermissionKey::normalize(string\|UnitEnum): string` — единый шов (сейчас дублируется 5+ раз) | `core/PermissionKey.php` + сайты | Юнит на normalize; дубли заменены |
| F35 | 🟡 | `list<class-string<UnitEnum>>` на Panel/enum-builder | `core/Support/Panel.php`, enum-builder | type-coverage/PHPStan зелёные |
| F36 | 🟡 | `class-string<...>` на `scopeModel()`/`directGrantModel()` (как у `roleModel()`) | `core/Support/Config.php` | PHPStan видит корректные типы |
| F38 | ⚪ | Замкнуть `Config::cacheKey()` в `keyFor()` — либо удалить мёртвый knob | `core/Support/Config.php`, `core/Registry/Resolver/PermissionCache.php` | Ключ конфигурируем **или** knob удалён; тест |

**Commit:** `refactor(core): declared @api boundary, unified key normalization, honest static-analysis baseline`

---

## Фаза 5 — Console / CLI 🟠

**Цель:** «CLI управляет всем» + машиночитаемый вывод для CI. Скетчи: §4.5.

| ID | Sev | Status | Действие | Файлы | AC / тест |
|---|---|---|---|---|---|
| F15 | 🟠 | ✅ | `guard:role:assign` / `guard:role:detach` (через `ResolvesUserModel` + `Config::roleModel()`) | новые `core/Commands/*`, `core/Commands/Concerns/*` | Feature-тест на назначение/снятие роли |
| F52 | 🟠 | ✅ | `--json`/`--format` + осмысленный exit-code для `doctor` и `catalog:validate`; общий concern `OutputsStructured` | `core/Commands/DoctorCommand.php`, `core/Commands/CatalogValidateCommand.php`, новый concern | Тест: `--format=json` даёт валидный payload; провал → ненулевой код |
| F32 | 🟡 | ✅ | Все команды через `Config::*Model()` (не хардкод); валидация ключей против каталога на add/sync | `core/Commands/*` | Тест: кастомные модели подхватываются; неизвестный ключ репортится |
| F33 | 🟡 | ✅ | `--force` для `make:guard-*`; `make:guard-role` — argument-driven (не только интерактив); общий трейт | `core/Commands/MakeGuard*Command.php` | Тест: неинтерактивная генерация с `--force` |
| F53 | 🟡 | ✅ | `guard:explain` / `guard:abilities` поверх resolver/`AbilitiesDto` (инспекция решений) | новые `core/Commands/*` | Тест: `guard:explain user perm` печатает источник вердикта |
| F51 | 🟡 | ✅ | Удалить самоссылочные мёртвые `$aliases` (3 команды); **стандартизовать префикс команд на `guard:`** (пакет не в проде — правим сразу) | `core/Commands/*`, регистрация в `AzGuardServiceProvider` | Все команды под единым префиксом; тест на регистрацию; запись в CHANGELOG |

**Commit:** `feat(core): complete CLI surface (role lifecycle, explain/abilities, structured output), unify command prefix`

---

## Фаза 6 — Filament & Context 🟠

**Цель:** закрыть неэнфорсимые права и синхронизировать кодоген с рантаймом; довести context до управляемого. Скетчи: §4.10.

| ID | Sev | Status | Действие | Файлы | AC / тест |
|---|---|---|---|---|---|
| F11 | 🟠 | ✅ | `PermissionEnumGenerator` уважает `case`/`key` конфиг (инжект `PermissionSchema`) — иначе кодоген расходится с рантаймом | `fila/Permissions/PermissionEnumGenerator.php`, `fila/Permissions/PermissionSchema.php` | Round-trip тест на не-snake кейсе: сгенерированный ключ == проверяемый |
| F13 | 🟠 | ✅ | Трейты `HasAzGuardPage::canAccess()` / `HasAzGuardWidget::canView()` — закрыть неэнфорсимые page/widget-права (или перестать эмитить + задокументировать, что нав-скрытие ≠ контроль) | новые трейты в `fila/`, каталог-эмиттер | Тест: страница без права не доступна по URL |
| F14 | 🟠 | ✅ | Context: авто-alias middleware в `boot()`; write-API `guard:context:grant`/`revoke` + builder | `ctx/AzGuardContextServiceProvider.php`, новые команды/builder в `ctx/` | Тест: middleware работает без ручного alias; гранты контекста ставятся из CLI |
| F12 | 🟠 | ✅¹ | Добавить ключ `filament.user_label_column` в конфиг (сейчас фантом на 4 сайтах чтения) | `packages/filament/config/az-guard-filament.php` | Тест: кастомный лейбл-столбец подхватывается |
| F26 | 🟡 | ✅ | `table_names.context_roles` в context-конфиг; читать оттуда (сейчас тянет несуществующий core-ключ) | `packages/context/config/az-guard-context.php` + reader | Тест: имя таблицы берётся из context-конфига |
| F29 | 🟡 | ✅ | Мемоизировать `DoctorPage::runDiagnose()` (3×/render); батч-резолв лейблов в `DirectGrantResource` (N+1) | `fila/Pages/DoctorPage.php`, `fila/Resources/DirectGrantResource.php` | Тест/бенч: `diagnose()` зовётся 1×; нет N+1 |
| F39 | 🟡 | ✅ | Дефолт `panelId` из конфига в `AzGuardPlugin::getPanelId()` (устранить `'app'`≠`'admin'`) | `fila/AzGuardPlugin.php` | Тест: плагин берёт панель из конфига |
| F41 | ⚪ | ✅ | Удалить мёртвое `MissingAuthorizationContextException` + ложный докблок в `DenyWithoutContextStrategy` | `ctx/**` | Мёртвый код удалён; тесты зелёные |

¹ Реализация 2026-07-02 была silent no-op (читала незарегистрированное дерево конфига `az-guard.filament.*`); баг найден ревью PR #91 и исправлен 2026-07-17 (commit `5d215cb`).

**Commit:** `feat(filament,context): enforce page/widget perms, sync codegen with runtime, context write-API`

---

## Фаза 7 — Cleanup, Testing & CI 🟡

**Цель:** снести мёртвый код и закрыть тестовые дыры на дифференциаторах. Скетчи: §4.1, §4.6.

| ID | Sev | Status | Действие | Файлы | AC / тест |
|---|---|---|---|---|---|
| F31 | 🟡 | ✅ | Удалить мёртвый код: `core/Guard/PanelManager.php`, `core/Grants/PendingGrant.php` (ссылается на несуществующий `GrantManager`); решить судьбу `core/Guard/DiscoveryService.php` | core src | Классы удалены; `composer check` зелёный; нет ссылок |
| F19 | 🟠 | ✅ | Feature-матрица на непокрытые CLI-команды (~15); юнит-сьют `AbilitiesDto` (после F2) | `tests/Feature/**`, `tests/Unit/Abilities/**` | Каждая команда имеет CLI-тест; abilities покрыт |
| F20 | 🟡 | ✅ | Contract-parity arch-тест на `FakeAzGuardUser`/`FakeGrantSource` | `tests/Unit/Contracts/ContractTraitParityTest.php` | Fakes проверяются на паритет с контрактами |
| F49 | ⚪ | ✅ | Arch-рэтчеты `toBeFinal()->toBeReadonly()`; параметризовать матрицы датасетами | `tests/ArchTest.php`, `tests/Unit/Filament/FilamentArchTest.php` | Arch-инварианты активны |
| F50 | 🟡 | ✅² | Infection per-package + diff-scoped PR-гейт; добавить coverage/mutation в `composer check` — реализовано; фактический прогон **deferred** (окружение без pcov/xdebug), гейт honest-skip локально, реален в CI | `infection.core/filament/context.json5`, `composer.json`, `bin/*-gate.sh`, `.github/workflows/mutation.yml` | `composer check` включает coverage/mutation-шаги (honest-skip без драйвера); CI (`mutation.yml`) реально гейтит PR diff-scoped + main advisory |

² Локальный прогон честно skip'ается (нет pcov/xdebug в dev-окружении); реальный гейт — только в CI.

**Commit:** `chore(core): remove dead code, close CLI/abilities test gaps, tighten arch & mutation gates`

---

## Фаза 8 — Docs, DX & governance 🟠

**Цель:** довести доки до состояния «люди зависят от них». P3 уже приземлил API, на который ссылаются доки. Скетчи: §4.7, §4.11.

| ID | Sev | Status | Действие | Файлы | AC |
|---|---|---|---|---|---|
| F23 | 🟠 | ✅ | Переписать `basic-usage/abilities-frontend.md` на `AbilitiesDto::make()->toArray()` + `abilitiesFor()` (F2/F37); Inertia-рецепт + типизированный `useCan()` | `docs/**/basic-usage/abilities-frontend.md`, `docs/**/recipes/inertia-permissions.md` | Примеры компилируются против реального API |
| F24 | 🟠 | ✅ | Компилируемый пример custom-catalog-builder (`SimplePermissionDefinition` + регистрация через F7) | `docs/**/advanced/extending.md` | Пример собирается; использует публичный `registerCatalogBuilder()` |
| F44 | 🟡 | ✅ | Генерировать CLI-референс из зарегистрированного списка команд; CI drift-тест; исправить таксономию префиксов (после F51) | `docs/**/basic-usage/artisan-commands.md`, CI | Референс покрывает все команды; CI ловит расхождение |
| F42 | 🟡 | ✅ | Починить RU-в-EN-дереве leak (`docs/recipes/index.md` на русском); добить integration-страницы; CI parity EN↔RU | `docs/`, `docs/ru/`, CI | Нет языковых утечек; parity-гейт зелёный |
| F45 | 🟡 | ✅ | Стандартизовать `App\Guards\` (генератор = источник истины) во всех доках | `docs/**` | Единое пространство имён в примерах |
| F43 | ⚪ | ✅ | Глобально PHP `8.2` → `8.3+` в доках; CI doc-lint против composer | `docs/**`, CI | Версии согласованы |
| F54 | 🟡 | ✅ | `.claude`-тулкит: починить путь rector-skip у `BaseRole`, перенацелить `azguard-reviewer` на существующую arch, обновить Boost-скилл до 0.2 API | `.claude/**`, `packages/core/resources/boost/skills/**` | Тулкит согласован с кодом; reviewer бьёт в реальную цель |

**Commit:** `docs: rebuild abilities/extending guides on real API, CLI reference generator, EN↔RU parity, toolkit sync`

---

## Карта зависимостей (почему такой порядок)

```
P1(F1) ─▶ P4(F18 снятие baseline)
P1(F2) ─▶ P2(F4) [взято в P1], P3(F37), P7(F19 abilities), P8(F23)
P3(F7) ─▶ P8(F24 пример регистрации)
P4(F9) ─▶ P4(F25 exceptions.md становится правдой)
P5(F51)─▶ P8(F44 таксономия префиксов)
P3(F37)─▶ P8(F23 frontend-доки)
```

## Definition of Done всего плана

- Все F1–F54 закрыты или сознательно сняты (со записью причины).
- `composer check` зелёный на каждом коммите; `phpstan-baseline.neon` ужат; type-coverage ≥98.
- Дифференциаторы (frontend-abilities, extension-API, CLI) — покрыты тестами.
- EN-доки актуальны, RU-зеркало не деградирует (CI parity).
- CHANGELOG каждого пакета отражает все ломающие изменения (без deprecated-шимов — прямые правки).
- Инварианты §6 не нарушены (проверено review-gate).

---

## Хвосты (residuals, доделать в конце плана)

> Всплыли при реализации фаз, сознательно НЕ закрыты в своём срезе (не регрессии). Собраны здесь, чтобы добить единым проходом в конце. Помечать статус по мере закрытия.

| ID | Sev | Источник | Что | Файл | Действие |
|---|---|---|---|---|---|
| T1 | 🟢 | Фаза 2 · F8 open-q + review | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P1.1) — Eloquent global query-scope теперь panel-aware, сужение строго аддитивное (D5); заодно исправлена рекурсия eager-load `scopeEntity` (D9). | `core/Concerns/HasScopedRoles.php` (`bootHasScopedRoles`) | Отдельный срез: пробросить активную панель в global scope, ИЛИ задокументировать, что query-scope не panel-bound. Тонко: у Eloquent global-scope нет «текущей панели» — нужен источник контекста. **Приоритет №1 среди хвостов (та же граница изоляции).** |
| T2 | 🟢 | Фаза 2 · F8 review [MED] | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P1.3) — `removeScopedRole(panelId=null)` теперь удаляет только any-panel строку, симметрично `assignScopedRole` (Вариант B, D10); снос «везде» — новый `removeScopedRoleEverywhere()`. Breaking change. | `core/Concerns/HasScopedRoles.php` (`removeScopedRole`, `removeScopedRoleEverywhere`) | — |
| T3 | 🟢 | Фаза 2 · F27 review [LOW] | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P2.1) — `EnumPermissionCatalogBuilder::build()` теперь логирует `Log::warning()` на missing enum-классе, паритетно policy-builder'у. | `core/Registry/Builders/EnumPermissionCatalogBuilder.php` (`build`) | — |
| T4 | 🟢 | Фаза 2 · F28 review [LOW] | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P2.2) — wildcard-off ветка `filterAgainstCatalog()` теперь дропает ключи с `WILDCARD` до dynamic-проверки, паритетно wildcard-ON ветке. | `core/Registry/Resolver/EffectivePermissionResolver.php` | — |
| T5 | 🟢 | Фаза 2 · F48 review [LOW] + open-q | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P2.3) — explicit rollback-тест подтвердил ЭКСПЕРИМЕНТОМ (не предположением из докблока), что `down()` падает `QueryException` на SQLite при наличии null-строки `scope_class`, паритетно докблоку. | `core/database/migrations/2026_01_01_000004_*` | — |
| T6 | 🟢 | Фаза 2 · F30/F30-fix open-q | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P1.2) — `add()`→`increment()`→`put()` в `forgetForUser()` теперь сериализован под `Cache::lock()`, снята гонка отката эпохи. Реальный кросс-процессный Redis-тест на гонку — за scope (array-лок доказывает наличие лок-обёртки, не физическую сериализацию); epoch по-прежнему растёт unbounded (нет reset) — вне scope T6. | `core/Registry/Resolver/PermissionCache.php` | Опц. follow-up: интеграционный тест на реальном redis; рассмотреть верхнюю границу/reset epoch. |
| T7 | ℹ️ | Фаза 2 · F3 review [INFO] | `resolveFor` пересчитывает panel/enums per-role в цикле (in-memory, НЕ N+1/DB) | `core/Concerns/HasScopedRoles.php:~213` | YAGNI — не трогать без реальной нагрузки. |

**Рекомендация:** T1 заслуживает собственного среза (та же граница изоляции панелей, что и F8) — не откладывать до самого конца, если планируется полагаться на scoped-query-filtering. T2 — продуктовое решение по семантике. T3–T7 — дешёвый батч чистки в конце.
