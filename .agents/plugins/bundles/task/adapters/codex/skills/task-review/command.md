<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/review.md
Canonical SHA-256: sha256:47375319311f6e441bfc9cff37542ac05136d109f519a6e43fbedb4003a66c0c
Adapter: task.codex-command/1.0.3
-->
Это задача РЕВЬЮ (read-only). Ввод пользователя: «$ARGUMENTS».
Модель/effort запинены (frontier/xhigh) — не переключай вручную. Файлы НЕ меняй.

**Зачем оркестратор.** Кейс-мотиватор: атрибуты Laravel-моделей не поймал ни один ревью в 5
проектах — правило жило в скилле, но ревью его не открывало. Laravel-ревью-паки (P7.5) — частный
случай; здесь он обобщён: **новый скилл в реестре ⇒ новое измерение ревью автоматически**,
без правки этой команды.

## Шаг 0 — диспетчер профилей (таксономия `quality/artifact-review`)

Определи тип артефакта на входе и профиль:
- **Код** (дифф / ветка / PR) → профиль **code**, Stage A/B/C ниже целиком.
- **Документация** (`docs/`, README, гайды) → профиль **doc**: конституция = канон структуры
  docs (Diátaxis / канон vault); измерения — соответствие канону, staleness против кода
  (примеры/маршруты/флаги сверить с реальным кодом), миграция план→docs без потерь,
  wiki-связность/битые ссылки. Stage A/B применяй к doc-скиллам реестра (writing-style и т.п.),
  Stage C — тот же.
- **План/спека** (`plans/<ID>/…`) → НЕ эта команда: профиль плана реализует `task:plan-audit`,
  перенаправь туда.
- Смешанный дифф (код + docs) → оба профиля, findings не смешивать — у каждой находки профиль.

## Stage A — детект стека → резолв скиллов

1. **Детект стека** по манифестам корня/затронутых пакетов: `composer.json` → `php`/`laravel`
   (+пакеты: `filament`, `livewire`…), `pubspec.yaml` → `dart`/`flutter`, `package.json` →
   `js`/`ts` (+`vue`, `react`…). Из манифестов выведи набор тегов стека + от типа диффа —
   теги дисциплин (`naming`, `conventions`, `git`, `writing`, `testing`, `security`).
2. **Резолв применимых скиллов** из реестра по тегам: `skills.json` (в проекте — vendor-копия
   `.ai/skills`/`.claude/skills`; SSOT — реестр skiller). Матчинг: тег скилла ∈ тегам стека/
   дисциплин диффа. Выпиши список «применимые скиллы» с путями — это измерения Stage B.

## Stage B — измерения: классика + conformance-finder'ы

1. **Классические измерения** (профиль code): correctness / security / perf. Свежий контекст —
   суди по диффу (`git diff <base>...<branch>` / `gh pr diff <N>`, slice, не файлы целиком);
   свой код не «полируй», атакуй дизайн. Глубину масштабируй под РИСК (blast-radius), не размер:
   на рисковом срезе (transactions / queue / concurrency / data-integrity / connectors) —
   adversarial: перечисли failure-modes, для каждого построй конкретный сценарий поломки.
2. **Conformance-finder на КАЖДЫЙ применимый скилл** из Stage A: finder получает секцию
   `## Conformance` скилла как чек-лист императивов и проверяет дифф против каждой строки.
   Нет секции `## Conformance` — finder берёт свод правил из тела SKILL (чеклист качества /
   жёсткие запреты). Один скилл = одно измерение; в fan-out-раскладке — finder-агент на скилл
   (шаблон `wf-audit-adversarial.js`), в single-agent — последовательные проходы.
   Оркестрация мультиагентным `*.js`-скриптом → сначала скилл `general/workflow-craft` (матчинг 4
   канонических шаблонов + обязательные стадии design vs dev) и конструктор
   `packages/task/workflows/README.md`, не изобретай оркестрацию с нуля.
3. **Glossary-conformance** (конституция = `CONTEXT.md` затронутых пакетов + term-index
   `02-Knowledge/00-Strategy/Ecosystem_Glossary.md` для кросс-пакетных терминов): дифф
   не вводит термин, конфликтующий с языком `CONTEXT.md` пакета; не использует
   `_Avoid_`-синоним вместо канона; термин, реально пересёкший 2+ пакетов (Rule of Two),
   отражён в term-index. Дисциплина и Rule of Two — в `architect/domain-modeling`
   (не дублируется здесь).
4. **Внешний факт под сомнением** (версия/API библиотеки, «ещё актуально», best practice) —
   верифицируй, не утверждай по памяти: лестница `verify-claims` (context7 → агрессивно
   `perplexity-web` → WebSearch), запрос по `query-craft`. RAG-ступень исполняй там, где
   доступен `perplexity-web` — НЕ делегируй тул-бедному субагенту.

## Stage C — adversarial verify

1. Дедуп сырых находок Stage B, затем adversarial-проверка каждой: воспроизводим ли
   failure_scenario на этом диффе, не ложный ли матч правила. Отсев только с обоснованием.
2. **Детект ≠ гейтинг.** Репортишь ВСЕ подтверждённые находки. Формат находки (оси —
   `quality/artifact-review`): `severity ∈ {Blocker,Major,Minor,Nit}` + лейбл
   `issue/suggestion/question/nitpick` + `file:line` + `failure_scenario` +
   **ссылка скилл→правило** (`<bucket>/<skill> ## Conformance: "<строка правила>"`;
   для классики — измерение correctness/security/perf). При сомнении severity ставь ВЫШЕ,
   сам не отбрасывай.
3. Прогони статику/целевые тесты проекта, если уместно. Кода НЕ меняй, дерево чистое.
   Фиксы по находкам — не в этой сессии: отдельными коммитами (P7.4, см. artifact-review).
