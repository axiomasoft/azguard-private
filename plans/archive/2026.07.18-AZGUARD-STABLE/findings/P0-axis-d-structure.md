# P0 ось D — качество / доменная структура / тестовые дыры (2026-07-18)

> Слой 1. Аудитор: fable-5/subagent (P0.5). Read-only аудит по чеклисту phases/P0.md §P0.5.

## Чеклист

| C# | Проверка | Вердикт | Якоря | Заметка |
|:--|:--|:--|:--|:--|
| C-D1 | Support/ — перечень, классификация, кандидаты-приёмники | fail | packages/core/src/Support/Config.php:36; packages/core/src/Support/Panel.php:12 | 9 файлов, 6 разных ролей в одной папке — таблица ниже; находка D-05 |
| C-D2 | Корневые файлы неймспейса — на месте ли каждый | partial | packages/core/src/PanelProvider.php:19; packages/core/src/PermissionKey.php:5 | Manager+SP — канон; PanelProvider и PermissionKey — кандидаты доменов (см. заметку C-D2) |
| C-D3 | Registry-субдомен: границы, вложенные Contracts/Exceptions | partial | packages/core/src/Registry/Contracts/GrantSource.php:1; tests/ArchTest.php:21 | Единственный субдомен с двумя «домами» контрактов/исключений; arch-инвариант не покрывает Registry\Contracts (D-04) |
| C-D4 | Parity Has*-контракт↔трейт — полнота ContractTraitParityTest | partial | tests/Unit/Contracts/ContractTraitParityTest.php:70 | Все 4 пары + AzGuardUser + 2 Fakes покрыты, но проверка однонаправленная (D-03) |
| C-D5 | Мёртвый testsuite UnitFilament (каталога нет) | pass | — | Предпосылка ОПРОВЕРГНУТА: tests/Unit/Filament существует, 8 тест-файлов, suite живой; recon-test-ci устарел (D-02) |
| C-D6 | Битая ссылка Feature/DiscoveryTest.php в tests/Pest.php | fail | tests/Pest.php:32 | Файл удалён коммитом 4f9a835 (F31), ссылка осталась — тихий no-op (D-01) |
| C-D7 | phpstan-baseline 35 записей: классификация | partial | phpstan-baseline.neon:22; phpstan-baseline.neon:172 | Корзины: снимаемые сейчас 17 + структурные 6 + легитимные 12 = 35 (см. §Корзины; D-09) |
| C-D8 | OOM голого `composer test` локально | fail | composer.json:56 | Причина: php CLI memory_limit=128M (herd-lite) × ~549 тестов одним процессом; скрипт не поднимает лимит; обход `php -d memory_limit=1G vendor/bin/pest` (D-06) |
| C-D9 | Отсутствие paratest | fail | composer.json:18 | В require-dev нет brianium/paratest — параллельного прогона нет; вход P4, здесь не решается (D-07) |
| C-D10 | Coverage/mutation-исключения — обоснованность | partial | phpunit.xml:41; infection.filament.json5:9 | Resources-exclude обоснован (комментарий phpunit.xml:39–40); mutation-excludes — широкая кисть + асимметрия Pages (D-08) |
| C-D11 | Прямые тесты на 5 корректность-чувствительных якорей | pass | — | Все 5 закрыты прямыми unit-тестами (см. §C-D11); кросс-процессный Redis race — гэп оси C, не дублируется |
| C-D12 | Docs EN/RU parity — гейты и долги зеркала | pass | — | 44 EN ↔ 44 RU, diff пуст (проверено); гейты bin/docs-parity-gate.sh + docs.yml в CI; 05_AI и README.md исключены by design (docs-parity-gate.sh:5–7) |

Счётчики: pass 3 · fail 4 · partial 5 · n/a 0.

## C-D1 — таблица Support/ (9 файлов, вкл. Schema/)

| Файл | LOC | Классификация | Кандидат-приёмник (вход P2) |
|:--|--:|:--|:--|
| BladeHelper.php | 13 | хелпер (Blade auth-check) | к месту регистрации директив (SP/View-домен) или Abilities/ |
| Config.php | 320 | типизированный config-аксессор (инфраструктура; 23 файла-потребителя) | собственный дом Configuration/ либо корень AzGuard\ |
| Panel.php | 154 | VO / fluent-builder (@api) | Panels/ — вместе с PanelResolver, PanelProvider, Panel*Exception |
| PanelResolver.php | 106 | резолвер panel-id | Panels/ |
| PermissionName.php | 55 | резолвер permission-ключа | Permissions/ — рядом с PermissionKey |
| RequestState.php | 54 | request-scoped стейт (@internal, Octane-safe) | Runtime/ (state-инфраструктура) |
| ResolvesGateAbilities.php | 29 | трейт-хелпер Gate (потребители: AbilitiesDto, SP) | Abilities/ |
| ScopedRoleCache.php | 38 | request-scoped кэш (@internal) | Runtime/ либо домен scoped-ролей (рядом с HasScopedRoles) |
| Schema/MorphColumns.php | 48 | схемный хелпер миграций | Database/Schema/ |

Заметка C-D2: AzGuardManager.php (259 LOC) и AzGuardServiceProvider.php (366 LOC) в корне —
канон Laravel-пакета, на месте. PanelProvider.php — базовый класс панель-провайдеров,
логичный сосед Panels/-домена (сейчас его VO Panel живёт в Support/). PermissionKey.php —
VO грамматики ключей в корне при существующем Permissions/ (в котором один трейт
InteractsWithPanel) — оба кандидаты переезда, решение за P2.

## C-D7 — корзины phpstan-baseline (сумма 35 записей)

- **Снимаемые сейчас (17)** — локальные фиксы без структурных сдвигов: instanceof.alwaysTrue ×5
  (phpstan-baseline.neon:10,16,28,100,142), instanceof/identical.alwaysFalse ×2 (:34,:106),
  function.alreadyNarrowedType ×2 (:4,:112), nullsafe.neverNull ×3 (:40,:46,:178),
  arrayValues.list ×1 (:118), missingType.iterableValue ×4 (:166,:196,:202,:208).
- **Структурные, уйдут с P2 (6)** — контрактные несостыковки шва: argument.type
  Authorizer::check Authenticatable≠Authorizable (:22), method.notFound ServiceProvider::panel()
  (:52), property.notFound Authenticatable::$roles (:148), method.notFound
  PermissionDefinition::label() ×2 (:172,:184), method.notFound Model::dbPermissions() (:190).
- **Легитимные (12)** — природа пакета: trait.unused ×10 (:58–:163 — трейты для потребителей,
  вне analysis paths phpstan.neon:8), generics ScopeInterface::apply Builder-TModel (:94),
  morphedByMany template в Role (:124).

## C-D11 — прямые unit-тесты корректность-чувствительных якорей

| Якорь | Прямой тест | Вердикт |
|:--|:--|:--|
| Concerns/HasScopedRoles | tests/Unit/Concerns/HasAzGuardScopedRolesTest.php (assign/has/remove/idempotent/wildcard) | pass |
| Registry/Resolver/PermissionCache | tests/Unit/Registry/PermissionCacheTest.php (epoch-ключ, фиксированный префикс F38) | pass |
| Registry/Resolver/EffectivePermissionResolver | tests/Unit/Registry/EffectivePermissionResolverTest.php | pass |
| Registry/Values/PermissionSet | tests/Unit/Registry/PermissionSetTest.php | pass |
| Registry/Contracts/PermissionCatalog | tests/Unit/Registry/CompositePermissionCatalogTest.php (+EnumPermissionCatalogBuilderTest) | pass |

Cross-ref: свойства гонок/изоляции (кросс-процессный Redis race по T6) — вопрос оси C
(P0-axis-c-correctness), здесь фиксируется только наличие прямых тестов юнитов.

## Находки

### D-01 — Битая ссылка и ручной дрейф списка биндингов в tests/Pest.php

- **Severity:** Minor
- **Чек:** C-D6
- **Где:** tests/Pest.php:32
- **Суть:** `Feature/DiscoveryTest.php` удалён (коммит 4f9a835, F31), ссылка в `uses()->in()` осталась — Pest молча игнорирует несуществующий путь. Список из 51 файла ведётся руками и дрейфует: `CliReferenceDriftTest.php` и `CommandPrefixRegistrationTest.php` не привязаны ни к одному TestCase (работают только потому, что чисто рефлексивные).
- **Рекомендация:** убрать мёртвую ссылку; рассмотреть биндинг по каталогу с точечными исключениями вместо перечисления файлов.

### D-02 — recon-test-ci содержит устаревшие факты о тестовой базе

- **Severity:** Minor
- **Чек:** C-D5
- **Где:** plans/2026.07.18-AZGUARD-STABLE/findings/recon-test-ci-2026-07-18.md:29
- **Суть:** Recon утверждает «каталога tests/Unit/Filament НЕТ (мёртвый suite)» и «tests/Unit — 4 файла»; фактически Unit-дерево — 38 файлов в 13 подкаталогах, tests/Unit/Filament существует (8 файлов), suite UnitFilament живой. Синтез P0.6 на этих фактах даст ложные находки.
- **Рекомендация:** при синтезе P0.6 опираться на настоящий файл, не на recon §1 «Дыры» (актуальной из трёх заявленных дыр осталась только ссылка DiscoveryTest — D-01).

### D-03 — ContractTraitParityTest проверяет parity только в одну сторону

- **Severity:** Minor
- **Чек:** C-D4
- **Где:** tests/Unit/Contracts/ContractTraitParityTest.php:70
- **Суть:** Тест ассертит «каждый метод контракта есть в трейте», но не обратное: публичный метод, добавленный в трейт без контракта, дрейфует незаметно — потребитель, типизирующийся на контракт, его не увидит (заявленное «mirror 1:1» не enforced).
- **Рекомендация:** дополнить обратной проверкой (public-методы трейта ⊆ контракт, с explicit-допуском внутренних хелперов).

### D-04 — Arch-инвариант «contracts are interfaces» не покрывает Registry\Contracts

- **Severity:** Minor
- **Чек:** C-D3
- **Где:** tests/ArchTest.php:21
- **Суть:** Ожидание навешано только на `AzGuard\Contracts`; 6 интерфейсов `AzGuard\Registry\Contracts` (GrantSource, PermissionCatalog…) вне инварианта — класс, добавленный туда, CI не поймает.
- **Рекомендация:** расширить expectation на оба неймспейса (или устранить двойной «дом» контрактов в P2 — тогда инвариант сойдётся сам).

### D-05 — Support/ — catch-all из шести разных ролей

- **Severity:** Minor
- **Чек:** C-D1
- **Где:** packages/core/src/Support/Config.php:36
- **Суть:** В одной папке живут VO (@api Panel), два резолвера, два request-scoped стейта (@internal), config-аксессор, Blade/Gate-хелперы и схемный хелпер миграций — граница «Support = всё остальное» не несёт смысла и размывает @api/@internal-границу по соседству.
- **Рекомендация:** фактура для P2 — таблица кандидатов-приёмников выше (§C-D1); здесь не проектируется.

### D-06 — Канонический `composer test` падает OOM на референсном локальном окружении

- **Severity:** Major
- **Чек:** C-D8
- **Где:** composer.json:56
- **Суть:** Скрипт `"test": "vendor/bin/pest"` не поднимает memory_limit; на локальном php CLI (128M) полный сьют ~549 тестов одним процессом падает OOM (подтверждено в TAILS P1 handoff), при этом правила проекта предписывают гонять именно `composer test` — канонический dev-loop сломан из коробки, все обходятся недокументированным `php -d memory_limit=1G vendor/bin/pest`.
- **Рекомендация:** зафиксировать лимит в самом скрипте (`php -d memory_limit=1G vendor/bin/pest`) — симметрично уже сделанному для analyse (`--memory-limit=1G`).

### D-07 — Параллельного прогона нет: paratest не подключён

- **Severity:** Minor
- **Чек:** C-D9
- **Где:** composer.json:18
- **Суть:** В require-dev нет brianium/paratest — сьют ~549 тестов идёт одним процессом, что удлиняет цикл и усугубляет D-06 (память копится в одном процессе).
- **Рекомендация:** вход P4 (там же docker/БД-матрица и race-тесты) — здесь только фиксация.

### D-08 — Mutation-excludes широкой кистью и асимметрия с coverage

- **Severity:** Minor
- **Чек:** C-D10
- **Где:** infection.core.json5:7; infection.filament.json5:9
- **Суть:** Все Commands трёх пакетов (22 core-команды + по одной в filament/context) целиком вне mutation — логика, не вынесенная из команд, не измеряется; filament Pages (DoctorPage) исключён из mutation, но входит в coverage-source (phpunit.xml:38–43 исключает только Resources) — асимметрия критериев. Обоснованы: Facades (docblock-обёртки), Resources (декларативный UI, зеркально исключён из coverage).
- **Рекомендация:** при P4 mutation-ratchet пересмотреть Commands-exclude (вынести логику или сузить exclude); выровнять Pages между coverage и mutation.

### D-09 — Шесть baseline-записей маскируют реальные несостыковки контрактов

- **Severity:** Minor
- **Чек:** C-D7
- **Где:** phpstan-baseline.neon:172
- **Суть:** Корзина «структурные» (6 записей — `PermissionDefinition::label()` ×2, `Model::dbPermissions()`, `Authenticatable::$roles`, Authorizable/Authenticatable в Authorizer, `ServiceProvider::panel()`) — не шум, а места, где код зовёт методы/свойства мимо объявленного контракта; baseline легализует обход типовой границы на реальных швах.
- **Рекомендация:** трактовать эти 6 как вход P2 (уточнение контрактов/generics), остальные 17 снимаемых — кандидаты быстрой волны P1.
