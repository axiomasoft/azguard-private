# HANDOFF — 2026-07-21 — after P4

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.11

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Заменить только два anonymous role fixtures на existing named SuperAdminRole и доказать/опровергнуть PG wildcard 37/37. |

```text
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.11

Прочитай handoff.md, research/05-codex-execution-contract.md, research/06-p4.8-wildcard-classification.md,
findings/P4.8-wildcard-follow-up-2026-07-21.md, plan.md D35–D36 и phases/P4.md P4.8/P4.11.
Меняй ТОЛЬКО два Files P4.11. Сохрани assertions true; не трогай packages/**, migration 000005,
P3 snapshot, tests/Pest.php и P4.7/P4.9/P4.10. Если named fixture не даёт PG 37/37, не ослабляй
тест: оставь evidence и эскалируй по §10. Перед закрытием — Sol/high read-only review классификации.
```

**Done:** P4.8 implementation committed: `1179b7c` (migration 000005) and `91a67d7`
(UUID regression coverage). Independent Sol/high review approved its migration diff. PG `MorphType`
5/5, PG/MySQL `ModelHasRolesScopes` 5/5, sqlite 668/668, lint and analyse are green. Follow-up
design reproduced PG wildcard `35/37` and classified the two residual failures as anonymous
class-name test-fixture portability (D36), without production/test-code edits in this design pass.

**Remaining:** P4.11 → resume/close P4.8 → P4.7 → P4.9/P4.10 → P4.3 → P4.4 → P4.5 → P4.6 →
`plan-close P4`. Final SoulXHigh phase review is deliberately deferred to a separate session by owner direction.

**Sources of truth:** `plan.md` D30–D36 · `phases/P4.md` P4.8/P4.11 ·
`research/05-codex-execution-contract.md` · `research/06-p4.8-wildcard-classification.md` ·
`findings/P4.8-wildcard-follow-up-2026-07-21.md` · commits `1179b7c`, `91a67d7`.

**Open risks:** D36 остаётся гипотезой, пока P4.11 не докажет named fixture PG `37/37`; failure
после этой подмены означает runtime/P3 вопрос и блокирует P4.8 закрытие. P4.10 CI hunk и dirty
`tests/Pest.php` остаются вне P4.11 scope.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: P4.10 CI hunk in `.github/workflows/tests.yml`; `tests/Pest.php` pre-existing dirty mapping; final SoulXHigh P4 review is owner-deferred.
- open_questions: D36 is resolved operationally only by P4.11 validation; no owner choice is pending. Docker lane ports: PGSQL 25432, MYSQL 23306, REDIS 26379.
