# 2026.07.18-AZGUARD-STABLE — AzGuard: аудит → стабильный API → тест-углубление → v0.3.0

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.07.18-AZGUARD-STABLE |
| Title | AzGuard: полный аудит, стабилизация публичного API (акцент — интеграционная поверхность, fluent/DX), структурный канон, тест-углубление по оси корректности, тег v0.3.0; план — эталонная дорожка для пакетов экосистемы |
| Version | 0.2.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | fable (opus-класс) |
| Last Updated | 2026-07-18 |
| Repository | /home/vostrikov/projects/packages/azguard |
| Related Packages | core, filament, context |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · haiku (LOW) · fable/opus (design/audit) |
| Approval Owner | Dmitry Vostrikov |
| Design Passes | 2/3 — 6 фаз / ~26 items (→ ≥2 по SKILL §5) + план объявлен эталоном дорожки для флота пакетов (ядро экосистемы → 3) |
| Paused By | — |

## 1. Context

AzGuard — монорепо RBAC для Laravel (пакеты core/filament/context, 0.2.0, без git-тегов).
Все находки прошлого архитектурного ревью (F1–F54) и хвосты T1–T7 закрыты; база зелёная
(~501 тест, PHPStan 6, mutation-гейты в CI). Владелец хочет финальную волну: свежий
read-only аудит (акцент — интеграционная поверхность и fluent/DX, слабейшее место по
его ощущению), ремедиацию находок, структурный канон (пре-1.0 свобода переименований),
заморозку публичной поверхности, затем углубление тестовой надёжности по образцу vaulter
(docker БД-матрица, race-тесты, параллельные прогоны, mutation-ratchet) и тег v0.3.0.
План строится как переиспользуемый шаблон дорожки «пакет → эталонная надёжность» для
остальных пакетов экосистемы. Вход и провенанс: `brief/00-brief.md`, recon-файлы в
`findings/`, синтез в `research/00-user-intent.md`.

## 2. Execution Rules

- Один item за раз; статус обновляется В ПЛАНЕ до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (plan-protocol §6).
- Коммит-гейт закрытия item'а — plan-protocol §6/§8: item-commit (только Files) →
  bookkeeping-commit; `git add -A`/`-a` запрещены; перед коммитом — зелёный
  `composer lint:check && composer analyse` + целевые тесты item'а.
- P0 — строго read-only по коду: аудит НЕ чинит (правки — только файлы плана/findings).
- План разошёлся с кодом → не импровизировать, эскалировать (§10).
- После закрытия item/фазы — перезаписать `plans/2026.07.18-AZGUARD-STABLE/handoff.md`.
- Волатильные фазы P1/P2 детализируются ПОСЛЕ закрытия P0 (D3) — exec по их скелетам
  запрещён.
- Инварианты ARCHITECT_REVIEW.md §6 «What NOT to Do» (12 анти-паттернов) обязательны
  для всех фаз: union-only решения, code-first core, курированный frontend, контракты
  только на реальных швах, explain() вне hot-path.

## 3. Routing

Черновая маршрутизация уровня фаз; уточняется при детализации каждой фазы
(`/task:plan-design <ID> Pn`). Пусто = дефолты SSOT-матрицы §9.

| Items | Model/effort | Exec | Почему |
|:--|:--|:--|:--|
| P0.1–P0.5 | fable/high | manual | read-only аудит публичных контрактов + RAG несущих фактов (effort high+ MANDATORY); исполняются ОДНОЙ сессией через `workflows/wf-azguard-stable-p0-audit.js` (D8: §7-критерий — 4 оси scope-независимы, Validation детерминирована); закрытие items — оркестратор по §8 |
| P0.6 | fable/high | manual | синтез REGISTER/бэклог + гейт владельца — контракт-класс, solo |
| P1.*, P2.* | — | — | волатильные фазы (D3): маршрутизация назначается при детализации по фактам аудита |
| P3.1–P3.3 | fable/high | manual | cut-line публичной поверхности / SemVer — контракт-класс |
| P4.1–P4.6 | sonnet/medium | plan-exec | инфраструктура тестов по готовым спецификациям; уточнить при детализации |
| P5.1 | fable/high | manual | экстракция эталонного шаблона дорожки — канон флота |
| P5.2–P5.3 | sonnet/medium | plan-exec | механика релиза и архивации по готовым чек-листам |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Read-only аудит: 4 оси + RAG fluent/DX → REGISTER + бэклог | 5/6 | 🟡 In progress |
| P1 | Ремедиация находок аудита (волны по severity) | 0/4 | ⬜ Not started |
| P2 | Структурный канон + fluent/DX редизайн API | 0/4 | ⬜ Not started |
| P3 | Release-готовность: cut-line, заморозка поверхности, SemVer-политика | 0/3 | ⬜ Not started |
| P4 | Тест-углубление (ось корректности): docker БД-матрица, race, паралл. прогоны, mutation-ratchet | 0/6 | ⬜ Not started |
| P5 | Шаблонизация дорожки + тег v0.3.0 + архивация | 0/3 | ⬜ Not started |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-18 | План создан из брифа владельца (`brief/00-brief.md`); дорожка адаптирована из цепочки планов vaulter: аудит → ремедиация → канон → заморозка → углубление → релиз | Прямое требование брифа «по образцу vaulter»; фактура образца — `findings/recon-vaulter-template-2026-07-18.md` (RAG:— repo-grounded: vaulter/plans/) |
| D2 | 2026-07-18 | Один мастер-план с фазами, а не цепочка планов + RUNBOOK (как у vaulter); роль RUNBOOK играет `roadmap.md` этого плана | Бриф: «общий план будет до финала»; протокол уже даёт roadmap-слой (SKILL §8). RAG:— (repo-grounded: brief/00-brief.md п.4) |
| D3 | 2026-07-18 | P1 (ремедиация) и P2 (канон/fluent) — волатильные фазы: их вход производится аудитом P0 (REGISTER + бэклог + RAG-выжимка), детализация — just-in-time после закрытия P0; exec по их живым скелетам запрещён | Явная D-запись по SKILL §5 (дефолт «полная детализация до exec» невыполним: содержимое ремедиации неизвестно до аудита; тот же паттерн у vaulter — REMED-план создавался после AUDIT-VLT). RAG:— (repo-grounded: findings/recon-vaulter-template-2026-07-18.md §1) |
| D4 | 2026-07-18 | Ось «нагрузка» vaulter (k6/SLO/load-стенд) в дорожку azguard НЕ переносится; углубление P4 идёт по оси корректности: docker БД-матрица (реальная БД вместо только sqlite :memory:), кросс-процессные race-тесты, параллельные прогоны, mutation-ratchet; perf hot-path (resolver/cache) — микробенчмарк внутри P4 без k6 | azguard — библиотека без собственного HTTP-рантайма; на sqlite самоскипается БД-специфичный код (урок vaulter VLT-MUT D3/D6). RAG:— (repo-grounded: findings/recon-test-ci-2026-07-18.md §1, §4) |
| D5 | 2026-07-18 | Тег v0.3.0 — финал плана (P5), после тест-углубления; «stable»-точка фиксируется заморозкой поверхности в P3 (arch/snapshot-гейт), не тегом | Бриф п.8 («в конце тегнуть»); vaulter тегал до углубления — осознанное отличие, см. `research/00-user-intent.md`. RAG:— (repo-grounded: brief/00-brief.md п.8) |
| D6 | 2026-07-18 | Coupling-поверхность плана: `packages/*/src` публичные типы (P1·P2·P3), `tests/**` (P0-чтение·P1·P2·P4), `.github/workflows/**` (P3·P4·P5), `composer.json` scripts (P2·P4), `docs/**` EN+RU (P1·P2·P3·P5), `phpunit.xml` (P2·P4), `ARCHITECT_REVIEW.md`-инварианты §6 (все фазы) | Превентивное перечисление по SKILL §5: автор поздней фазы видит, что заденет раннюю. RAG:— (repo-grounded: findings/recon-api-surface-2026-07-18.md §5) |
| D7 | 2026-07-18 | Выход-бэклог P0 переименован: `research/01-backlog.md` → `research/02-backlog.md`; контракт-блоки P0/P1/P2 обновлены | Коллизия нумерации слоя 2: NN=01 занял `research/01-fluent-api-priors.md`, добавленный после скелетов; NN — порядок чтения (SKILL §16). RAG:— (repo-grounded: research/) |
| D8 | 2026-07-18 | P0 объявляет оркестрацию: P0.1–P0.5 исполняет ОДНА fable/high-сессия через `workflows/wf-azguard-stable-p0-audit.js` (стадия RAG → барьер → 4 параллельные оси; агенты пишут только findings-файлы, БЕЗ git); закрытие items P0.1–P0.5 — оркестратор-сессия последовательно по §8; P0.6 — ручной solo (синтез + блокирующий гейт владельца). Альтернатива item-by-item (5 сессий) отклонена | Критерий SKILL §7 сработал: 4 items P0.2–P0.5 scope-независимы (пишут разные файлы, друг друга не ждут; общий предшественник — только P0.1) и несут детерминированную Validation (grep-гейты формата findings-файлов). RAG:— (repo-grounded: phases/P0.md Phase Status, Validation items) |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-18 | issue-planner/fable | План создан (design pass 1/3: recon×3 → findings/, синтез research/00, скелеты P0–P5 с контракт-блоками, D1–D6). ACTIVE: — → 2026.07.18-AZGUARD-STABLE. Фокус pass 2: детализация P0 |
| 2026-07-18 | issue-planner/fable | Заложены приоры research/01-fluent-api-priors.md + RAG-preseed findings/P0-rag-fluent-dx-preseed.md (5 тезисов верифицированы, приор B.7 скорректирован); P0.1 сужен до добора первоисточников (Filament/context7) |
| 2026-07-18 | issue-planner/fable | Design pass 2/3: P0 детализирована до DoR (6 items, чеклисты C-A/B/C/D, finding-template), workflow wf-azguard-stable-p0-audit.js создан, Routing P0 уточнён, D7–D8, v0.2.0. Фокус pass 3: детализация P1–P5 по фактам аудита P0 |
| 2026-07-18 | orchestrator/fable | P0.1 закрыт: RAG-добор — 5/5 вердиктов preseed подтверждены первоисточниками, 2 [UNVERIFIED] — детали см. phases/P0.md P0.1 Completion Notes |
| 2026-07-18 | orchestrator/fable | P0.2 закрыт: ось A — 11 чеков, 8 находок (3 Major) — детали см. phases/P0.md P0.2 Completion Notes |
| 2026-07-18 | orchestrator/fable | P0.3 закрыт: ось B — 10 чеков, 11 находок (5 Major), фасад 17 @method (recon завышал) — детали см. phases/P0.md P0.3 Completion Notes |
| 2026-07-18 | orchestrator/fable | P0.4 закрыт: ось C — 10 чеков, 16 находок (1 Blocker C-01, 9 Major), F4/F40/F51 сделаны, F22 открыт — детали см. phases/P0.md P0.4 Completion Notes |
| 2026-07-18 | orchestrator/fable | P0.5 закрыт: ось D — 12 чеков, 9 находок (1 Major D-06 OOM), Support/ 9 файлов классифицированы, baseline 17+6+12=35 — детали см. phases/P0.md P0.5 Completion Notes |

## Обсуждение

### 1 — Тег v0.3.0 до или после тест-углубления

- **(рекоменд.) Вариант A — тег в финале плана.** Плюсы: соответствует брифу («в конце
  тегнуть»); тег закрепляет уже углублённо протестированную поверхность. Минусы:
  «stable»-точка без публичного маркера до самого конца.
- **Вариант B — тег сразу после P3 (vaulter-модель).** Плюсы: ранний публичный маркер.
  Минусы: противоречит брифу; углубление может вскрыть правки → второй тег.

**Статус:** Resolved → D5

### 2 — Финальная версия: 0.3.0 или иная

- **(рекоменд.) Вариант A — 0.3.0.** Ориентир из брифа («там 0,3, допустим, пока»).
- **Вариант B — 0.9.x/1.0.0-rc.** Если после заморозки поверхность считается
  кандидатом в 1.0.

**Статус:** Decision pending (нужен владелец)

### 3 — Состав docker-матрицы P4

- **Вариант A — Postgres 16 + Redis.** Минимум, закрывающий уроки vaulter (PG-only
  код) и follow-up T6 (кросс-процессный Redis race-тест).
- **Вариант B — Postgres + MySQL/MariaDB + Redis.** Полная матрица потребителей RBAC.

**Статус:** Decision pending (нужен владелец)
