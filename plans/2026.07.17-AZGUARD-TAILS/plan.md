# 2026.07.17-AZGUARD-TAILS — Закрыть хвосты T1-T7 (REMAINDER_REPORT.md)

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.07.17-AZGUARD-TAILS |
| Title | AzGuard: закрыть хвосты T1-T7 (panel-aware query-scope, epoch race, семантика removeScopedRole, диагностика/wildcard/rollback) |
| Version | 0.7.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | opus |
| Last Updated | 2026-07-18 (фаза P2 закрыта) |
| Repository | /home/vostrikov/projects/packages/azguard |
| Related Packages | core |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · haiku (LOW) · opus (design/audit) |
| Approval Owner | Dmitry Vostrikov |
| Design Passes | 1/1 — 2 фазы, 7 items (≤3 фаз/≤10 items → 1 прогон по SKILL §5) |

## 1. Context

PR #91 (2026-07-17) закрыл Фазы 5-8 `IMPROVEMENT_PLAN.md` (F15-F54) и смержен в `main`
(`bd3026f`). При его собственном ревью найдены и честно задокументированы в
`REMAINDER_REPORT.md` семь незакрытых «хвостов» (T1-T7) — расхождений между кодом и
докблоками/докой, не входивших в scope PR #91. Этот план закрывает их.

Наивысший приоритет — **T1**: Eloquent global query-scope в `HasScopedRoles.php`
(`bootHasScopedRoles`) не учитывает `panel_id` при фильтрации результатов запроса —
только permission-**check**-путь (`hasScopedPermission`) уже изолирован по панели (F8).
Второй по значимости — **T6**: более ранний фикс TTL кеша эпох (`e3e33c3`) сам внёс новую
гонку `increment()`/`put()`. T2 — продуктовое решение по семантике, вынесено в
`open-questions.md`. T3-T5 — независимые дешёвые правки. T7 закрывается решением
(YAGNI), без кода.

## 2. Execution Rules

- Один item за раз (кроме P2 — задекларирована оркестрация, workflow-скрипт).
- Статусы — 6 канонических строк байт-в-байт (plan-protocol §6).
- Commit-гейт закрытия item'а — plan-protocol §8 (item-commit → bookkeeping-commit);
  ДО item-commit зелёные РОВНО эти три: `composer test` · `vendor/bin/pint --test` ·
  `vendor/bin/phpstan analyse`. Скрипта `validate.sh` в этом репо НЕТ — `bin/` несёт только
  `coverage-gate.sh`/`docs-parity-gate.sh`/`docs-php-version-gate.sh`/`mutation-gate.sh`
  (в per-item commit-гейт не входят).
- P1.1/P1.2 — `Exec: manual`, effort=high MANDATORY (correctness-critical: panel-isolation,
  concurrency) — routing-гейт `plan-exec` их не исполняет, только `plan-run`/ручной запуск.
- P1.3 заблокирован до разрешения Q1 (`open-questions.md`) — не начинать без `D#`.
- План разошёлся с кодом → не импровизировать, эскалировать (plan-protocol §10).
- После закрытия item/фазы — перезаписать `handoff.md`.

## 3. Routing

| Items | Model/effort | Exec | Почему |
|:--|:--|:--|:--|
| P1.1 | sonnet/high | manual | T1 — tenant/panel-isolation correctness; effort high+ MANDATORY (invariants) |
| P1.2 | sonnet/high | manual | T6 — concurrency/race correctness; effort high+ MANDATORY (invariants) |
| P1.3 | sonnet/medium | manual | T2 — публичный API breaking change (`removeScopedRole` семантика); Exec=manual для владельческого ревью перед коммитом breaking-диффа, не из-за Q1 (Q1 уже resolved, D10) |
| P2.1 | sonnet/medium | plan-exec | T3 — локализованная правка с детерминированным тестом |
| P2.2 | sonnet/medium | plan-exec | T4 — локализованная правка с детерминированным тестом |
| P2.3 | sonnet/medium | plan-exec | T5 — миграция + rollback-тест, умеренный риск |
| P2.4 | — | — | T7 — закрыт решением (⛔), экзекуции не требует |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | Correctness-critical: panel-isolation & cache race | 2/3 | 🟡 In progress |
| P2 | Дешёвый батч: диагностика/wildcard/rollback | 3/4 | 🟢 Done |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-17 | Q1 (семантика `removeScopedRole` — T2) НЕ решается этим design-заходом самовольно — вынесена в `open-questions.md`, P1.3 заблокирован до её разрешения | Публичный API-метод пользовательской модели; смена семантики — breaking change, решение принадлежит владельцу (RAG:— repo-grounded: `HasScopedRoles.php:125-145`, докблок описывает текущее поведение как намеренное) |
| D2 | 2026-07-17 | T1/T6 — `Exec=manual`, effort=high (не `plan-exec`/medium) | Оба — correctness-critical (panel isolation / concurrency race); §9 routing-дефолт «effort>medium → manual» (thinking-policy: invariants требуют high+) |
| D3 | 2026-07-17 | T7 закрыт `⛔ Skipped by decision` без экзекуции — `resolveFor` пересчитывает panel/enums per-role в цикле, но это in-memory (не N+1/DB) | YAGNI — владелец явно указал «закрыть без действия» в брифе; нет прод-нагрузки, оправдывающей оптимизацию (RAG:— repo-grounded: `HasScopedRoles.php:238-254` — цикл `foreach ($roles as $roleModel)`, `resolveFor()` на :249; REMAINDER_REPORT.md T7) |
| D4 | 2026-07-17 | P2 (T3/T4/T5) объявляет оркестрацию — workflow-скрипт `workflows/wf-azguard-tails-p2.js` | 3 items с независимым scope (разные файлы, Inputs не ссылаются на Deliverables друг друга) + детерминированная Validation каждого → критерий SKILL §7 «phase MUST declare orchestration» сработал буквально |
| D5 | 2026-07-17 | T1 query-scope panel-isolation — СТРОГО аддитивная: при `PanelResolver::resolve(null) === null` (панель не установлена) применяются ВСЕ scopes как до правки; сужение по панели только когда панель АКТИВНА. Guard: `if ($currentPanelId !== null && $scope->panel_id !== null && $scope->panel_id !== $currentPanelId) continue;` | Вариант «отбрасывать явные-панельные scopes при null-контексте» — регрессия видимости: `AzGuard::setCurrentPanel()` зовут только 2 middleware + ExplainCommand (RAG:— repo-grounded: `grep setCurrentPanel packages/` — `SetCurrentPanel.php:25`, `PanelCheckAccess.php:34`, `ExplainCommand.php:66`, Octane-reset `AzGuardServiceProvider.php:202`); Filament читает свой `Filament::getCurrentPanel()`, `azguard.panel` в нём не установлена → guard молча отдал бы `Model::all()` вместо доступного подмножества (аудит A1). Это НЕ буквальная копия `hasScopedPermission()` — там панель никогда не null (`resolveDefault`), здесь бывает. Развилка №2 → resolved этим design-заходом (не breaking, не продуктовое: единственный корректный по построению вариант, устраняет утечку) |
| D6 | 2026-07-17 | Закрытие каждого T# обновляет его строку в `REMAINDER_REPORT.md` (Статус → Закрыт/ссылка на коммит) и колонку `Status` в `IMPROVEMENT_PLAN.md`; оба файла входят в `Files` соответствующего item'а (это repo-доки, НЕ `plans/**` → идут в item-commit, а не bookkeeping). T1→P1.1, T6→P1.2, T3/T4/T5→P2.1-P2.3 (через свою команду), T2→P1.3, T7 уже отражён D3 | `REMAINDER_REPORT.md` объявлен источником правды (`handoff.md`) и утверждает «T1 — Открыт — приоритет №1»; без носителя план закрыт, а отчёт продолжает звать хвосты заново (аудит A11). Каждый T# — отдельная строка → item'ы не конфликтуют по диффу |
| D8 | 2026-07-17 | P2.3 (T5): противоречие «файл миграции только чтение» ↔ «поправить докблок при расхождении» снято разделением — ПОВЕДЕНИЕ `down()` (`nullable(false)`) неприкосновенно (Scope Excluded / эскалация), ДОКБЛОК `down()` — документация, его правка при расхождении эксперимента с ним В СКОУПЕ | Докблок ≠ поведение; исполнитель, упёршийся в собственное ТЗ, иначе либо правит запрещённый файл, либо теряет находку без фикса (аудит B6→B3). Файл добавлен в `Files` P2.3 с явной границей поведение/докблок (RAG:— repo-grounded: `phases/P2.md` P2.3 Files/Scope Included) |
| D9 | 2026-07-17 | Рекурсия eager-load `scopeEntity` в `bootHasScopedRoles` (строка 63) — реальный прод-краш на первичном пути T1 (`Project::all()` при `runningInConsole()===false` + активный scope-row) — чинится В СОСТАВЕ P1.1 (bundle), фикс `->withoutGlobalScope(self::SCOPE_KEY)` на eager-load; тест P1.1 гоняет РЕАЛЬНЫЙ fetch-путь, а не pre-seed | Баг вскрыт при проектировании обхода console-guard (C1): обход исполним, но без фикса рекурсии Validation P1.1 физически не зелёная (OOM — проверено прогоном EXP B; фикс — EXP D зелёный, phpstan L6+pint чисты). Владелец: «баг править однозначно нужно, структуру решай сам». Bundle (а не отдельный item P1.4) — правка той же строки того же метода, что P1.1 уже трогает; отдельный item потребовал бы reverse-ordering (фикс ПЕРЕД P1.1) и второй коммит в один метод. RAG:— (repo-grounded + прогон: `HasScopedRoles.php:59-65`, EXP A/B/C/D в этом design-заходе) |
| D7 | 2026-07-17 | P1.3 переклассифицирован `🔴 Blocked` → `⬜ Not started` (форма ожидания — `ОЖИДАНИЕ Q1`, §8), phase P1 → `🟡 In progress` | Ожидание продуктового РЕШЕНИЯ владельца (Q1) — это healthy-gate §8 «ОЖИДАНИЕ», а НЕ §10-эскалация «plan разошёлся с кодом» (код однозначен, спорна лишь целевая семантика); план уже externalized гейт в `open-questions.md`+`## Обсуждение`, а `roadmap.md` уже моделирует P1.3 как `ОЖИДАНИЕ`. `🔴` заставлял `plan-lint` держать всю фазу Blocked и уводил холодный старт от актуальных P1.1/P1.2 (аудит A7). item↔roadmap↔board теперь консистентны |
| D10 | 2026-07-18 | Q1 (T2) разрешена владельцем — **Вариант B**: `removeScopedRole($role, $entity, panelId=null)` меняет семантику на «`null` = только null-панельная строка» (симметрично `assignScopedRole`); для «снести везде» вводится отдельный явный метод/флаг. Это `### Breaking` change публичного API-метода трейта модели пользователя — деталь diff'а, миграционная заметка и точная форма CHANGELOG-записи заполняются при детализации P1.3 (`/task:plan-design 2026.07.17-AZGUARD-TAILS P1.3`) | Решение владельца (Dmitry Vostrikov, 2026-07-18): симметрия с `assignScopedRole` важнее сохранения текущего (asymметричного, путающего) поведения; пакет `core` публичный, релизы по SemVer — breaking-изменение допустимо со следующим major/минором с явным CHANGELOG-объявлением, владелец готов на это |
| D11 | 2026-07-18 | P1.3 детализация: (а) «снести везде» реализуется ОТДЕЛЬНЫМ методом `removeScopedRoleEverywhere($role, $entity)`, НЕ флагом на `removeScopedRole()` — флаг допускал бы противоречивую комбинацию `panelId: 'admin', allPanels: true`, отдельный метод без параметра `panelId` делает её невыразимой на уровне сигнатуры; (б) `packages/core/CHANGELOG.md` заводит НОВУЮ секцию `### Breaking`, размещённую ПЕРВОЙ под `## [Unreleased]` (перед существующей `### Security`) — раздела `### Breaking` в файле не было ни разу; (в) диф `removeScopedRole()`/новый метод/два новых теста ПРИМЕНЕНЫ к рабочему дереву и ПРОГНАНЫ этим design-заходом (`composer test`-эквивалент `php -d memory_limit=1G vendor/bin/pest` 551/551, `pint --test` чист, `phpstan analyse` 0 ошибок), затем отменены `git checkout --` перед записью ТЗ — точный diff в `phases/P1.md` Code Guidance проверен, не гадание | (а) SKILL §3 «никаких самовольных допущений» — но выбор ФОРМЫ уже approved breaking-change (не сама breaking-семантика — та решена D10) является рутинным API-дизайном, а не продуктовым решением, требующим повторной эскалации владельцу; (б) в файле нет прецедента места для `### Breaking` — консьюмер должен увидеть breaking-изменения раньше Security/Fixed; (в) прошлые аудиты этого плана (A3/C1) поймали Code Guidance, не проверенный прогоном — тот же класс риска здесь недопустим для публичного API-диффа, RAG:✅ (2026-07-18: прогон на реальном рабочем дереве, не по памяти) |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-17 | issue-planner/opus | План создан; P1/P2 полностью детализированы за один заход (Design Passes 1/1); T7 закрыт решением сразу (D3) |
| 2026-07-17 | issue-planner/opus | `finish`: гейт скелетов чист (обе фазы детализированы), reconcile — контракт-блоков нет (не заводились), DoR обеих фаз зелёный; `roadmap.md` собран; version → 0.2.0 |
| 2026-07-17 | issue-planner/opus | Правка ТЗ P2 по аудиту: B1 (real `Log::spy` pattern+эталон), B2 (CHANGELOG+D6-доки в Files P2.1-P2.3), B3/D8 (докблок vs read-only), B4 (Inputs→tracked), B5 (sonnet/medium в 3 agent()), B6 (цитата 238-254), B7 (Board 0/3); детали — phases/P2.md, v0.3.0→0.4.0 |
| 2026-07-17 | issue-planner/opus | Правка ТЗ P1 по аудиту (RED): D5 (query-scope без панели→apply-all, A1), phpstan-safe lock через getStore()+instanceof (A3, прогон level 6), array=LockProvider — ложная эскалация снята (A4), RAG-маркеры (A5), CHANGELOG+REMAINDER в Files (A6/A11/D6), P1.3 🔴→⬜ ОЖИДАНИЕ (A7/D7), A8-A10; v0.2.0→0.3.0 |
| 2026-07-17 | issue-planner/opus | Правка ТЗ P1 по round-2 аудиту (C1): обход console-guard теста P1.1 спроектирован+ПРОВЕРЕН прогоном; рекурсия eager-load `scopeEntity` вскрыта+забандлена в P1.1 (D9); C2 (Board P2 канон 0/4; «0/3» B7 — ошибка, завершение 3/4+⛔), C3 (entity-scopes.md) — детали phases/P1.md P1.1; v0.4.0→0.5.0 |
| 2026-07-17 | issue-planner/opus | Design pass 1/1 (§5, k=1≥N=1 — бюджет исполнен): обе фазы детализированы, все Blocker'ы round-1/2 (A1/A3/C1+D9) закрыты с прогонной проверкой; дыр 0; фокус следующего — re-audit round-3, затем exec P1.1 |
| 2026-07-17 | plan-run/sonnet-high | P1.1 закрыт (🟠): D5+D9 в `bootHasScopedRoles()` — детали см. `phases/P1.md` P1.1 Completion Notes |
| 2026-07-17 | manual/sonnet-high | P1.2 закрыт (🟢): атомарный epoch bump в `PermissionCache::forgetForUser()` через `Cache::lock()` (T6) — детали см. `phases/P1.md` P1.2 Completion Notes. Item-commit `58ed1c4` |
| 2026-07-18 | owner (Dmitry Vostrikov) | Q1 разрешена — Вариант B (D10). P1.3 разблокирован, ждёт детализации Code Guidance через `/task:plan-design 2026.07.17-AZGUARD-TAILS P1.3` |
| 2026-07-18 | issue-planner/opus | P1.3 детализирован (D11), diff проверен прогоном — детали см. `phases/P1.md` P1.3 Code Guidance. `roadmap.md`/`handoff.md`/`brief/01-refinements.md` синхронизированы |
| 2026-07-18 | manual/sonnet-medium | P1.3 закрыт (🟢): `removeScopedRole(panelId=null)` → только any-panel строка, новый `removeScopedRoleEverywhere()` (Вариант B, D10) — детали см. `phases/P1.md` P1.3 Completion Notes. Item-commit `b972162` |
| 2026-07-18 | plan-exec/sonnet-medium | P2.1 закрыт (🟢): `Log::warning()`-паритет в `EnumPermissionCatalogBuilder::build()` (T3) — детали см. `phases/P2.md` P2.1 Completion Notes. Item-commit `b1de1ac` |
| 2026-07-18 | plan-exec/sonnet-medium | P2.2 закрыт (🟢): `filterAgainstCatalog()` wildcard-off ветка теперь дропает ключи с `*` до dynamic-сопоставления, паритетно wildcard-ON (T4) — детали см. `phases/P2.md` P2.2 Completion Notes. Item-commit `6bead71` |
| 2026-07-18 | plan-exec/sonnet-medium | P2.3 закрыт (🟢): rollback-тест миграции 000004 подтвердил ЭКСПЕРИМЕНТОМ, что `down()` падает `QueryException` на SQLite при null-строке `scope_class` (T5), паритетно докблоку — докблок правки не потребовал — детали см. `phases/P2.md` P2.3 Completion Notes. Item-commit `f75e0ef` |
| 2026-07-18 | plan-close/sonnet-low | Фаза P2 закрыта (🟢 Done): все items терминальны (P2.1-P2.3 🟢, P2.4 ⛔ по решению), docs-sync — не требуется, известные отклонения свёрнуты в `phases/P2.md` Phase Handoff. Следующий шаг — закрыть фазу P1 (items уже терминальны, формальный `plan-close` не проведён) |

## Обсуждение

### 1 — Семантика `removeScopedRole($role, $entity, panelId=null)` (T2, см. Q1)

- **(рекоменд.) Вариант A — оставить как есть, задокументировать явно.** `panelId=null`
  продолжает сносить строки ВСЕХ панелей. Плюсы: не breaking, не требует миграции
  поведения у существующих консьюмеров, минимальный риск. Минусы: асимметрия с
  `assignScopedRole` (там `null` = «своя any-panel строка») остаётся источником путаницы
  для новых читателей кода — митигируется только докблоком/тестом.
- **Вариант B — сменить семантику: `null` = «только null-панель строка», добавить явный
  метод/флаг для «снести везде».** Плюсы: симметрия с `assignScopedRole`, меньше
  сюрпризов. Минусы: breaking change публичного API трейта модели пользователя; требует
  записи в `packages/core/CHANGELOG.md` под `### Breaking` и, возможно, deprecation-пути
  (сначала soft-deprecate текущего поведения, потом сменить в мажоре) — решение о
  готовности пакета к breaking-изменениям (см. `Related Packages` — пакет `core` уже
  публичный, релизы идут по SemVer) принадлежит владельцу.

**Статус:** Resolved → D10 (Вариант B) — 2026-07-18, см. `open-questions.md` Q1.

### 2 — Что делает query-scope, когда `AzGuard::currentPanel()` НЕ установлена (T1/P1.1)

Контекст: `PanelResolver::resolve(null)` возвращает `null` везде, где панель AzGuard не
установлена явно. Установить её могут ТОЛЬКО middleware `SetCurrentPanel`/`PanelCheckAccess`
(консьюмер вешает руками, обнуляют в `finally`) и `ExplainCommand` (консоль). Filament
читает СВОЙ `Filament::getCurrentPanel()` и `AzGuard::setCurrentPanel()` не зовёт — значит
в ЛЮБОМ Filament-запросе и на роутах без `azguard.panel` панель AzGuard = `null`. Это
НОРМА, не край. Развилка: как ведёт себя новый panel-guard в query-scope при `null`?

- **(рекоменд.) Вариант A — при null-панели применять ВСЕ scopes (как до правки).** Сужение
  по панели строго аддитивное: срабатывает только при АКТИВНОЙ панели. Плюсы: не расширяет
  видимость ни в одном контексте (entity-scopes СУЖАЮТ выборку — их молчаливое отключение =
  регрессия безопасности, отдающая `Model::all()`); zero-регресс для null-контекстов. Минусы:
  cross-panel изоляция не действует, когда панель не установлена — но там и нет «текущей
  панели», против которой изолировать, так что это корректно, а не дыра.
- **Вариант B — при null-панели применять только null-панельные scopes.** Плюсы: строже.
  Минусы: молча гасит легитимные явные-панельные scopes в Filament-запросах → расширение
  видимости (ровно находка A1). Отклонён.
- **Вариант C — `resolveDefault(null)` → `'app'`.** Плюсы: панель всегда определена. Минусы:
  маскирует «панели нет вовсе» под `'app'`; scopes явных панелей ≠ app молча отбрасываются
  вне панельного контекста. Отклонён.

**Статус:** Resolved → D5 (Вариант A). Не продуктовое/breaking решение (единственный
корректный по построению вариант, устраняет утечку) — в `open-questions.md` не выносится.
