# HANDOFF — 2026-07-22 — after P4.14

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.14

| Параметр | Значение |
|:--|:--|
| Model | implementation (GPT-5.6 Terra) |
| Thinking | medium — frozen D39 local test helper with three deterministic driver checks |
| Context | NEW SESSION — item execution |
| Суть | Реализовать только D39: `pgsql` savepoint recovery, direct SQLite/MySQL `QueryException`, post-exception query. |

```text
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.14

Прочитай handoff.md, research/05-codex-execution-contract.md,
findings/P4.10-full-lane-blockers-2026-07-22.md §B,
findings/P4.14-laravel-transaction-semantics-2026-07-22.md,
research/09-p4.14-driver-aware-savepoint.md, plan.md D39 и полный P4.14.
Измени только tests/Feature/ScopeClassMigrationRollbackTest.php: file-local helper
оборачивает actual migration->down() через DB::transaction() только для exact driver `pgsql`;
SQLite/MySQL выполняют исходную closure напрямую. На всех трёх драйверах сохрани
toThrow(QueryException::class), затем докажи DB::table(configured model_has_scopes)->exists()
на той же connection. Никаких catch/rethrow, Throwable/message/SQLSTATE assertions, raw PDO,
migration, tests/Pest.php, TestCase/config/CI/API/snapshot edits. Не трогай user-owned diffs
.github/workflows/tests.yml и tests/Pest.php.
Выполни focused SQLite; PG дважды; MySQL с COMPOSER_PROCESS_TIMEOUT=900; lint, analyse,
diff-check. Затем independent GPT-5.6 Sol/high read-only full review. Закрыть P4.14 можно
только после APPROVE; далее P4.10 запускается из clean worktree на full PG, затем full MySQL
с timeout 900. CI/docs/baseline/B6 до этих двух green full lanes запрещены.
```

**Done:** P4.13 is closed (`cf85e16`, `1a9853f`): SHA-256 digest, focused three-driver proof and Sol/high approval. P4.14's universal wrapper was reproduced but not committed; it recovered PostgreSQL twice and changed MySQL from `QueryException` to `PDOException`. D39 designs the narrower repo-precedented `pgsql`-only savepoint helper after external Laravel/Composer verification.

**Remaining:** P4.14 writer → independent full review → clean full P4.10 PostgreSQL/MySQL proof → only then CI/docs/baseline/B6 → P4.3–P4.6 → `task:plan-close` P4 → separate Sol/xhigh final audit.

**Sources of truth:** `plan.md` D38–D39; `phases/P4.md` P4.14/P4.10; `research/09-p4.14-driver-aware-savepoint.md`; `findings/P4.14-laravel-transaction-semantics-2026-07-22.md`; `research/05-codex-execution-contract.md`; commits `93b01a0`, `cf85e16`.

**Open risks:** P4.14 must preserve `QueryException` on every driver and prove post-exception query success; any deviation is a §10/D39 escalation. P4.10 CI/docs/B6 remain prohibited before clean full green proof. User-owned `.github/workflows/tests.yml` and `tests/Pest.php` changes remain untouched.

**Workarounds/Deferred/Open questions:** workarounds — none; deferred — P4.10 CI/docs/B6 until P4.14 terminal and full DB proof green; open_questions — none.
