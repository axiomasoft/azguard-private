# HANDOFF — 2026-07-22 — after P4.12

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.10

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | medium |
| Context | новая сессия; прочитать `plan.md`, этот handoff, P4.10 и Required Reads |
| Суть | Выполнить только full real-DB proof, зелёный CI/db-matrix и docs/finding scope P4.10; новый production fix не добавлять. |

```text
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.10
```

**Done:** P4.12 item-коммит `7ce4934` добавил private deterministic table-aware short morph-index
names в `MorphColumns`; long UUID fixture теперь вызывает production helper. Focused SQLite,
PostgreSQL и MySQL proof, Pint и PHPStan зелёные; отдельный Sol/high read-only review — APPROVE.

**Remaining:** P4.10 → P4.3 → P4.4 → P4.5 → P4.6; затем `task:plan-close` P4 и отдельный
Sol/xhigh audit фазы.

**Sources of truth:** `phases/P4.md` P4.10/P4.12, `plan.md` D30/D37,
`research/05-codex-execution-contract.md`, `research/04-p4.2-remediation.md`,
`findings/P4.2-db-portability-failures.md`, `findings/P4.10-uuid-morph-index-name-2026-07-22.md`.

**Open risks:** P4.10 должен воспроизвести full PostgreSQL/MySQL suites; только при обоих зелёных
можно принимать уже существующий dirty CI hunk. Full MySQL proof запускается с
`COMPOSER_PROCESS_TIMEOUT=900`. Shared worktree всё ещё содержит чужие незакоммиченные
`.github/workflows/tests.yml` и `tests/Pest.php`; P4.10 должен инвентаризировать и коммитить лишь
свои declared Files.

**Workarounds/Deferred/Open questions:** deferred — P4 phase Sol/xhigh audit только после
терминальности всех P4 items; open_questions — нет.
