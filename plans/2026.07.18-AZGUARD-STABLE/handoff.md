# HANDOFF — 2026-07-22 — after P4.10

**Next:** /task:plan-design 2026.07.18-AZGUARD-STABLE P4.10

| Параметр | Значение |
|:--|:--|
| Model | GPT-5.6 Sol |
| Thinking | high — определить owner ULID portability defect |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать узкий remediation-item для PostgreSQL ULID→bigint failure, не меняя scope P4.10. |

```
/task:plan-design 2026.07.18-AZGUARD-STABLE P4.10
```

**Done:** Clean detached `d164d94` proof: PostgreSQL failed only at `MorphTypeTest` when a ULID was written to a bigint morph column; MySQL passed 669 tests / 1786 assertions with timeout 900. No CI/docs/baseline/B6 deliverable was accepted.

**Remaining:** P4.10 is blocked on a plan-designed ULID portability remediation → repeat both clean DB lanes → CI/docs/baseline/B6 only if both are green → P4.3–P4.6 → `task:plan-close` P4 → separate Sol/xhigh audit.

**Sources of truth:** `phases/P4.md` P4.10 escalation; `/tmp/azguard-p410-final-pgsql-clean.log`; `/tmp/azguard-p410-final-mysql-clean.log`; item base `d164d94`.

**Open risks:** PostgreSQL is not a first-class green lane while `MorphTypeTest` persists ULID into a bigint morph column. User-owned `.github/workflows/tests.yml` and `tests/Pest.php` remain excluded; CI/docs/baseline/B6 stay prohibited.

**Workarounds/Deferred/Open questions:** workarounds — none; deferred — CI/docs/baseline/B6 until both DB lanes are green; open_questions — whether the remediation belongs in MorphColumns/config lifecycle or the ULID fixture contract; plan-design must decide.
