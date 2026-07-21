# HANDOFF — 2026-07-21 — after P4.8

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high — P4.7

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | high — migration security and MySQL correctness |
| Context | continue (reset the session context) — ручной item |
| Суть | Выполнить P4.7 по frozen D24+D32; затем независимый Sol/high review до закрытия. |

```text
В отдельной implementation/high Codex-сессии выполни P4.7 плана 2026.07.18-AZGUARD-STABLE через task:plan-run. Сначала прочитай plan.md, handoff.md, phases/P4.md P4.7 и Required Reads; не трогай P4.8, P4.9 или P4.10. Реализуй только key-length+MySQL collation для миграций 000002/000010, пройди required validation и перед закрытием получи независимый Sol/high read-only review.
```

**Done:** P4.8 закрыт по committed evidence: `1179b7c`/`91a67d7` реализуют migration 000005 и UUID proof; P4.11 `cda13a8` устранил отдельную portability fixture без изменения runtime. Повторные PG MorphType, PG ModelHasRolesScopes, PG `Authorizer|HasAzGuard`, MySQL ModelHasRolesScopes, SQLite full suite, lint и analyse зелёные.

**Remaining:** P4.7 → B6 (P4.9–P4.10) → P4.3 → P4.4 → P4.5 → P4.6; затем `task:plan-close` P4 и отдельный SoulXHigh phase review.

**Sources of truth:** `plan.md` (D30, D35–D36, Status Board), `phases/P4.md` (P4.8/P4.11), `research/06-p4.8-wildcard-classification.md`, commits `1179b7c`, `91a67d7`, `cda13a8`.

**Open risks:** Чужой незакоммиченный diff `.github/workflows/tests.yml` принадлежит P4.10, а `tests/Pest.php` не принадлежит P4.8; не включать их в P4.7 без scoped-приёмки. Полный green-proof обоих лейнов и CI matrix остаются P4.10.

**Workarounds/Deferred/Open questions:** workarounds — · deferred — P4.9/P4.10 и остальной P4 порядок roadmap.md · open_questions —
