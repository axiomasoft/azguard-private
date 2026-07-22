# HANDOFF — 2026-07-22 — after P4.13

**Next:** /task:plan-exec 2026.07.18-AZGUARD-STABLE P4.14

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | medium — prescribed transaction-isolation test seam |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить только P4.14: сохранить expected migration failure и восстановить PG outer transaction через savepoint. |

```text
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.14

РЕЖИМ: remediation
ВХОД: plans/2026.07.18-AZGUARD-STABLE/research/05-codex-execution-contract.md; plans/2026.07.18-AZGUARD-STABLE/findings/P4.10-full-lane-blockers-2026-07-22.md §B; plans/2026.07.18-AZGUARD-STABLE/research/08-p4.13-p4.14-recovery.md; phases/P4.md P4.14.
СКОУП: только tests/Feature/ScopeClassMigrationRollbackTest.php — expected `down()` failure внутри nested DB transaction/savepoint и assert normal query after it.
НЕ ТРОГАТЬ: migration 000004, production schema/runtime, tests/Pest.php, test connection config, CI/full lanes (P4.10), P4.13, public API/config/snapshot, user-owned .github/workflows/tests.yml and tests/Pest.php diffs.
```

**Done:** P4.13 item commit `cf85e16` replaces only `sha1()` in the private morph-index-name helper with the D38 SHA-256-derived 40-hex digest. Focused SQLite/PostgreSQL/MySQL UUID long-name proof, lint, PHPStan and direct ArchTest passed; independent Sol/high review approved with no material findings.

**Remaining:** P4.14 independent Sol/high review → P4.10 clean full PostgreSQL/MySQL proof → only then CI/docs/baseline/B6 → P4.3–P4.6 → `task:plan-close` P4 → separate Sol/xhigh audit.

**Sources of truth:** `plan.md` D37/D38; `phases/P4.md` P4.13/P4.14/P4.10; `findings/P4.10-full-lane-blockers-2026-07-22.md`; `research/08-p4.13-p4.14-recovery.md`; `research/05-codex-execution-contract.md`; commit `cf85e16`.

**Open risks:** P4.13's literal ArchTest filter selector returns `No tests found`, although direct `tests/ArchTest.php` passed the security gate; preserve that evidence, do not weaken or edit the architecture test. P4.14 must prove the same testbench connection remains usable after expected exception. P4.10 CI/docs/B6 remain prohibited before new full green proof.

**Workarounds/Deferred/Open questions:** workarounds — none; deferred — P4.10 CI/docs/B6 until P4.13/P4.14 terminal and full DB proof green; open_questions — none.
