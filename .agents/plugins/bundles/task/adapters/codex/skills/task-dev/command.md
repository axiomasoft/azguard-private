<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/dev.md
Canonical SHA-256: sha256:4b3a0743fabdf7e472128c11c0b5c7d713d2416055e5810b79aa778554deaee5
Adapter: task.codex-command/1.0.3
-->
Это задача РАЗРАБОТКИ. Ввод пользователя: «$ARGUMENTS».

Профиль `dev` (token-экономный, Lean-tiered). Модель/effort этой команды уже запинены (implementation/high) —
не переключай вручную.

**Вход — КОРОТКИЙ:** ссылка на durable план-файл (primary source) + что делаем. Детали, якоря и текст
правок бери ИЗ плана; не жди и не проси бриф на 2 страницы — вся подробность живёт в плане, не в промпте.

1. **Планируй** на текущей (implementation) модели: разложи задачу на вертикальные срезы
   (model→service→controller→test), каждый со своим риском `LOW|MED|HIGH` (blast-radius: concurrency /
   transactions / data-integrity / queue-semantics / connectors). Срез = завершённая вертикаль с проводкой
   и тестом, а не горизонтальная нарезка.
2. **Если установлен agent-pack** (`.claude/workflows/*-dev-loop.js` + агенты `*-slice-builder/test-writer/
   reviewer`) — гони срезы через него: impl→tests-to-green→risk-адаптивный review-gate. Модель/effort
   субагентов уже заданы по риску (implementation impl, frontier+xhigh на HIGH-review, economy тривиал) и перебивают сессию.
   Передай срезы как `args.slices`. Детальный статус фонового workflow-прогона — `swissknifeman wf status`,
   НЕ советовать `/workflows` (такой команды в окружении нет).
   Оркестрация мультиагентным `*.js`-скриптом → сначала скилл `general/workflow-craft` (матчинг 4
   канонических шаблонов + обязательные стадии design vs dev) и конструктор
   `packages/task/workflows/README.md`, не изобретай оркестрацию с нуля.
3. **Нет пака** — работай последовательно сам, ОДИН срез за раз на ОДНОЙ ветке: impl → тесты до зелёного →
   self-review под риск (на HIGH — adversarial: перечисли failure-modes → построй сценарий сбоя).
4. **Внешние факты (версия/API библиотеки, «ещё актуально», best practice) — верифицируй ДО фиксации в
   срезе**: лестница `verify-claims` (context7 → агрессивно `perplexity-web` → WebSearch); запрос
   формулируй по `query-craft` (версия+стек+формат). RAG-ступень исполняй там, где доступен
   `perplexity-web` — НЕ делегируй тул-бедному субагенту.
5. **Fan-out НЕ используй на coding** (только на research-фазе — он ≈15× токенов и оправдан лишь на
   breadth-first research). Гейты строгие; любая обходка в тесте → `[POSSIBLE-DEFECT]`.
6. Дерево чистое; durable план-файл — primary source. НЕ git push / PR без явной просьбы.
