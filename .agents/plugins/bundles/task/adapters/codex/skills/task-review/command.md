<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/review.md
Canonical SHA-256: sha256:c04416db5b4c7e82a9fc2194453ea1c4c05809ef6fd0f425a1f0f929c315d40c
Adapter: task.codex-command/1.0.16
-->
Это read-only review: «$ARGUMENTS». Файлы не меняй.

Сначала определи артефакт и наблюдаемый риск, затем выбери глубину сам:

- **quick** — документация, конфиг или малый локальный diff: один проход по correctness и явно
  применимым правилам проекта;
- **standard** — обычный кодовый diff: correctness/security/performance по затронутому срезу,
  подходящие целевые проверки;
- **adversarial** — security/auth, destructive migration, payment/accounting, concurrency/shared
  state, irreversible external effect или public API/schema: конкретные failure scenarios и, если
  отдельная точка зрения реально нужна, один независимый finder/verifier.

Используй diff как основной контекст. Открывай manifests, локальные инструкции и skills только
когда они относятся к изменённому стеку или поведению. Не сканируй весь registry, glossary,
callers или тестовый набор ради полноты. Смежные правила проверяй одним проходом; 1–2 применимых
skills проверяй сам без fan-out. Меняющуюся внешнюю предпосылку сверяй с первичным источником.

Для каждой подтверждённой находки укажи severity (`Blocker|Major|Minor|Nit`), confidence,
`file:line`, конкретный failure scenario и основание (правило проекта или
correctness/security/performance). Severity определяется влиянием, а не строгостью формулировки
правила. Отсекай совпадения без воспроизводимого влияния. Если находок нет, скажи это прямо и
назови только существенный непроверенный риск, если он остался.

Запускай статический анализ или тесты лишь когда они проверяют гипотезу review. Сохраняй dirty
baseline и передавай исправления исполнителю.
