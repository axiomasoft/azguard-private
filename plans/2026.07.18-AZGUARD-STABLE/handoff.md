# HANDOFF — 2026-07-22 — after P4

**Next:** run-items: task:plan-run 2026.07.18-AZGUARD-STABLE P4.12

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | native: task:plan-run |
| Context | same-session — item |
| Суть | Выполнить P4.12: explicit portable table-aware morph-index names; не сокращать fixture, затем Sol/high review. |

```text
$ task:plan-run 2026.07.18-AZGUARD-STABLE P4.12
```

**Done:** P4.9 item-коммит `77c118c` заменил backslash-escape в поиске DirectGrantResource на
явный нейтральный `ESCAPE '!'` и экранирование `!`, `%`, `_`; существующий escape-тест зелёный на
SQLite, PostgreSQL и MySQL. Чистый P4.10 PostgreSQL run на `e48b3dd`: 669 passed / 1777 assertions.

**Remaining:** P4.12 UUID index-name remediation → P4.10 → P4.3 →
P4.4 → P4.5 → P4.6; затем `task:plan-close` P4 и отдельный Soul/xhigh audit фазы.

**Sources of truth:** `phases/P4.md` P4.12/P4.10, `plan.md` D37,
`research/05-codex-execution-contract.md`, `research/07-p4.12-morph-index-portability.md`,
`findings/P4.10-uuid-morph-index-name-2026-07-22.md`, clean-worktree focused command
`COMPOSER_PROCESS_TIMEOUT=900 MYSQL_PORT=23306 composer test:mysql -- --filter='creates the scopes unique index when the morph type is uuid'`.

**Open risks:** focused MySQL test fails with `SQLSTATE[42000] 1059` because automatic
`uuid_morph_test_model_has_scopes_scope_entity_type_scope_entity_id_index` exceeds MySQL's 64-char
identifier limit (tests/Feature/MorphTypeTest.php:64); P4.12 owns the production-helper repair,
not P4.10 CI. Composer's default 300s timeout is insufficient for the full MySQL suite; P4.10 uses
`COMPOSER_PROCESS_TIMEOUT=900` only after the targeted repair is green.

**Workarounds/Deferred/Open questions:** workarounds — Context7 quota fallback to Perplexity official
Laravel API for P4.9 verification; deferred — B6 Sol/high review after eventual P4.10 closure;
open_questions — resolved by D37: explicit production-helper names, not a shorter fixture.
