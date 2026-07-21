# HANDOFF — 2026-07-21 — after P4.8

**Next:** design-item: task:plan-design 2026.07.18-AZGUARD-STABLE P4.8

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — минимальный follow-up для wildcard defect |
| Context | NEW SESSION — шаг-не-item |
| Суть | Классифицировать две PG wildcard-ошибки и добавить минимальный контрактный follow-up без scope creep P4.8. |

```text
$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.8

Прочитай research/05-codex-execution-contract.md, plan.md D30–D35, phases/P4.md P4.8,
commits 1179b7c и 91a67d7, а также точный PG failure:
PGSQL_PORT=25432 composer test:pgsql -- --filter='Authorizer|HasAzGuard'.
P4.8 не расширяй и не закрывай: спроектируй минимальный отдельный follow-up для двух
wildcard assertions (tests/Unit/Concerns/HasAzGuardTest.php:145,
tests/Feature/AuthorizerExtendedTest.php:53), с явным verdict о SemVer/P3 freeze и validation.
```

**Done:** P4.8 implementation committed: `1179b7c` (migration 000005) and `91a67d7`
(UUID regression coverage). Independent Sol/high review approved final P4.8 diff. PG `MorphType`
5/5, PG/MySQL `ModelHasRolesScopes` 5/5, sqlite 668/668, lint and analyse are green.

**Remaining:** follow-up design for PG wildcard defect → resume/close P4.8 → P4.7 →
P4.9/P4.10 → P4.3 → P4.4 → P4.5 → P4.6 → `plan-close P4`. Final SoulXHigh phase review
is deliberately deferred to a separate session by owner direction.

**Sources of truth:** `plan.md` D30–D35 · `phases/P4.md` P4.8 ·
`research/05-codex-execution-contract.md` · `research/04-p4.2-remediation.md` ·
`findings/P4.2-remediation-anchors-2026-07-18.md` · commits `1179b7c`, `91a67d7`.

**Open risks:** PG wildcard failure remains red: `Authorizer|HasAzGuard` 35/37 with false
assertions at `HasAzGuardTest.php:145` and `AuthorizerExtendedTest.php:53`; it has no SQLSTATE or
transaction-abort symptom. Classify before changing core behaviour or test expectations.

**Workarounds/Deferred/Open questions:** P4.10 CI hunk in `.github/workflows/tests.yml` remains
uncommitted and out of P4.8 scope. `tests/Pest.php` is a pre-existing dirty mapping and remains
uncommitted. Docker lane ports: PGSQL 25432, MYSQL 23306, REDIS 26379.
