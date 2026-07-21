# HANDOFF — 2026-07-22 — after P4.10 escalation

**Next:** `$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.10`

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — resolve cross-item validation blockers |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать P4.12-owned replacement for forbidden sha1 and classify the PostgreSQL rollback transaction abort before P4.10 is resumed. |

```text
$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.10
```

**Done:** P4.10 ran full real-DB validation in clean detached worktree `e5407a0` after a fresh
`composer update`: PostgreSQL on port 25432 exited 2 with 667 passed / 2 failed; MySQL on port
23306 with `COMPOSER_PROCESS_TIMEOUT=900` exited 1 with 668 passed / 1 failed. Both fail the
security architecture rule because P4.12 `MorphColumns::morphIndexName()` calls `sha1()`; the PG
run additionally reports `ScopeClassMigrationRollbackTest` with SQLSTATE 25P02. No CI hunk, docs,
or RESOLVED finding was accepted; no B6 review was run.

**Remaining:** plan-design remediation for the blockers → resume P4.10 full clean PG/MySQL proof
→ P4.10 CI/docs deliverables → B6 Sol/high review only after a valid P4.10 item diff exists →
P4.3 → P4.4 → P4.5 → P4.6; then `task:plan-close` P4 and a separate Sol/xhigh phase audit.

**Sources of truth:** `phases/P4.md` P4.10/P4.12; `plan.md` D30/D37; full-run logs
`/tmp/azguard-p410-pgsql.log` and `/tmp/azguard-p410-mysql.log`; clean worktree
`/tmp/azguard-p410.5xFZ0b` at `e5407a0`; `research/05-codex-execution-contract.md`;
`research/04-p4.2-remediation.md`.

**Open risks:** P4.10 must not accept `.github/workflows/tests.yml`, update docs/finding, or run
B6 until both full real-DB suites are green. The `sha1()` violation is in a P4.12-owned helper,
outside P4.10 Scope Included; changing it without a plan-design decision would violate the frozen
item boundary. The PostgreSQL rollback failure needs an isolated classification after that decision.

**Workarounds/Deferred/Open questions:** deferred — B6 Sol/high review remains after P4.10 has a
valid completed diff; open_questions — whether the PG transaction failure is independently
reproducible after the P4.12 remediation.
