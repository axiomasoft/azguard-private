# HANDOFF — 2026-07-21 — after P4

**Next:** run-items: task:plan-run 2026.07.18-AZGUARD-STABLE P4.8

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | skills.repository · command.filesystem-read · command.filesystem-write · command.git-read · command.git-write |
| Context | same-session — item |
| Суть | Продолжить уже начатый P4.8 на GPT-5.6 Terra/high: сначала инвентаризировать dirty diff, затем довести migration 000005 и доказательства PG/MySQL до acceptance; перед закрытием — независимый GPT-5.6 Sol/high review |

```
$ task:plan-run 2026.07.18-AZGUARD-STABLE P4.8

РЕЖИМ: remediation — продолжение уже начатого 🟡 P4.8, не реализация с нуля
ВХОД: handoff.md → plan.md D30–D33 → phases/P4.md P4.8 → research/04-p4.2-remediation.md → findings/P4.2-remediation-anchors-2026-07-18.md; затем git status/diff
СКОУП: 1) сохранить и проверить существующий migration-diff 000005; 2) отделить временную DebugPgAbort-диагностику от deliverable-тестов; 3) прогнать P4.8 Validation на sqlite/PG/MySQL; 4) заказать read-only Sol/high review diff; 5) исправить findings на Terra/high; 6) закрыть item по двухкоммитной топологии
НЕ ТРОГАТЬ: P4.7 (000002/000010), P4.9 (Filament LIKE), P4.10 deliverables; `.github/workflows/tests.yml` не коммитить до P4.10; не переписывать/не стирать чужой dirty diff без инвентаризации
```

**Provider fallback (если `task`-plugin не установлен):** запустить новую Codex-сессию на
GPT-5.6 Terra/high и вставить code block выше как обычный prompt; затем потребовать следовать
`plan-protocol` и полной спецификации P4.8.

**Done:** P4.1 🟢 (Docker-стенд) и P4.2 🟢 (DB-lane harness + `expires_at` fixture). План
адаптирован под Codex в D33: provider-neutral routing, Luna/Terra/Sol projection и review
checkpoints. `plan-lint` зелёный; остаётся только optional warning об отсутствующем `index.md`.

**Current dirty tree — считать входом P4.8, не мусором:**

- `packages/core/database/migrations/2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php`
  — рабочая реализация P4.8: morph-aware COALESCE и MySQL FK/index down-order.
- `tests/Feature/DebugPgAbortTest.php` + registration в `tests/Pest.php` — временная
  диагностическая проба; файл не входит в declared Deliverables P4.8 и не должен быть
  закоммичен как `Debug*`. Сначала извлечь доказательство, затем либо перенести нужные cases в
  declared target test, либо убрать только после проверки.
- `plans/2026.07.18-AZGUARD-STABLE/phases/P4.md` — P4.8 уже переведён в `🟡 In progress`;
  это bookkeeping, не item-commit.
- `.github/workflows/tests.yml` — отложенный CI hunk, владелец P4.10; не включать в P4.8.

**Remaining:** P4.8 → P4.7 → P4.9/P4.10 (B6) → P4.3 → P4.4 → P4.5 → P4.6 →
`plan-close P4` → новая Sol/xhigh `plan-audit P4` → P5.1 → P5.2/P5.3 →
`plan-close P5` → новая Sol/xhigh `plan-audit P5` → archive.

**Review economy:** Terra пишет frozen-spec implementation; Sol используется для независимого
review P4.8/P4.7/P4.4, общего B6 checkpoint и phase audits; Luna — только read-only recon,
изолированные test-runs и сжатие логов, не финальный verdict.

**Docker stand:** локальные занятые дефолтные порты обходились через
`PGSQL_PORT=25432`, `MYSQL_PORT=23306`, `REDIS_PORT=26379`. Перед реальными lane-runs проверить
`docker compose ps`; не предполагать, что контейнеры пережили предыдущую сессию.

**Sources of truth:** `plan.md` v0.3.22 (§3, D30–D33) · `phases/P4.md` P4.8 ·
`roadmap.md` review checkpoints · `findings/codex-model-routing-2026-07-21.md` ·
`research/04-p4.2-remediation.md` · `findings/P4.2-remediation-anchors-2026-07-18.md` ·
`findings/P4.2-db-portability-failures.md` · current `git status`/`git diff`.

**Open risks:** временный Debug-тест может дать ложную уверенность, если покрывает только
duplicate constraint, но не реальный abort cascade; MySQL FK/index recreation должна сохранять
исходный contract; ULID config-timing ещё требует фактического verdict. Любой независимый
portability-баг после P4.8 — §10, не scope expansion на месте.

**Workarounds/Deferred/Open questions:** CI hunk + полный lane green → P4.10;
utf8mb4_bin UPGRADING note → P4.10. Observer выключен: `.claude/observer.env.ini` отсутствует,
поэтому Claude JSONL не анализировался; восстановление состояния выполнено по plan/handoff/journal,
git history и dirty diff.
