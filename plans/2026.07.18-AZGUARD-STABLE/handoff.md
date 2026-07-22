# HANDOFF — 2026-07-22 — after P4.14

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — resolve cross-driver rollback contract |
| Context | NEW SESSION — шаг-не-item |
| Суть | Перепроектировать P4.14: PostgreSQL savepoint recovery конфликтует с MySQL `QueryException` contract. |

```text
$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.14

Прочитай plan.md, handoff.md, research/05-codex-execution-contract.md, phases/P4.md P4.14,
findings/P4.10-full-lane-blockers-2026-07-22.md §B и research/08-p4.13-p4.14-recovery.md.
P4.14 остановлен: предписанная nested DB::transaction() даёт два зелёных PostgreSQL targeted
прогона и normal-query proof, но на MySQL меняет required expected exception с
Illuminate\Database\QueryException на PDOException. Исходный MySQL seam зелёный с QueryException.
Определи минимальное D#-подкреплённое решение без ослабления negative proof, миграции, public API,
глобального Pest harness, CI или P4.10 scope. До нового исполнения требуется обновлённое
детерминированное ТЗ и validation-contract.
```

**Done:** P4.13 item commit `cf85e16` replaces only `sha1()` in the private morph-index-name helper with the D38 SHA-256-derived 40-hex digest. Focused SQLite/PostgreSQL/MySQL UUID long-name proof, lint, PHPStan and direct ArchTest passed; independent Sol/high review approved with no material findings. P4.14 reproduced the nested-transaction behavior without committing source: PostgreSQL passed twice with 2 tests / 4 assertions, while the MySQL negative assertion changed class.

**Remaining:** P4.14 redesign → re-execution and independent full review → P4.10 clean full PostgreSQL/MySQL proof → only then CI/docs/baseline/B6 → P4.3–P4.6 → `task:plan-close` P4 → separate Sol/xhigh audit.

**Sources of truth:** `plan.md` D37/D38; `phases/P4.md` P4.13/P4.14/P4.10; `findings/P4.10-full-lane-blockers-2026-07-22.md`; `research/08-p4.13-p4.14-recovery.md`; `research/05-codex-execution-contract.md`; commit `cf85e16`.

**Open risks:** P4.14 remains blocked until a design decision preserves both PostgreSQL transaction recovery and the MySQL exception-class contract. P4.10 CI/docs/B6 remain prohibited before a new clean full green proof. User-owned `.github/workflows/tests.yml` and `tests/Pest.php` changes remain untouched.

**Workarounds/Deferred/Open questions:** workarounds — none; deferred — P4.10 CI/docs/B6 until P4.14 is terminal and full DB proof green; open_questions — which narrow cross-driver test seam preserves `QueryException` without a global harness change.
