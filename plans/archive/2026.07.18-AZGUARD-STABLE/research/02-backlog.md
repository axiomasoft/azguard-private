# 02 — Ранжированный бэклог ремедиации (выход P0.6, D7; 2026-07-18)

> Слой 2. Вход детализации волатильных фаз P1 (ремедиация) и P2 (канон/fluent) по D3.
> Источник: findings/REGISTER.md (44 находки, дедуп 0, re-rated 0). Партиция бинарна и
> тотальна: каждый ID ровно в одной корзине. Правило партиции (ТЗ P0.6): локальный фикс
> без переименований/изменения грамматики API → P1; переименование, структурный сдвиг
> или редизайн API → P2. Зависимости — `после <ID>`. Статус: **утверждён гейтом владельца
> 2026-07-18** (brief/01-refinements.md, блок «Гейт P0.6»; plan.md §5 D9).

## P1 — волны ремедиации

### W0 — Blocker (первым, до всего остального)

| ID | Суть фикса (направление) | Зависимости |
|:--|:--|:--|
| C-01 | Явный контракт console/queue для query-scope: config-флаг/сужение условия до реального artisan-CLI вместо безусловного `runningInConsole`-bypass | — |

### W1 — Major (12; порядок внутри волны — security → cache → docs/DX)

| ID | Суть фикса (направление) | Зависимости |
|:--|:--|:--|
| C-13 | Валидация/запрет `*` и wildcard-паттернов в context-грантах либо catalog-фильтр для layer-wildcard | — |
| C-11 | class_name/scope_class вывести из fillable (явные сеттеры/guarded) | — |
| C-10 | Единый шов морф-резолюции: `getMorphClass()` везде + тест с enforceMorphMap | — |
| C-08 | ResourceGate: `true|null` вместо `false` из Gate::before (или документированный enforcement-режим с opt-out) | — |
| C-02 | Strict-режим изоляции query-scope (падение/пустая выборка при отсутствии панели — по выбору потребителя) | после C-01 |
| C-03 | Warning + `guard:doctor`-проверка на stale scope_class (null ≠ «класс пропал») | после C-01 |
| C-04 | Граница/реюз epoch или запрет TTL=null при персистентном сторе (валидация конфига) | — |
| C-05 | Warning (однократный) на сторе без LockProvider; кросс-процессный race-тест — вход P4 | — |
| B-04 | Выровнять границу `string|BackedEnum` на всех входах panelId/role по карте C-B6 (аддитивное расширение, unwrap на границе) | — |
| B-01 | Секция extending.md «Swapping core services» (5 швов) + синхронизация configuration.md с фактическим конфигом | — |
| A-05 | Глава Testing: убрать ложное «no fake/mock layer», перенести testing-kit из recipes, поднять Testing до first-class | — |
| D-06 | `composer test` → `php -d memory_limit=1G vendor/bin/pest` (симметрично analyse) | — |

### W2 — Minor + Nit (14)

| ID | Суть фикса (направление) | Зависимости |
|:--|:--|:--|
| A-01 | Синхронизировать примеры вывода doctor/список чеков/версии; убрать или реализовать обещание TS-export | — |
| A-02 | `implements AzGuardUser` в сниппетах install/quick-start | — |
| B-07 | Сузить докблок flush() либо ленивый сбор builders (как panels()) | — |
| B-09 | Swap-тест кастомной MergeStrategy через config (образец ExtensionSwapTest) | — |
| B-10 | Feature-тест restrict/reorder `grant_sources` | — |
| B-11 | Синхронизировать @method-типы фасада с сигнатурами AzGuardManagerInterface | после B-04 |
| C-09 | Флаш кэша и по `getOriginal('panel_id')`/original grantable при update гранта | — |
| C-12 | Экранировать LIKE-метасимволы в поиске DirectGrantResource | — |
| C-14 | Листенер JobProcessing (или документированный контракт «панель в jobs ставится явно»); Octane-тест RequestState — вход P4 | после C-01 |
| C-15 | Заполнять winningSource в Authorizer::explain() (либо решение об удалении поля — тогда P2) | — |
| C-16 | Новая миграция: down() для базовой, PK/unique на model_has_roles и model_has_scopes | — |
| D-01 | Убрать мёртвую ссылку в tests/Pest.php; биндинг по каталогу вместо перечисления | — |
| D-03 | Обратная проверка parity (public-методы трейта ⊆ контракт, explicit-допуски) | — |
| D-04 | Расширить arch-expectation на Registry\Contracts (если P2 сольёт дома контрактов — инвариант сойдётся сам) | — |

## P2 — темы (кластеры по корню проблемы)

Предписанные ТЗ 6 кластеров + 3 добавленных по фактуре осей (материал не помещается в
предписанные без искажения корня; подтверждение — гейт).

| Кластер | Находки | Корень / что проектируется |
|:--|:--|:--|
| 1. Словарь терминов | A-07, A-08 | 4 термина при 3 сущностях: guard=бренд vs panel; маршрутизация/унификация нарратива context↔scope |
| 2. Локус фасада | B-05 | Сужение фасада к оркестровому входу (2 мёртвых резолвера, дубли трейта); классификация C-B4 готова; исполнение cut-line — P3 |
| 3. Grant-грамматика | B-03, B-08 | Единый fluent-корень core↔context, TTL-парность (или «context-грант бессрочен by design»); канон builders (immutable-with vs мутабельный) + арх-ратчет |
| 4. Config→fluent | B-06, B-02, A-03 | Поведение из config в fluent-сеттеры плагина (`make()` через контейнер); типизированные `::using(string|BackedEnum)` у middleware; единый порядок аргументов alias-DSL |
| 5. Support/-разбор | D-05 | Роспуск catch-all по таблице C-D1 (9 файлов → Panels/, Permissions/, Runtime/, Abilities/, Database/Schema/) |
| 6. Wildcard `**` | C-06, C-07 | Флип дефолта на Hierarchical пре-1.0 (вердикт C-C4: делать сейчас), legacy — opt-out; C-07 (fallback вне контейнера) — после C-06 |
| 7. Testing DX (добавлен) | A-04 | `AzGuard::fake()` — Recorder-паттерн + assertGranted/assertDenied/assertChecked (+closure), канон RAG:✅ |
| 8. Headless-порог (добавлен) | A-06 | Минимальный panel-less/lenient путь или «minimal setup» quick-start (открытый follow-up INTEGRATION_FEEDBACK п.4) |
| 9. Контрактные швы (добавлен) | D-09 | 6 структурных baseline-записей → уточнение контрактов/generics на реальных швах (Authorizer, PermissionDefinition::label, dbPermissions) |

## Отклонено (3; подтверждение — гейт)

| ID | Причина |
|:--|:--|
| D-02 | Не дефект продукта: recon-файл историчен, не правится задним числом; синтез уже опирается на файлы осей (риск зафиксирован в handoff Open risks) |
| D-07 | Отклонено из бэклога P1/P2 по существу корзины: paratest — штатный предмет фазы P4 (скелет уже несёт параллельные прогоны); дублирование в P1/P2 создало бы второй дом работы |
| D-08 | Аналогично D-07: пересмотр mutation-excludes/выравнивание Pages — предмет P4 mutation-ratchet |

## Хвосты в P4 (аннотации, не корзины)

Не находки-корзины, а тестовые части находок P1, уже входящие в объявленный скоуп P4:
кросс-процессный Redis race-тест (из C-05) · Octane-тест RequestState (из C-14) ·
пересмотр Commands-exclude при mutation-ratchet (контекст D-08). Детализация P4 обязана
их подобрать (Coupling D6).

## Сводка партиции

P1: W0=1 · W1=12 · W2=14 (27 находок). P2: 9 кластеров, 14 находок. Отклонено: 3.
Дубли: 0. Итого 44/44 — партиция тотальна. Blocker в «отклонено» нет (правило ТЗ).
