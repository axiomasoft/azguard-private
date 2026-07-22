# HANDOFF — 2026-07-22 — after P4

**Next:** run-items: task:plan-run 2026.07.18-AZGUARD-STABLE P4.13

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | task.plan-run · database · laravel-package-testing |
| Context | same-session — item |
| Суть | Исполнить только P4.13: заменить forbidden digest в private morph-index helper, сохранить D37 proof и пройти независимое review до P4.14. |

```text
$ task:plan-run 2026.07.18-AZGUARD-STABLE P4.13

РЕЖИМ: remediation
ВХОД: plans/2026.07.18-AZGUARD-STABLE/findings/P4.10-full-lane-blockers-2026-07-22.md §A → architecture failure; plans/2026.07.18-AZGUARD-STABLE/research/08-p4.13-p4.14-recovery.md → decision
СКОУП: 1. Replace only sha1() in MorphColumns::morphIndexName() with the D38 permitted deterministic digest. 2. Preserve the 48-character table-aware name and long-name three-driver proof. 3. Obtain Sol/high read-only review before closure.
НЕ ТРОГАТЬ: P4.12/P4.10 historical Completion Notes and statuses; migrations, public API/config/snapshot, tests/ArchTest.php, CI/docs/B6, user-owned .github/workflows/tests.yml and tests/Pest.php diffs.
```

**Done:** D38 classified both P4.10 blockers without changing source: full PG/MySQL logs show
the P4.12 `sha1()` architecture violation; the isolated PostgreSQL rollback test deterministically
reproduces `SQLSTATE[25P02]` after its expected migration exception. P4.13/P4.14 are detailed
to DoR. P4.12/P4.10 provenance remains intact.

**Remaining:** P4.13 → independent Sol/high review → P4.14 → independent Sol/high review →
P4.10 full clean PG/MySQL proof → only then its CI/docs/baseline/B6 review → P4.3–P4.6 →
`task:plan-close` P4 → separate Sol/xhigh phase audit.

**Sources of truth:** `plan.md` D37/D38; `phases/P4.md` P4.10/P4.12/P4.13/P4.14;
`findings/P4.10-full-lane-blockers-2026-07-22.md`; `research/08-p4.13-p4.14-recovery.md`;
`/tmp/azguard-p410-pgsql.log`; `/tmp/azguard-p410-mysql.log`; `research/05-codex-execution-contract.md`.

**Open risks:** `hash('sha256', ...)` must pass the actual architecture gate and retain D37's
long-name proof. P4.14 must prove recovery on the same testbench connection, not merely catch
the exception. No CI hunk, docs, RESOLVED finding or B6 review may be accepted before both full
real-DB suites are green.

**Workarounds/Deferred/Open questions:** deferred — P4.10 CI/docs/B6 until P4.13/P4.14 are
terminal and new full proof is green; open_questions — none.
