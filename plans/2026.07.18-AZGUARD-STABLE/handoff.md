# HANDOFF — 2026-07-21 — after P4.7

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.9 P4.10

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | medium — frozen LIKE fix, then DB-lane proof/CI |
| Context | новая сессия; batch B6, строго P4.9 → P4.10 |
| Суть | P4.9 чинит MySQL LIKE-escape; P4.10 принимает чужой workflow/Pest diff, восстанавливает MySQL RefreshDatabase bootstrap после reset, доказывает green оба DB-лейна и добавляет CI job. |

```text
В отдельной implementation/medium Codex-сессии выполни P4.9 и P4.10 плана
2026.07.18-AZGUARD-STABLE через task:plan-exec. Прочитай research/05-codex-execution-contract.md,
plan.md, phases/P4.md P4.9/P4.10 и этот handoff. Строго сначала P4.9, затем P4.10. P4.10 владеет
незакоммиченными .github/workflows/tests.yml и tests/Pest.php только после scoped-инвентаризации.
На пустом disposable azguard_test воспроизведи MySQL RefreshDatabase bootstrap: P4.7 и существующий
ModelHasRolesScopes падают до test body с missing/already-existing model_has_roles/roles/migrations.
Не трогай P4.7 migrations 000002/000010; сначала классифицируй/fix harness в Scope P4.10, затем
повтори composer test:mysql включая CollationCaseSensitivityTest и получи общий Sol/high review B6.
```

**Done:** P4.7 item-коммит `4c4970f` ограничил ключи 000002/000010 и применил MySQL/MariaDB-only
`utf8mb4_bin`; key math 2560 B/3068 B < 3072 B, SQLite/PG и initial MySQL regression proof зелёные,
Sol/high read-only review — APPROVE.

**Remaining:** P4.9 → P4.10 → P4.3 → P4.4 → P4.5 → P4.6; затем `task:plan-close` P4 и отдельный
SoulXHigh phase review.

**Sources of truth:** `phases/P4.md` P4.7/P4.9/P4.10, `plan.md` D24/D30/D32/D34, commit `4c4970f`,
`research/04-p4.2-remediation.md`, `research/05-codex-execution-contract.md`.

**Open risks:** После разрешённого reset только `azguard_test` повторяемый MySQL `RefreshDatabase`
bootstrap падает до body P4.7 и существующего `ModelHasRolesScopes` (missing/already-existing
`model_has_roles`/`roles`/`migrations`). Это P4.10 green-proof/harness риск; не расширять P4.7.

**Workarounds/Deferred/Open questions:** workarounds — · deferred — P4.9/P4.10 и остальной P4 порядок
roadmap.md · open_questions —
