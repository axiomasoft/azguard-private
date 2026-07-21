# 2026.07.18-AZGUARD-STABLE — AzGuard: аудит → стабильный API → тест-углубление → v0.3.0

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.07.18-AZGUARD-STABLE |
| Title | AzGuard: полный аудит, стабилизация публичного API (акцент — интеграционная поверхность, fluent/DX), структурный канон, тест-углубление по оси корректности, тег v0.3.0; план — эталонная дорожка для пакетов экосистемы |
| Version | 0.3.22 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | fable (opus-класс) |
| Last Updated | 2026-07-21 (Codex-проекция и review checkpoints для остатка P4/P5, D33; незавершённый diff P4.8 сохранён) |
| Repository | /home/vostrikov/projects/packages/azguard |
| Related Packages | core, filament, context |
| Execution Mode | phase-first |
| Target Operator Models | GPT-5.6 Terra (implementation) · GPT-5.6 Luna (economy/read-only) · GPT-5.6 Sol (design/audit) — D33 |
| Approval Owner | Dmitry Vostrikov |
| Design Passes | 3/3 — 6 фаз / 33 items (→ ≥2 по SKILL §5) + план объявлен эталоном дорожки для флота пакетов (ядро экосистемы → 3); `finish` завершён (сквозной reconcile контракт-блоков/coupling/producer→consumer ✓, roadmap верифицирован); план готов к `plan-audit design` |
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
- Для открытых items маршрутизация provider-neutral: implementation/frontier/economy; Codex-проекция
  и checkpoints ревью закреплены D33/findings/codex-model-routing-2026-07-21.md. Исторические строки
  с sonnet/fable/opus не переписываются.
- Review=full для P4.8/P4.7/P4.4 включает независимый read-only diff-review на frontier/high ДО
  закрытия item'а; исправления выполняет implementation/high. После каждой терминальной фазы —
  обязательный `plan-close`, затем свежая frontier/xhigh-сессия `plan-audit`.
- Волатильные фазы P1/P2 детализируются ПОСЛЕ закрытия P0 (D3) — exec по их скелетам
  запрещён.
- Инварианты ARCHITECT_REVIEW.md §6 «What NOT to Do» (12 анти-паттернов) обязательны
  для всех фаз: union-only решения, code-first core, курированный frontend, контракты
  только на реальных швах, explain() вне hot-path.

## 3. Routing

Закрытые строки сохраняют фактически использованные Claude-селекторы как историю. Все ОТКРЫТЫЕ
строки provider-neutral и проецируются в Codex по D33: economy→GPT-5.6 Luna,
implementation→GPT-5.6 Terra, frontier→GPT-5.6 Sol. Пусто = дефолты SSOT-матрицы §9.

| Items | Model class/effort | Exec | Почему |
|:--|:--|:--|:--|
| P0.1–P0.5 | fable/high | manual | read-only аудит публичных контрактов + RAG несущих фактов (effort high+ MANDATORY); исполняются ОДНОЙ сессией через `workflows/wf-azguard-stable-p0-audit.js` (D8: §7-критерий — 4 оси scope-независимы, Validation детерминирована); закрытие items — оркестратор по §8 |
| P0.6 | fable/high | manual | синтез REGISTER/бэклог + гейт владельца — контракт-класс, solo |
| P1.1 (W0) | sonnet/high | manual | Blocker C-01 — снять `runningInConsole`-bypass (D10 а); default-fallback упразднён (D27), панель-резолюция не трогается; solo, один item-commit |
| P1.2 (W1) | sonnet/high | manual | 12 Major, часть security-sensitive (морф/mass-assign/wildcard/union-only); файлы пересекаются (C-02/C-03 один файл) → последовательно, per-finding коммиты (D11); НЕ workflow |
| P1.3 (W2) | sonnet/medium | manual | 14 Minor/Nit — механика/доки/тесты; последовательно, per-finding коммиты (D11) |
| P1.4 (review) | fable/high | manual | сквозной adversarial review диффа фазы через субагентов (security-review + reviewer + blade-review) свежим контекстом |
| P2.1–P2.3 | fable/high | manual | исполнены (историческая блочная маршрутизация D14: design/contract-класс, Review=full); с P2.4 routing построчный — D28 |
| P2.4 | sonnet/high | manual | каноны решены и запинены RAG'ом (D17: Filament fluent, `::using()`, порядок `что,где`) — предписанная реализация публичного API, открытых design-решений нет; breaking легален (D14); Review=full |
| P2.5 | fable/high | manual | cut-line target-спека — чистый design-item, вход заморозки P3 (ошибка вердикта замораживается снапшотом); Review=full |
| P2.6 | sonnet/high | manual | канон fake() запинён RAG (Pdf::fake, Запрос 3) — форма Recorder/ассерций предписана; новая @api-поверхность → Review=full |
| P2.7 | sonnet/medium | plan-exec | editorial: вердикты словаря готовы (таблица C-A10 + §9 канона), кода нет; Review=light |
| P2.8 | sonnet/medium | plan-exec | doc-only quick-start + одна информативная hint-ветка doctor (fail-closed не трогается); Review=light |
| P2.9 | fable/high | manual | breaking-семантика permission-matcher'а (дефолт грамматики меняет поведение всех потребителей) + re-baseline тестов требует различать «legacy-намерение vs случайность»; Review=full |
| P2.10 | sonnet/medium | plan-exec | механический свип docs-паритета + консолидация arch-тестов; гейты детерминированы (parity-gate, composer check); Review=light |
| P3.1 | sonnet/high | manual | исполнение выреза ПО ЗАМОРОЖЕННОЙ спеке P2.5 (fable) — предписанная механика, но SemVer-необратимо; Review=full |
| P3.2 | fable/high | manual | snapshot-гейт заморозки (D20) — несущий механизм всего остатка плана, ошибка = тихий дрейф @api; Review=full |
| P3.3 | sonnet/high | manual | SemVer-политика + UPGRADING по D21/D22 — контракт-язык, но состав предписан (все breaking P1+P2 уже зафиксированы в Completion Notes); Review=full |
| P4.1 | sonnet/medium | plan-exec | docker-стенд (compose PG/MySQL/Redis), инфра без прикладной логики; Review=light |
| P4.2 | sonnet/medium | plan-exec | re-scope (D31): коммит написанного БД-лейн-харнесса + фикс тест-фикстуры expires_at; CI-джоб/green отложены в P4.10; DB-корректность → Review=full |
| P4.8 | implementation/high | manual | frozen D30 remediation; GPT-5.6 Terra/high исполняет, GPT-5.6 Sol/high независимо ревьюит diff до закрытия; raw-SQL cross-driver data integrity → Review=full |
| P4.7 | implementation/high | manual | frozen D24+D32; GPT-5.6 Terra/high исполняет, GPT-5.6 Sol/high независимо ревьюит diff до закрытия; миграции/security fail-closed → Review=full |
| P4.9 | implementation/medium | plan-exec | точечный query-фикс по D30, детерминированный тест; GPT-5.6 Terra/medium; Review=full в общем checkpoint B6 |
| P4.10 | implementation/medium | plan-exec | green-proof+CI+baseline по D30; GPT-5.6 Terra/medium; Review=full в общем checkpoint B6 |
| P4.3 | implementation/medium | plan-exec | paratest+shared-state hardening; GPT-5.6 Terra/medium; Review=full |
| P4.4 | implementation/medium | plan-exec | race Redis/Octane; GPT-5.6 Terra/medium исполняет, GPT-5.6 Sol/high независимо ревьюит concurrency-diff; Review=full; реальный race → §10 |
| P4.5 | implementation/medium | plan-exec | mutation-ratchet по измеренному baseline; GPT-5.6 Terra/medium; Review=full |
| P4.6 | implementation/medium | plan-exec | механическая чистка дыр; GPT-5.6 Terra/medium; Review=light |
| P5.1 | frontier/high | manual | открытый синтез канона флота; GPT-5.6 Sol/high; Review=full |
| P5.2 | implementation/medium | plan-exec | механика релиза по frozen D25; GPT-5.6 Terra/medium; push тега только после approve владельца; Review=full |
| P5.3 | implementation/medium | plan-exec | docs migration по frozen D26; GPT-5.6 Terra/medium; архив post-plan; Review=light |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Read-only аудит: 4 оси + RAG fluent/DX → REGISTER + бэклог | 6/6 | 🟢 Done |
| P1 | Ремедиация находок аудита (волны по severity) | 1/4 | 🟠 Done with deviations |
| P2 | Структурный канон + fluent/DX редизайн API | 5/10 | 🟠 Done with deviations |
| P3 | Release-готовность: cut-line, заморозка поверхности, SemVer-политика | 2/3 | 🟠 Done with deviations |
| P4 | Тест-углубление (ось корректности): docker БД-матрица, portability-ремедиация, race, mutation-ratchet | 2/10 | 🟡 In progress |
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
| D9 | 2026-07-18 | Гейт P0.6 пройден: владелец утвердил бэклог ремедиации — волны P1 (W0={C-01}, W1=12 Major, W2=14 Minor+Nit), 3 отклонения (D-02 историчен; D-07/D-08 → маршрут P4), 9 кластеров P2 (6 предписанных + 3 добавленных: Testing DX, headless-порог, контрактные швы), спорные B-04/C-02/C-08/C-11 оставлены в P1-W1. REGISTER + 02-backlog — утверждённый вход детализации P1/P2 (D3) | Блокирующий гейт по ТЗ P0.6; ответы владельца — brief/01-refinements.md блок 2026-07-18. RAG:— (repo-grounded: findings/REGISTER.md, research/02-backlog.md) |
| D10 | 2026-07-18 | Единый контракт query-scope изоляции (C-01+C-02+C-03), принцип владельца «максимальная надёжность, fail-closed, ничего не ослаблять» (brief/01-refinements.md, 2026-07-18): (а) C-01 — убрать `runningInConsole()`-bypass из bootHasScopedRoles; активация scope ключается на `Auth::check()`+`method_exists`, не на SAPI (в Laravel нет `runningInQueue()`), → queue-джобы под авторизованным юзером получают изоляцию; (б) в глобальном scope панель резолвится `PanelResolver::resolve(null) ?? resolveDefault(null)` (детерминизм: приложения с `default_panel` работают штатно); (в) C-02 — новый конфиг `az-guard.scope.on_missing_panel` enum `exception`(дефолт, fail-closed, бросает `PanelNotSetException`)|`empty`(whereRaw 1=0)|`all`(legacy-аддитив) применяется, когда панель null даже после default-fallback; (г) C-03 — stale `scope_class` → `Log::warning` once (RequestState::once) + чек в `guard:doctor`; тихая потеря фильтра становится громкой | Владелец на уточнении выбрал максимальную надёжность; default-fallback не даёт fail-closed ломать штатный HTTP-путь (панель почти всегда резолвится). Спорная партиция C-02 в W1 подтверждена гейтом D9. RAG:— (repo-grounded: packages/core/src/Concerns/HasScopedRoles.php:50-84, packages/core/config/az-guard.php:131, Exceptions/PanelNotSetException.php) |
| D11 | 2026-07-18 | Внутри волн P1.2/P1.3 фиксы коммитятся per-finding (каждая находка — отдельный Files-scoped коммит с conventional-сообщением `fix(scope): … (C-XX)`), item закрывается после зелёной валидации всей волны + handoff; `git add -A/-a` запрещён (Execution Rules) | Волна из 12/14 находок одним коммитом неревьюабельна, а часть — security-фиксы; per-finding коммиты служат принципу надёжности (D10) и облегчают P1.4/откат. Уточнение канона «волна=item=один item-commit» (VLT-REMED) под security-состав волны. RAG:— (repo-grounded: plan.md §2 Execution Rules) |
| D12 | 2026-07-18 | Пиновка направлений остальных нагруженных находок P1 (fail-closed/max-reliability): C-13 — ContextGrantBuilder::grant бросает на `*`/wildcard-метасимволы + резолвер не короткоциркуитит catalog-фильтр для layer-wildcard; C-08 — ResourceGate возвращает `true|null`, никогда `false` (union-only §6); C-10 — `getMorphClass()` во всех write/read-путях грантов + тест с `Relation::enforceMorphMap`; C-11 — `class_name`/`scope_class` вон из `$fillable` (guarded + прямые сеттеры, mass-assign закрыт); C-04 — запрет `expiration_time=null` при персистентном сторе (валидация конфига бросает при boot) — TTL становится границей орфанов epoch | Каждая — security- или корректность-находка Major; выбран строгий вариант из «либо/либо» рекомендаций осей. RAG:✅ union-only §6 (ARCHITECT_REVIEW), морф-канон repo-grounded (findings/P0-axis-c-correctness.md C-08/C-10/C-11/C-13). RAG:— (repo-grounded: findings/P0-axis-c-correctness.md) |
| D13 | 2026-07-18 | Реконсиляция «вход P4» осей с гейтом D9: B-09 (swap-тест MergeStrategy) и B-10 (тест grant_sources) — авторы осей помечали «вход P4», но гейт D9 поместил их в P1-W2 → пишутся ТЕПЕРЬ (P1.3), не откладываются; в P4 уходят ТОЛЬКО кросс-процессный Redis race-тест (хвост C-05) и Octane-тест RequestState (хвост C-14) — как зафиксировано в 02-backlog «Хвосты в P4». Дублирования домов нет | Гейт владельца (утверждённая партиция) старше рекомендации аудитора; 02-backlog §«Хвосты в P4» перечисляет ровно 2 тестовых хвоста, B-09/B-10 в них не входят. RAG:— (repo-grounded: research/02-backlog.md §Хвосты в P4, findings/REGISTER.md судьба B-09/B-10) |
| D14 | 2026-07-18 | Развилки P2 разрешены в пользу идеала/канона по прямому указанию владельца («идеальная структура, любые breaking разрешены, применять лучшие решения»): grant-грамматика = immutable-with единый корень (D16); TTL-парность context (D16); `AzGuard::fake()` СТРОИМ в 0.3.0 (§6 канона); headless = doc-only minimal-setup, рантайм panel-less НЕ строим (YAGNI, fail-closed сохраняется); wildcard-флип на Hierarchical сейчас (D18); cut-line фасада исполняет P3, P2 даёт спеку (P2.5). Отклонены: мутабельные builders / «context бессрочен by design» / отложить fake() / рантайм-lenient / legacy-wildcard в 1.0 | Владелец снял 4 развилки одним указанием (brief п.7/п.9 + refinements); синтез альтернатив — research/03-p2-canon.md §0. RAG:— (repo-grounded: brief/00-brief.md п.7/п.9, brief/01-refinements.md) |
| D15 | 2026-07-18 | Целевая структура core (research/03-p2-canon.md §1): `Support/` УПРАЗДНЯЕТСЯ — 9 файлов + PanelProvider + PermissionKey переезжают в Panels/ · Permissions/ · Configuration/ · Runtime/ · Abilities/ · Auth/ · Database\Schema/; `AzGuard\Contracts` и `AzGuard\Registry\Contracts` — ДВА ДОМА сознательно (locality субдомена), не сливать (arch-расширение на оба делает P1-W2 D-04) | Catch-all Support (6 ролей) размывает @api/@internal (D-05); субдоменные контракты рядом со своим субдоменом — глубже модуль, чем плоский общий дом. RAG:— (repo-grounded: findings/P0-axis-d-structure.md §C-D1/§C-D2, tests/ArchTest.php:21) |
| D16 | 2026-07-18 | Grant-грамматика канон: единый immutable fluent-корень `AzGuard::forUser($u)->on()->inContext()->until()->grant()` для core И context (context = расширение корня, не отдельный `new ContextGrantBuilder`); builders `final readonly` + with-методы (`new self`); TTL-парность context (новая миграция `expires_at`); фасадные позиционные shorthands grant/revoke/grants → `@internal`; арх-ратчет toBeFinal/toBeReadonly расширен на `AzGuard\Grants` + context-builder-неймспейс. Альтернатива (мутабельные builders, унифицировать только вход) отклонена | B-03 (две несведённые грамматики) + B-08 (мутабельные builders вне ратчета); канон F49 (Values уже immutable), «идеальный fluent» (владелец). RAG:— (repo-grounded: findings/P0-axis-b-fluent.md §C-B5/§C-B9, tests/ArchTest.php:120-122) |
| D17 | 2026-07-18 | Config→fluent канон: Filament-плагин — fluent-сеттеры (enforce/source/abilities/keyTemplate/case) + `make()` через `app(static::class)`, config→fallback; middleware — статические `::using(string|BackedEnum)` на grant/panel/panel_check/check (строковый alias-DSL параллельно, оба в docs); единый порядок аргументов алиасов `что,где` (выровнять PanelCheckAccess под CheckDirectGrant — breaking) | B-06/B-02/A-03; каноны верифицированы первоисточниками P0.1. RAG:✅ 2026-07-18 (findings/P0-rag-fluent-dx.md Запрос 1 Filament v5, Запрос 2 spatie/Laravel ::using + PR #52679) |
| D18 | 2026-07-18 | Wildcard-флип (F22): дефолтный matcher = `HierarchicalPermissionMatcher` (`*`=сегмент, `**`=рекурсивно) сейчас, legacy `WildcardPermissionMatcher` — opt-out через `features.wildcard_permission` на один deprecate-цикл; `PermissionSet` вне контейнера дефолтит на Hierarchical (C-07), расхождение — @api-докблок; F4/F40/F51 подтверждены сделанными аудитом (C-C4, якоря) — P2.9 только verify-record; C-15 остаётся в P1 (заполнение winningSource, не удаление поля) | Вердикт аудита C-C4 «делать сейчас» + гейт D9 + пре-1.0 свобода (бриф п.3/п.7). RAG:— (repo-grounded: findings/P0-axis-c-correctness.md §C-06/§C-07/§C-C4, research/02-backlog.md W2 C-15) |
| D19 | 2026-07-18 | Reconcile контракт-блока P3: cut-line фасада ИСПОЛНЯЕТ P3 (не только гейты/доки) — P3.1 удаляет 2 мёртвых метода `AzGuardManager`+их `@method` и проставляет `@internal` по замороженной спеке P2.5; исходная граница блока «код поверхности НЕ меняется» уточнена до «поведенческий редизайн завершён P2; P3 исполняет ТОЛЬКО cut-line (удаление мёртвых + переклассификация @api/@internal), новой функциональности нет». Контракт-блок P3 обновлён | Расхождение скелет-блока P3 с D14/P2.5 (P2.5 Scope Excluded: «правка фасада — исполнение P3»); reconcile при детализации (SKILL §5, дёшево — скелет не терминален). RAG:— (repo-grounded: phases/P2.md P2.5 Scope Excluded, plan.md D14) |
| D20 | 2026-07-18 | Механизм заморозки P3.2: расширить существующий reflection-тест `tests/Unit/ApiBoundaryTest.php` до snapshot-гейта с закоммиченным фикстуром (`@api`-типы core + сигнатуры публичных методов ВКЛЮЧАЯ имена параметров — named-arguments-контракт); регенерация фикстура — осознанный акт под D#+bump, не авто-обновление; over-sensitivity (краснеет и на BC-safe добавления) — ЖЕЛАЕМОЕ свойство пре-1.0-строгой-заморозки. `roave/backward-compatibility-check` рассмотрен как tag-boundary BC-комплемент и ОТЛОЖЕН (не тащить новый dev-dep; снапшот+D#-дисциплина достаточны для 0.x; зафиксирован в каталоге ограничений как follow-up) | RAG-верификация ландшафта: roave/bc-check — де-факто BC-инструмент, но reflection-снапшот механически надёжен для детекта дрейфа и для 0.x-строгой-заморозки его строгость — плюс; переиспользование ApiBoundaryTest-паттерна дешевле и без supply-chain-риска. RAG:✅ 2026-07-18 (perplexity: roave/bc-check vs reflection-snapshot, packagist roave/backward-compatibility-check) |
| D21 | 2026-07-18 | Локус UPGRADING: канонический путь апгрейда — `docs/introduction/upgrading.md` (+RU-зеркало, VitePress), НЕ новый repo-root `UPGRADING.md` (не плодить дубль существующей доки); P3.3 консолидирует полную главу «0.2→0.3» (все breaking P1+P2) там. `root/semver-policy.md`+`root/known-limitations.md` живут в plan-root/ (судьба — docs проекта, при архивации переезжают в docs/) | Существует `docs/introduction/upgrading.md` (P2.9 уже дописывает туда wildcard-breaking); второй дом фрагментирует апгрейд-нарратив. RAG:— (repo-grounded: docs/introduction/upgrading.md, phases/P2.md P2.9 Files) |
| D22 | 2026-07-18 | Финальный тег плана — v0.3.0 (владелец разрешил Q1, 2026-07-18): «stable»-точка после тест-углубления P4, тегается в P5.2; НЕ 0.9.x/1.0-rc (поверхность стабилизируется, но 1.0-обязательства не берутся в этой волне) | Ответ владельца на Q1 (open-questions.md); согласуется с брифом п.8 «там 0,3». Обсуждение §2 → Resolved. RAG:— (repo-grounded: brief/00-brief.md п.8) |
| D23 | 2026-07-18 | Цель Q2 (владелец, 2026-07-18): пакет ДОЛЖЕН работать под разные СУБД (приоритет Postgres, но MySQL/MariaDB — first-class), Redis ОПЦИОНАЛЕН (shared hosting → database/file cache-драйвер должен нести epoch/scoped-cache без Redis). Матрица P4 и стратегия портируемости (что PG-only, что через воркэраунд) — по фактам recon findings/recon-db-portability-2026-07-18.md; финальный состав матрицы + воркэраунды фиксируются при детализации P4 (переоформить в D-запись состава). Обсуждение §3 остаётся Resolving до recon | Прямой steer владельца сместил Q2 с «минимум PG+Redis» на «мультибаза + опциональный Redis»; выбор состава требует фактуры DB-специфичных конструкций (запущен recon). RAG:— (repo-grounded: ответ владельца Q2, open-questions.md) |
| D24 | 2026-07-18 | Реоформление D23 «состава матрицы» при детализации P4: (а) матрица P4 = SQLite (:memory:, быстрый дефолт) + Postgres 16 (приоритет) + MySQL 8 (first-class); MariaDB опц.; Redis-путь тестируется И на database cache-драйвере (shared-hosting без Redis); (б) collation-hardening RBAC-ключей — ОТДЕЛЬНЫЙ санкционированный code-change item P4.7 (driver-conditional `utf8mb4_bin` на panel_id/permission_key/model_type/scope_class/context_* под MySQL/MariaDB), НЕ input-канонизация; (в) фаза выросла с 6 до 7 items; (г) контракт-блок P4 обновлён: collation-миграция P4.7 — санкционированное исключение из «прикладной код не меняется» (portability-conformance под уже-корректный case-sensitive контракт, @api-снапшот P3 не задет — миграции не @api); (д) P4.6 НЕ переделывает D-01/D-06 (закрыты P1), только verify | Мандат D23 «финальный состав + воркэраунды фиксируются при детализации P4 (переоформить в D-запись)»; recon-db-portability §3/§7 — case-insensitive collation MySQL схлопывает ключи = security-корректность-баг, binary collation fail-closed (D10); §7-критерий оркестрации не сработал (scope P4 связан по данным) → manual item-by-item. RAG:— (repo-grounded: findings/recon-db-portability-2026-07-18.md §3/§7, plan.md D10/D23) |
| D25 | 2026-07-18 | Scope релиза P5.2 (выбор владельца, 2026-07-18): тег v0.3.0 + GH Release + CHANGELOG в приватном монорепо; split/Packagist ОТЛОЖЕНЫ как follow-up вне плана — split-репо `axioma-studio/azguard-*` не существуют (404), `MONOREPO_SPLIT_TOKEN` не настроен, монорепо приватный (`axiomasoft/azguard-private`), публикация кода = отдельное решение владельца. split.yml нейтрализуется job-guard'ом `if: vars.SPLIT_ENABLED == 'true'` (repo-переменная не создаётся — дефолт выключено; `vars` доступен в job-if, `secrets` — нет). Санкционированное исключение из «код не меняется» для P5.2: guard split.yml + версия-бампы манифестов (сателлиты `^0.2→^0.3`, versions map `0.3.0`). Контракт-блок P5 обновлён (reconcile) | Без guard'а тег гарантированно роняет split-джоб (шум на первом же релизе, маскирует реальные падения); констрейнт `^0.2` после breaking P1/P2 ложен, а release.yml гейтит только `*`. RAG:✅ 2026-07-18 (gh api: 404/секреты; GitHub Docs Contexts: vars в jobs.<id>.if — findings/P5-rag-release-guard-2026-07-18.md) |
| D26 | 2026-07-18 | Механика закрытия P5: (а) миграцию root/→docs исполняет item P5.3 (контент-работа: EN-перевод, RU-зеркала, VitePress-навигация) — НЕ команда архивации; шаг «docs из root/» архив-пайплайна §12 при `/task:plan-close archive` становится верификацией по migration-чеклисту handoff; (б) таблица судеб root/: package-hardening-track.md, api-surface.md, glossary.md → `docs/05_AI/` (внутренние, parity-exempt); semver-policy.md → `docs/introduction/versioning.md` (EN+RU); known-limitations → `docs/introduction/known-limitations.md` (EN+RU; если P3.3 оформил разделом — одна страница versioning); contracts/facade-cutline.md — остаётся в архиве (рабочая спека); (в) сам перенос плана в plans/archive/ — post-plan `/task:plan-close archive` после терминальности всех items (item не может архивировать план, в котором сам открыт) | Архив-команда (sonnet/low) не должна нести перевод и навигацию; docs/05_AI исключён из parity-гейта by design — дом внутренних RU-доков. RAG:— (repo-grounded: bin/docs-parity-gate.sh:20-26, скилл plan-protocol §12) |
| D27 | 2026-07-18 | **supersedes D10 (б)**: default-panel fallback резолюции query-scope (`PanelResolver::resolve(null) ?? resolveDefault(null)`) УПРАЗДНЁН — в `bootHasScopedRoles` панель резолвится только `PanelResolver::resolve(null)` (nullable). Причина: `resolveDefault(null)` = `Config::defaultPanel() ?? 'app'` механически НИКОГДА не возвращает null, поэтому fallback делал ветку D5 «нет панели → аддитивно применить все scope» недостижимой для ЛЮБОГО вызова без активной панели → строки с `scope->panel_id != default_panel` переставали фильтроваться (утечка видимости), падал anti-regression A1 (`ScopedRoleQueryScopePanelIsolationTest`), а premise C-02 «панель null даже после fallback» становился недостижимым. Следствия: (а) P1.1 сужается до ЕДИНСТВЕННОГО изменения — снять `runningInConsole`-bypass; панель-резолюция и аддитив D5 (строки 60/72-84) не трогаются, три isolation-кейса остаются зелёными; (б) вся семантика null-панели переезжает в C-02 (P1.2, `on_missing_panel` enum, дефолт `exception`), где кейс A1 осознанно re-baseline'ится под fail-closed-контракт; (в) утверждённый backlog W0 (C-01 = только console/queue-контракт) fallback не предписывал — D27 возвращает C-01 ровно к его scope | Прямое применение принципа владельца «максимальная надёжность, fail-closed, ничего не ослаблять» (brief/01-refinements.md, D10 преамбула): fallback ослаблял изоляцию — строгий вариант = его удаление. Развилка подтверждена владельцем на детализации P1.1 (2026-07-18). RAG:— (repo-grounded: packages/core/src/Support/PanelResolver.php:33-42/90-93, packages/core/src/Concerns/HasScopedRoles.php:60-84, tests/Feature/ScopedRoleQueryScopePanelIsolationTest.php:84-105) |
| D28 | 2026-07-18 | Routing актуализирован построчно по прямому указанию владельца («fable — только где оправдан»): критерий — fable/high остаётся там, где есть ОТКРЫТЫЕ design-решения либо цена семантической ошибки необратима (P2.5 спека-вход заморозки, P2.9 флип семантики matcher, P3.2 механизм заморозки, P5.1 канон флота); предписанная реализация по уже запинённым канонам — sonnet/high manual (P2.4, P2.6, P3.1, P3.3); editorial/механика без открытых решений — sonnet/medium plan-exec (P2.7, P2.8, P2.10). Блочная строка P2.1–P2.10 сохранена как историческая для исполненных P2.1–P2.3 | Указание владельца в сессии P2.3 (2026-07-18): ресурсы fable тратить только оправданно; блочная маршрутизация D14 не различала разнородные items внутри фазы (docs-items не трогают @api-поверхность — аргумент «всё замораживается P3» к ним не применяется). RAG:— (repo-grounded: plan.md §3, phases/P2.md P2.4–P2.10, D17/D18/D20/D21) |
| D29 | 2026-07-18 | **уточняет D19**: сверка P2.5 с фактом кода показала — `tryPermission`/`panelIdForPermission` НЕ мёртвые (интерфейс-потребители: `Permissions/PermissionName.php:31` — шов всех grant-путей, вошёл 3e9adb1 ДО аудита вне фасадной формы grep'а C-B4; `Concerns/HasScopedRoles.php:324` — P1-W1 ed64c93/B-04, ПОСЛЕ аудита) → P3.1 удаляет только 2 @method-СТРОКИ фасада, методы `AzGuardManagerInterface`/`AzGuardManager` получают `@internal` (не удаляются); SSOT выреза — `root/contracts/facade-cutline.md` | Полное удаление методов сломало бы оба внутренних шва; цель cut-line — сужение ПУБЛИЧНОЙ поверхности — достигается де-публикацией (паттерн shorthands P2.3). RAG:— (repo-grounded: facade-cutline.md §3, git log -S tryPermission) |
| D30 | 2026-07-18 | Ре-дизайн эскалации P4.2 (§10): реальный прогон лейна вскрыл portability-баги вне Scope P4.2 → ремедиация как санкционированные контракт-блоком фазы багфиксы. Recon якорей (findings/P4.2-remediation-anchors) свёл 8 симптомов к 4 корням: 2 «класса» — КАСКАДЫ (PG boolean-суперадмин + PG transaction-abort + MySQL «table exists» — следствия сбоя миграции 000005), отдельных items под них НЕТ (verify после фикса). Структура — file-ownership split (каждая миграция правится одним item'ом): P4.2 re-scope (харнесс+тест-фикстура R4), новый **P4.8** (миграция 000005: COALESCE morph-aware + MySQL down-order), P4.7 расширен (000002/000010: +key-length), новый **P4.9** (filament LIKE-escape), новый **P4.10** (green-proof+CI-джоб). Фаза P4: 7→10 items. Порядок последователен (общий docker-стенд + MySQL-каскад маскирует нижележащее → R1 первым; оркестрация НЕ объявляется). @api-снапшот P3.2 не задет (миграции/query не @api); фиксы едут в v0.3.0 | Эскалация P4.2 (handoff after P4.2); recon-консолидация каскадов экономит 2+ ложных items; file-ownership минимизирует coupling (research/04 §2/§3). RAG:— (repo-grounded: findings/P4.2-db-portability-failures.md, findings/P4.2-remediation-anchors-2026-07-18.md, research/04-p4.2-remediation.md) |
| D31 | 2026-07-18 | **re-scope P4.2** (снимает 🔴 эскалацию): P4.2 сужен до КОММИТА уже написанного локального БД-лейн-харнесса (env-TestCase + composer test:pgsql/test:mysql + union-doc + генерализованный rollback-тест) + фикс тест-фикстуры R4 (`ContextTableNameConfigTest` строит кастом-таблицу без `expires_at`, который штатная миграция 000011 добавляет — тест-баг, не app). CI-джоб `test-db-matrix` и полный green PG/MySQL ОТЛОЖЕНЫ в P4.10 (добавляются зелёными после ремедиации). Acceptance P4.2 = sqlite green + лейны ЗАПУСКАЮТСЯ и воспроизводят baseline (харнесс-инструмент готов), НЕ полный green. Статус 🔴→⬜ | Инфраструктура лейна валидирована в части, не зависящей от багов (sqlite 667/667); блокировать коммит рабочего инструмента нельзя; R4 — тест-scope, разблокирует чистую диагностику (findings-anchors §5). RAG:— (repo-grounded: findings/P4.2-remediation-anchors-2026-07-18.md §5) |
| D32 | 2026-07-18 | **расширяет D24 (P4.7)**: MySQL-лейн упал `Specified key too long (3072 bytes)` на `az_direct_grant_unique` (000002) и `az_ctx_roles_unique` (000010) — 6× varchar(255) под utf8mb4 > лимит ключа InnoDB. Key-length-bounding сливается в P4.7 (те же ключевые колонки тех же файлов, что и collation → один проход, без двойного редактирования миграций). P4.7: (а) подобрать длины расчётом «сумма байт < 3072» (НЕ резать `model_type` вслепую до 191 — FQCN длиннее, pre-mortem: коллизия ключей; префикс-индекс/сузить прочие); (б) driver-conditional utf8mb4_bin (как D24). scopes-индекс `model_has_scopes_unique` уже несёт 191-префикс (P4.8-владение) — P4.7 его не трогает | Length и collation — одни колонки одних файлов; раздельные items = двойная правка 000002/000010 (P4.7 обязан не откатить длины). RAG:— (repo-grounded: findings/P4.2-remediation-anchors-2026-07-18.md §2, research/04 §2) |
| D33 | 2026-07-21 | Остаток P4/P5 переведён с Claude-селекторов на provider-neutral routing с Codex-проекцией: economy→GPT-5.6 Luna, implementation→GPT-5.6 Terra, frontier→GPT-5.6 Sol. Sol не тратится на frozen-spec implementation; он обязателен для P5.1, phase-audit и независимого review P4.8/P4.7/P4.4. Исторические строки/логи моделей не переписываются. Незакоммиченный P4.8 diff признан входом продолжения, а не новой реализацией | Качество/стоимость: официальная линейка позиционирует Sol как frontier, Terra как balance, Luna как cost-sensitive; локальный Codex adapter даёт тот же neutral mapping. Read-only supporting work можно дешево изолировать, но final correctness verdict остаётся у Sol. RAG:✅ 2026-07-21 (findings/codex-model-routing-2026-07-21.md); RAG:— (repo-grounded: git status/diff, provider-commands.md, .codex/agents/*.toml) |

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
| 2026-07-18 | plan-run solo/fable (fable/high) | P0.6 закрыт: REGISTER 44 находки + бэклог W0/W1/W2=1/12/14, 9 кластеров P2, гейт владельца утверждён (D9) — детали см. phases/P0.md P0.6 Completion Notes |
| 2026-07-18 | plan-close/sonnet | Фаза P0 закрыта: 6/6 items 🟢, Phase Handoff сверен, docs-sync не требуется, lint 0/0 — детали см. phases/P0.md Phase Handoff |
| 2026-07-18 | plan-design/fable (design pass 3) | P1 детализирована до DoR: 4 items (W0 C-01 · W1 12 Major · W2 14 Minor/Nit · adversarial review), контракт query-scope D10 (fail-closed по решению владельца), D11–D13, Routing P1 уточнён, v0.3.0. Фокус далее: P2 (9 кластеров) |
| 2026-07-18 | plan-design/fable (design pass 3) | P2 детализирована до DoR: 10 items, 4 развилки разрешены владельцем (D14–D18), синтез research/03-p2-canon.md, Routing P2 (fable/high manual), v0.3.1 — детали см. phases/P2.md. Фокус далее: P3 |
| 2026-07-18 | plan-design/fable | Владелец разрешил Q1 (тег v0.3.0 — D22) и дал steer по Q2 (мультибаза PG-приоритет + опциональный Redis — D23); recon-db-portability закрыт (findings/): PG-only фич нет, риск — collation RBAC-ключей MySQL; Обсуждение §2/§3 Resolved, open-questions обновлён |
| 2026-07-18 | plan-design/fable (design pass 3) | P3 детализирована до DoR: 3 items (cut-line фасада → api-surface · snapshot-гейт заморозки · SemVer-политика+UPGRADING), reconcile контракт-блока (D19), D20–D21, v0.3.2 — детали см. phases/P3.md. Фокус далее: P4 |
| 2026-07-18 | plan-design/fable (design pass 3) | P4 детализирована до DoR: 7 items (стенд·БД-лейн·paratest·race C-05/C-14·mutation-ratchet·чистка·collation MySQL), D24 (состав матрицы+collation P4.7+рост 6→7), RAG P4-paratest, v0.3.3 — детали phases/P4.md. Фокус далее: P5 → finish |
| 2026-07-18 | plan-design/fable (design pass 3) | P5 детализирована до DoR (3 items: шаблон дорожки · релиз v0.3.0 · миграция root/→docs), scope релиза решён владельцем (split отложен — D25), D26 (механика закрытия/архива), RAG P5-release-guard, roadmap.md собран, Design Passes 3/3, v0.3.4. Фокус далее: `finish` |
| 2026-07-18 | plan-design/fable (finish) | Сквозной reconcile 6 фаз: контракт-блоки, producer→consumer артефактов, coupling D6, Required Reads — консистентны; 0 скелетов/маркеров, развилки Resolved, roadmap↔Routing сверен. Дыр нет. v0.3.5 → готов к `plan-audit design` — детали handoff.md «Done» |
| 2026-07-18 | plan-design/opus (P1.1 re-detail) | Эскалация P1.1 снята владельцем → D27 (supersedes D10-б: default-fallback упразднён, fail-closed); P1.1 сужен до снятия console-bypass, статус 🔴→⬜, C-02/P1.2 реконсилен. v0.3.6 — детали phases/P1.md P1.1 + §5 D27 |
| 2026-07-18 | plan-run/sonnet-high | P1.1 закрыт (🟠): console-bypass убран из `bootHasScopedRoles` (C-01), новый тест `ScopedRolesConsoleQueueTest`, полный сьют 559 passed без регрессий — детали см. phases/P1.md P1.1 Completion Notes |
| 2026-07-18 | plan-run/sonnet-high | P1.2 закрыт (🟠): 12 находок W1 закрыты, 13 коммитов — детали см. phases/P1.md P1.2 Completion Notes/Known Deviations |
| 2026-07-18 | plan-run/sonnet-medium | P1.3 закрыт (🟠): 14 находок W2 закрыты, 15 коммитов, 600 тестов зелёные — детали см. phases/P1.md P1.3 Completion Notes/Known Deviations |
| 2026-07-18 | plan-run/fable-high | P1.4 закрыт (🟢): review диффа P1 — 16 находок, 6 Major+тест-гэп C-11 сняты 10 фикс-коммитами, 610 тестов — детали см. phases/P1.md P1.4 Completion Notes |
| 2026-07-18 | plan-close/sonnet | Фаза P1 закрыта: 4/4 items терминальны (🟠/🟠/🟠/🟢), Phase Handoff сверен, docs-sync не требуется (фиксы восстанавливают уже задокументированное поведение), lint см. handoff.md — детали см. phases/P1.md Phase Handoff |
| 2026-07-18 | plan-run/fable-high | P2.1 закрыт (🟠): Support/ распущен — 11 файлов в доменных неймспейсах, канон двух домов контрактов в ADR — детали см. phases/P2.md P2.1 Completion Notes |
| 2026-07-18 | plan-run/fable-high | P2.2 закрыт (🟠): 6 структурных baseline разрешены уточнением контрактов (label() чинит runtime-баг Filament), analyse 0 errors — детали см. phases/P2.md P2.2 Completion Notes |
| 2026-07-18 | plan-run/fable-high | P2.3 закрыт (🟠): единый immutable fluent-корень forUser()→inContext() (registered-extension шов) + TTL-парность context (миграция expires_at), 623 теста — детали см. phases/P2.md P2.3 Completion Notes |
| 2026-07-18 | plan-run/fable-high | Routing актуализирован построчно (D28): P2.4/P2.6/P3.1/P3.3 → sonnet/high, P2.7/P2.8/P2.10 → sonnet/medium plan-exec; fable остаётся на P2.5/P2.9/P3.2/P5.1. v0.3.9 |
| 2026-07-18 | plan-run/sonnet-high | P2.4 закрыт (🟠): AzGuardPlugin fluent enforce/source/abilities/keyTemplate/case (config→fallback) + `make()` container-swap; middleware `::using()` на 4 классах; PanelCheckAccess breaking arg-order permission,panel — детали см. phases/P2.md P2.4 Completion Notes/Known Deviations. v0.3.10 |
| 2026-07-18 | plan-run/sonnet-high | P2.6 закрыт (🟢): `AzGuard::fake()` — Recorder + assertGranted/assertDenied/assertChecked (простая форма + closure), 657 тестов — детали см. phases/P2.md P2.6 Completion Notes. v0.3.11 |
| 2026-07-18 | plan-run/fable-high | P2.5 закрыт (🟢): cut-line target-спека фасада `root/contracts/facade-cutline.md` (17 вердиктов + пост-recon fake/assert*) + D29 (резолверы не мёртвые → @internal вместо удаления методов) — детали см. phases/P2.md P2.5 Completion Notes. v0.3.12 |
| 2026-07-18 | plan-run/fable-high | P2.9 закрыт (🟠): дефолт matcher → Hierarchical, legacy opt-out через инвертированный `features.wildcard_permission`, R7 закрыт, F4/F40/F51 верифицированы, 664 теста — детали см. phases/P2.md P2.9 Completion Notes. v0.3.13 |
| 2026-07-18 | plan-exec/sonnet-medium | P2.7 закрыт (🟢): `root/glossary.md` (guard=бренд/context=runtime/scope=persist), multiple-guards.md через панели (ложная panel↔guard-декларация убрана), routing-раздел «context или scope?» в context.md+entity-scopes.md, RU-зеркала — детали см. phases/P2.md P2.7 Completion Notes. v0.3.14 |
| 2026-07-18 | plan-exec/sonnet-medium | P2.8 закрыт (🟢): `docs/introduction/headless-quick-start.md`+RU (doc-only minimal-setup), `AzGuardDiagnostics` — onboarding-hint при 0 панелей (warnings-канал, не error) + тест, EN/RU intro-навигация, 666 тестов — детали см. phases/P2.md P2.8 Completion Notes. v0.3.15 |
| 2026-07-18 | plan-exec/sonnet-medium | P2.10 закрыт (🟢), фаза P2 терминальна (5🟢+5🟠/10): docs EN/RU свип + arch-тесты консолидированы — детали см. phases/P2.md P2.10 Completion Notes. v0.3.16 |
| 2026-07-18 | plan-close/sonnet | Фаза P2 закрыта: 10/10 items терминальны (5🟢/5🟠), Phase Handoff phases/P2.md заполнен (агрегат Known Deviations по 10 items + SemVer-breaking список), docs-sync подтверждён (P2.10 сквозной свип + root/architecture.md/glossary.md), lint см. handoff.md — детали см. phases/P2.md Phase Handoff |
| 2026-07-18 | plan-audit/opus-xhigh | Аудит фазы P2 (вердикт ATTENTION, движение вперёд): 10/10 коммитов сверены, F1–F7 — дефекты бухгалтерии/полноты, НЕ реопен — детали см. phases/P2.md `## Audit P2 — 2026-07-18` |
| 2026-07-18 | plan-run/sonnet-high | P3.1 закрыт (🟢): cut-line фасада по facade-cutline.md/D29 (tryPermission/panelIdForPermission/isSuperAdmin → @internal, 2 @method-строки убраны), root/api-surface.md создан + свёрнуты follow-up находки Audit P2 F1/F3/F4/F6 — детали см. phases/P3.md P3.1 Completion Notes |
| 2026-07-18 | plan-run/fable-high | P3.2 закрыт (🟢): snapshot-гейт заморозки @api-поверхности (32 типа, сигнатуры+имена параметров, фикстур+регенерация под D#), самопроверка «мутация→red» ✓ — детали см. phases/P3.md P3.2 Completion Notes. v0.3.18 |
| 2026-07-18 | plan-run/sonnet-high | P3.3 закрыт (🟢): semver-policy.md+known-limitations.md созданы, UPGRADING 0.2→0.3 консолидирован EN+RU, F2 (Audit P2) закрыт — детали см. phases/P3.md P3.3 Completion Notes. v0.3.19 |
| 2026-07-18 | plan-close/sonnet | Фаза P3 закрыта: 3/3 items терминальны (🟠/🟢/🟢), Phase Handoff phases/P3.md заполнен (агрегат Known Deviations, docs-sync подтверждён), lint см. handoff.md — детали см. phases/P3.md Phase Handoff |
| 2026-07-18 | plan-exec/sonnet-medium | P4.1 закрыт (🟢): docker-compose.yml + Makefile + DEVELOPMENT.md «Local database matrix» — все три сервиса healthy — детали см. phases/P4.md P4.1 Completion Notes |
| 2026-07-18 | plan-design/opus (P4.2 ре-дизайн) | Эскалация P4.2 снята: ремедиация portability-багов (D30–D32), P4 7→10 items, roadmap пересобран, v0.3.21 — детали phases/P4.md, research/04-p4.2-remediation.md |
| 2026-07-18 | plan-exec/sonnet-medium | P4.2 закрыт (🟢): БД-лейн-харнесс закоммичен, фикстура ContextTableNameConfigTest дополнена expires_at (R4), PG/MySQL-лейны запущены и воспроизводят baseline — детали см. phases/P4.md P4.2 Completion Notes |
| 2026-07-21 | plan-design/GPT-5.6 | Codex-проекция остатка плана (D33): открытые items маршрутизированы через Luna/Terra/Sol, добавлены независимые review checkpoints и cold-start продолжения незакоммиченного P4.8 — детали roadmap.md и handoff.md. v0.3.22 |

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

**Статус:** Resolved → D22 (владелец: «0.3», 2026-07-18)

### 3 — Состав docker-матрицы P4

- **Вариант A — Postgres 16 + Redis.** Минимум, закрывающий уроки vaulter (PG-only
  код) и follow-up T6 (кросс-процессный Redis race-тест).
- **Вариант B — Postgres + MySQL/MariaDB + Redis.** Полная матрица потребителей RBAC.

**Статус:** Resolved → D23 → **финализировано D24** (детализация P4): матрица = SQLite +
Postgres 16 + MySQL 8 (MariaDB опц.), Redis-путь И на database cache-драйвере; collation-hardening
RBAC-ключей — санкционированный item **P4.7** (driver-conditional `utf8mb4_bin`, fail-closed).
Recon findings/recon-db-portability-2026-07-18.md подтвердил: PG-only фич нет, пакет portable;
единственный содержательный риск — case-insensitive collation MySQL (схлопывает ключи).

### 4 — Grant-грамматика: immutable-with vs мутабельные builders + унификация core↔context

- **(рекоменд.) Вариант A — immutable-with + единый корень.** `final readonly` builders,
  единый `AzGuard::forUser()->…->grant()` для core И context, shorthands → @internal.
  Плюсы: консистентно с F49 (Values), одна грамматика, арх-ратчет. Минусы: шире переписывание.
- **Вариант B — мутабельный, унифицировать только вход.** Плюсы: меньше правок. Минусы:
  расходится с F49-каноном, builders остаются вне ратчета.

**Статус:** Resolved → D16 (владелец: «лучшие решения», D14)

### 5 — TTL-парность context-грантов

- **(рекоменд.) Вариант A — TTL-парность.** ContextGrantBuilder получает until()/ttl() +
  active()-фильтр (миграция expires_at). Плюсы: одна грамматика, потребитель не учит две.
  Минусы: новая колонка/миграция.
- **Вариант B — «context-грант бессрочен by design».** Плюсы: меньше кода. Минусы:
  асимметрия грамматики core↔context остаётся.

**Статус:** Resolved → D16 (владелец: идеальный fluent, D14)

### 6 — Headless-порог: doc-only vs рантайм panel-less

- **(рекоменд.) Вариант A — doc-only minimal-setup.** headless quick-start + doctor-hint,
  рантайм не меняется. Плюсы: fail-closed сохраняется, YAGNI. Минусы: порог снижен только
  документацией.
- **Вариант B — рантайм panel-less/lenient путь.** Плюсы: реальный минимальный прод-путь.
  Минусы: риск ослабить fail-closed, крупнее.

**Статус:** Resolved → D14 (fail-closed приоритетнее, YAGNI)

### 7 — AzGuard::fake(): строить в 0.3.0 vs отложить

- **(рекоменд.) Вариант A — строим.** Recorder + assertGranted/Denied/Checked (+closure).
  Плюсы: закрывает Testing-DX гэп (акцент брифа), канон RAG:✅. Минусы: новый код.
- **Вариант B — отложить post-0.3.0.** Плюсы: меньше scope. Минусы: Testing DX остаётся слабым.

**Статус:** Resolved → D14 (подтверждённый гейтом кластер, акцент интеграционной поверхности)

### 8 — Scope релиза P5.2: split/Packagist сейчас vs отложить

- **(рекоменд.) Вариант A — тег + GH Release, split отложить.** Плюсы: не публикует код
  приватного пакета, ноль новой внешней инфры, релиз-конвейер (release.yml/changelog.yml)
  работает как есть. Минусы: пакеты пока не ставятся через Packagist.
- **Вариант B — полный публичный релиз.** One-time setup (3 репо под axioma-studio, PAT →
  MONOREPO_SPLIT_TOKEN, Packagist). Плюсы: полный конвейер. Минусы: публикация приватного
  кода — отдельное решение, инфра-работа вне кода.
- **Вариант C — split в приватные репо без Packagist.** Плюсы: потребители через
  VCS-repository. Минусы: инфра-работа при недоказанной потребности.

**Статус:** Resolved → D25 (владелец: Вариант A, 2026-07-18)
