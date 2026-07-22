# HANDOFF — 2026-07-22 — after P4.4

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.5

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | medium — measured mutation baseline |
| Context | continue (reset the session context) — ручной item |
| Суть | Измерить mutation baseline, поднять только честные пороги и провести full review P4.5. |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.5
```

**Done:** `07cac2b` добавил real Redis cross-process test: 8 worker-процессов × 3 bump,
уникальный prefix и явный skip без Redis; C-14 проверяет scoped `RequestState` после
`forgetScopedInstances()`. Targeted proof 3×, full sqlite suite, Pint/PHPStan и независимый
Sol/high review зелёные.

**Remaining:** P4.5 → P4.6 → `task:plan-close` P4 → независимый `task:plan-audit` P4.

**Sources of truth:** `phases/P4.md` P4.4; commit `07cac2b`; independent P4.4 review.

**Open risks:** root ignored `vendor/` malformed; package validation выполнять только в fresh isolated
worktree. P4.4 raw epoch starts at 1, поэтому 24 real bump завершаются на 25 — это записано как
material deviation item-а, production-код не менялся.

**Workarounds/Deferred/Open questions:** workarounds — не использовать copied/symlinked root vendor;
deferred — P4.5 mutation measurement; open_questions — stale `tests/Pest.php` DebugPgAbort registration
остается вне P4.10.
