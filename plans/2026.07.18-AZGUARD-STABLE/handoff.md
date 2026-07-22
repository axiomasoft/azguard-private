# HANDOFF — 2026-07-22 — after P4.15

**Next:** task:plan-design 2026.07.18-AZGUARD-STABLE P4.10

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | cold-start — plan-step |
| Суть | Re-open P4.10 around the strict-output blocker before another union proof. |

```
$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.10

РЕЖИМ: детализация
ВХОД: phases/P4.md P4.10/P4.15 → Completion Notes and Known Deviations; /tmp/azguard-p415-{sqlite,pgsql-seed,pgsql,mysql}.log
СКОУП: 1. установить владельца и узкий scope для `[DEBUG-BATCH-QUERY]` stdout, из-за которого Composer exits 1 after Pest 669/669; 2. сохранить D40/P4.15 и повторно определить честный clean PG/MySQL union gate; 3. не принимать CI/docs/baseline/B6 до обеих command-level green validations
НЕ ТРОГАТЬ: P4.15's class-local annotation; production API/migrations/P3 snapshot; CI/docs/baseline/B6 before the green union proof
```

**Done:** P4.15 item commit `704d16b` adds only `#[ResetRefreshDatabaseState]` to `MorphTypeTestCase`. The recorded PostgreSQL random seed no longer shows ULID-to-bigint `22P02`; Pest reported 669/669 across SQLite, PostgreSQL seed, ordinary PostgreSQL and MySQL. Independent full read-only review: APPROVE.

**Remaining:** P4.10 redesign for the strict-output blocker → fresh clean PostgreSQL/MySQL union proof → CI/docs/baseline/B6 only if both commands exit green → P4.3–P4.6 → `task:plan-close` P4 → independent phase audit.

**Sources of truth:** `phases/P4.md` P4.10/P4.15; `plan.md` D40; `findings/P4.10-ulid-refresh-state-2026-07-22.md`; `research/10-p4.15-ulid-refresh-isolation.md`; `/tmp/azguard-p415-{sqlite,pgsql-seed,pgsql,mysql}.log`; item commit `704d16b`.

**Open risks:** SQLite seed and PostgreSQL seed write `[DEBUG-BATCH-QUERY]` stdout and Composer exits 1 after passing Pest output. P4.10's green-proof is therefore still blocked; the existing dirty workflow/Pest changes require their own accepted commit and validation.

**Workarounds/Deferred/Open questions:** workarounds — none; deferred — CI/docs/baseline/B6 until P4.10 command-level union proof is green; open_questions — origin/owner of the debug stdout.
