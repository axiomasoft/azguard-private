# HANDOFF — 2026-07-21 — after P4.10

**Next:** design-item: task:plan-design 2026.07.18-AZGUARD-STABLE P4.10

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | high — classify P4.8 regression and rescope repair |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать P4.8-owned remediation MySQL UUID morph-index-name overflow, затем вернуть P4.10 к green-proof. |

```text
$ task:plan-design 2026.07.18-AZGUARD-STABLE P4.10
```

**Done:** P4.9 item-коммит `77c118c` заменил backslash-escape в поиске DirectGrantResource на
явный нейтральный `ESCAPE '!'` и экранирование `!`, `%`, `_`; существующий escape-тест зелёный на
SQLite, PostgreSQL и MySQL. Чистый P4.10 PostgreSQL run на `e48b3dd`: 669 passed / 1777 assertions.

**Remaining:** plan-design remediation для P4.8/P4.10 UUID index-name regression → P4.10 → P4.3 →
P4.4 → P4.5 → P4.6; затем `task:plan-close` P4 и отдельный Soul/xhigh audit фазы.

**Sources of truth:** `phases/P4.md` P4.8/P4.10, `plan.md` D30/D34,
`research/05-codex-execution-contract.md`, commit `e48b3dd`, clean-worktree focused command
`COMPOSER_PROCESS_TIMEOUT=900 MYSQL_PORT=23306 composer test:mysql -- --filter='creates the scopes unique index when the morph type is uuid'`.

**Open risks:** focused MySQL test fails with `SQLSTATE[42000] 1059` because automatic
`uuid_morph_test_model_has_scopes_scope_entity_type_scope_entity_id_index` exceeds MySQL's 64-char
identifier limit (tests/Feature/MorphTypeTest.php:64); classify/re-own it under P4.8 remediation, not
P4.10 CI. Composer's default 300s timeout is insufficient for the full MySQL suite; CI would need
an explicit timeout only after the real defect is resolved.

**Workarounds/Deferred/Open questions:** workarounds — Context7 quota fallback to Perplexity official
Laravel API for P4.9 verification; deferred — B6 Sol/high review after eventual P4.10 closure;
open_questions — whether P4.8 test-owned UUID index should receive an explicit portable name or use
a shorter table fixture name; plan-design must decide.
