# REGISTER — единый реестр находок аудита P0 (2026-07-18)

> Синтез P0.6 (слой 1, fable/high). Источники: findings/P0-axis-a-integration.md (8) ·
> P0-axis-b-fluent.md (11) · P0-axis-c-correctness.md (16) · P0-axis-d-structure.md (9) —
> всего 44 находки, все без потерь. ID осей сохранены (сквозная перенумерация запрещена ТЗ).
> Severity не переоценивались: `re-rated:` — 0 случаев.
> Дедуп-проход выполнен, кросс-осевых дублей **0** — оси соблюдали cross-ref-дисциплину:
> гэп `::using()` канонически в B-02 (ось A в C-A3 только ссылается); race-тест — только
> C-05 (ось D в C-D11 ссылается); doc-DX-дефект C-A11 слит аудитором в A-05 ещё на оси;
> A-03 (порядок аргументов) и B-02 (типизированные конструкторы) — разные дефекты одного
> файла, не дубль. Строк `dup → <ID>` в реестре нет.
> Судьба — enum `P1-W0|P1-W1|P1-W2|P2|отклонено|dup`; партиция и причины —
> research/02-backlog.md (D7). Кластеры P2 — 6 предписанных ТЗ + 3 добавленных по корню
> проблемы (Testing DX, headless-порог, контрактные швы) — на подтверждение гейтом.

## Сводная статистика

### Severity × ось

| Ось | Blocker | Major | Minor | Nit | Всего |
|:--|--:|--:|--:|--:|--:|
| A (интеграция/DX) | 0 | 3 | 5 | 0 | 8 |
| B (гибкость/fluent) | 0 | 5 | 6 | 0 | 11 |
| C (корректность/безопасность) | 1 | 9 | 5 | 1 | 16 |
| D (структура/тесты) | 0 | 1 | 8 | 0 | 9 |
| **Σ** | **1** | **18** | **24** | **1** | **44** |

### Судьба

| Судьба | Счёт | Состав |
|:--|--:|:--|
| P1-W0 (Blocker) | 1 | C-01 |
| P1-W1 (Major) | 12 | A-05, B-01, B-04, C-02, C-03, C-04, C-05, C-08, C-10, C-11, C-13, D-06 |
| P1-W2 (Minor+Nit) | 14 | A-01, A-02, B-07, B-09, B-10, B-11, C-09, C-12, C-14, C-15, C-16, D-01, D-03, D-04 |
| P2 (темы/кластеры) | 14 | A-03, A-04, A-06, A-07, A-08, B-02, B-03, B-05, B-06, B-08, C-06, C-07, D-05, D-09 |
| отклонено | 3 | D-02, D-07, D-08 |
| dup | 0 | — |
| **Σ** | **44** | = 41 корзин backlog + 3 отклонённых + 0 дублей |

## Реестр

| ID | Severity | Ось | Где | Суть | Судьба |
|:--|:--|:--|:--|:--|:--|
| A-01 | Minor | A | docs/introduction/installation.md:68-74 | Доки описывают несуществующий вывод doctor, устаревшие рамки версий, обещание TS-export без носителя | P1-W2 |
| A-02 | Minor | A | docs/introduction/installation.md:43-50 | Golden path без `implements AzGuardUser` — потребитель получает нетипизированного актора | P1-W2 |
| A-03 | Minor | A | packages/core/src/Http/Middleware/CheckDirectGrant.php:35-39 | Асимметрия порядка аргументов middleware-DSL между соседними алиасами | P2: config→fluent |
| A-04 | Major | A | packages/core/src/Facades/AzGuard.php:21-41 | Нет `AzGuard::fake()` с high-level ассерциями (канон fake-фасада RAG:✅) | P2: Testing DX |
| A-05 | Major | A | docs/advanced/testing.md:140 | Глава Testing отрицает существующий testing-kit (FakeAzGuardUser/FakeGrantSource) | P1-W1 |
| A-06 | Major | A | docs/introduction/quick-start.md:34-119 | Headless-порог: первая проверка требует PanelProvider + enum + каталога + sync | P2: headless-порог |
| A-07 | Minor | A | docs/basic-usage/multiple-guards.md:7 | Термин «guard» без сущности; doc-декларация привязки panel↔auth-guard без носителя в коде | P2: словарь терминов |
| A-08 | Minor | A | docs/advanced/context.md:94 | context vs scope — два параллельных механизма entity-bound прав без маршрутизации | P2: словарь терминов |
| B-01 | Major | B | docs/advanced/configuration.md:9 | Все 5 swappable-швов не документированы; configuration.md отстал от реального конфига | P1-W1 |
| B-02 | Major | B | packages/core/src/Http/Middleware/CheckDirectGrant.php:38 | Нет статических `::using()`-конструкторов у middleware (канон spatie/Laravel RAG:✅) | P2: config→fluent |
| B-03 | Major | B | packages/context/src/ContextGrantBuilder.php:38 | Две несведённые grant-грамматики core↔context (fluent-корень и TTL только в core) | P2: grant-грамматика |
| B-04 | Major | B | packages/core/src/AzGuardManager.php:117 (карта C-B6) | Полиморфизм неоднороден: panelId без enum в трейтах/плагине, role без BackedEnum нигде | P1-W1 |
| B-05 | Minor | B | packages/core/src/Facades/AzGuard.php:27-33 | Фасад шире локуса: 2 мёртвых резолвера, 2 дубля трейта, 8/17 методов вне docs | P2: локус фасада |
| B-06 | Major | B | packages/filament/src/AzGuardPlugin.php:28-43 | Filament-плагин config-центричен против канона v5 (fluent-сеттеры, `app(static::class)`) | P2: config→fluent |
| B-07 | Minor | B | packages/core/src/Registry/Contracts/PermissionCatalog.php:55-59 | Контракт flush() не пересобирает runtime-builders (tagged-список заморожен singleton'ом) | P1-W2 |
| B-08 | Minor | B | packages/core/src/Grants/GrantBuilder.php:32-36 | Builders мутабельны и вне арх-ратчета (канон F49 для них не решён) | P2: grant-грамматика |
| B-09 | Minor | B | packages/context/src/AzGuardContextServiceProvider.php:50 | Подмена MergeStrategy через config не покрыта тестом | P1-W2 |
| B-10 | Minor | B | packages/core/config/az-guard.php:171 | Config `grant_sources` (restrict/reorder) без единого теста | P1-W2 |
| B-11 | Minor | B | packages/core/src/Facades/AzGuard.php:23-41 | @method-докблоки фасада уже реальных сигнатур менеджера (IDE скрывает enum) | P1-W2 |
| C-01 | Blocker | C | packages/core/src/Concerns/HasScopedRoles.php:50 | Global query-scope тихо отключается в console/queue-воркерах (`runningInConsole`) | P1-W0 |
| C-02 | Major | C | packages/core/src/Concerns/HasScopedRoles.php:72 | Нет strict-режима изоляции query-scope («нет панели» → применяются все строки) | P1-W1 |
| C-03 | Major | C | packages/core/src/Concerns/HasScopedRoles.php:81 | Stale scope_class тихо снимает фильтр выборки (class_exists=false → все строки) | P1-W1 |
| C-04 | Major | C | packages/core/src/Registry/Resolver/PermissionCache.php:107 | Epoch без границы; TTL=null → орфаны на персистентном сторе не умирают никогда | P1-W1 |
| C-05 | Major | C | packages/core/src/Registry/Resolver/PermissionCache.php:117 | Без LockProvider bump идёт без лока и без сигнала; кросс-процессного race-теста нет | P1-W1 |
| C-06 | Major | C | packages/core/src/Registry/Matching/WildcardPermissionMatcher.php:30 | Дефолтный matcher пересекает сегменты; флип на Hierarchical отложен на 0.4.0 | P2: wildcard `**` |
| C-07 | Minor | C | packages/core/src/Registry/Values/PermissionSet.php:129 | PermissionSet вне контейнера всегда берёт legacy-matcher (расхождение результатов) | P2: wildcard `**` |
| C-08 | Major | C | packages/filament/src/Permissions/ResourceGate.php:53 | ResourceGate возвращает false из Gate::before — жёсткий deny ломает union-only (§6) | P1-W1 |
| C-09 | Minor | C | packages/core/src/Models/DirectGrant.php:52 | Флаш кэша при update гранта только по новому panel_id — панель A не инвалидируется | P1-W2 |
| C-10 | Major | C | packages/core/src/Registry/Sources/DirectGrantSource.php:38 | Морф-типы: `$user::class` vs `getMorphClass()` — тихая потеря грантов при morph map | P1-W1 |
| C-11 | Major | C | packages/core/src/Models/Role.php:24; ModelHasScope.php:30 | class_name/scope_class в fillable — вектор эскалации mass-assignment'ом | P1-W1 |
| C-12 | Nit | C | packages/filament/src/Resources/DirectGrantResource.php:64 | LIKE-поиск не экранирует `%`/`_` | P1-W2 |
| C-13 | Major | C | packages/context/src/ContextGrantBuilder.php:84 | Context-грант `*` даёт panel-wide wildcard и минует catalog-фильтр (эскалация) | P1-W1 |
| C-14 | Minor | C | packages/core/src/AzGuardServiceProvider.php:200 | Сброс currentPanel только Octane-событием; queue-воркеры без сброса — протечка панели | P1-W2 |
| C-15 | Minor | C | packages/core/src/Events/AccessDecision.php:38 | winningSource объявлен и читается CLI, но не заполняется ни в одной ветке | P1-W2 |
| C-16 | Minor | C | packages/core/database/migrations/2026_01_01_000000_create_az_guard_tables.php:10 | Базовая миграция без down(); pivot-таблицы без PK/unique (дубли при гонке) | P1-W2 |
| D-01 | Minor | D | tests/Pest.php:32 | Битая ссылка DiscoveryTest + ручной дрейф списка биндингов (51 файл руками) | P1-W2 |
| D-02 | Minor | D | plans/…/findings/recon-test-ci-2026-07-18.md:29 | Recon-факты о тестовой базе устарели (UnitFilament живой, Unit-дерево 38 файлов) | отклонено |
| D-03 | Minor | D | tests/Unit/Contracts/ContractTraitParityTest.php:70 | Parity контракт↔трейт проверяется только в одну сторону («mirror 1:1» не enforced) | P1-W2 |
| D-04 | Minor | D | tests/ArchTest.php:21 | Arch-инвариант «contracts are interfaces» не покрывает Registry\Contracts (6 шт.) | P1-W2 |
| D-05 | Minor | D | packages/core/src/Support/Config.php:36 | Support/ — catch-all из шести разных ролей (9 файлов, @api/@internal вперемешку) | P2: Support/-разбор |
| D-06 | Major | D | composer.json:56 | Канонический `composer test` падает OOM локально (128M, лимит не поднят скриптом) | P1-W1 |
| D-07 | Minor | D | composer.json:18 | Paratest не подключён — сьют ~549 тестов одним процессом | отклонено |
| D-08 | Minor | D | infection.core.json5:7 | Mutation-excludes широкой кистью (все Commands); асимметрия Pages coverage↔mutation | отклонено |
| D-09 | Minor | D | phpstan-baseline.neon:172 | 6 baseline-записей легализуют вызовы мимо объявленных контрактов на реальных швах | P2: контрактные швы |

## Трассировка (Validation P0.6)

- Ось A: 8 находок в файле оси (`grep -c '^### A-'`) == 8 строк реестра (`grep -c '^| A-'`).
- Ось B: 11 == 11 · Ось C: 16 == 16 · Ось D: 9 == 9.
- Каждая строка несёт Severity из enum (Blocker|Major|Minor|Nit) и судьбу из enum
  (`P1-W0|P1-W1|P1-W2|P2|отклонено|dup`).
- 44 (реестр) == 41 (корзины P1/P2 backlog) + 3 (отклонено) + 0 (dup).
