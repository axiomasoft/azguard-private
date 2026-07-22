# HANDOFF — 2026-07-22 — after P4.10

**Next:** exec-items: task:plan-exec 2026.07.18-AZGUARD-STABLE P4.10

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Run the fresh-vendor PostgreSQL/MySQL union proof, then accept CI/docs only if both exit green. |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.10
```

**Done:** P4.15 item commit `704d16b` adds only `#[ResetRefreshDatabaseState]` to `MorphTypeTestCase`. D41 redesigns P4.10 to require a fresh Composer dependency tree for its command-level union proof.

**Remaining:** P4.10 fresh clean PostgreSQL/MySQL union proof → CI/docs/baseline/B6 only if both commands exit green → P4.3–P4.6 → `task:plan-close` P4 → independent phase audit.

**Sources of truth:** `phases/P4.md` P4.10/P4.15; `plan.md` D40; `findings/P4.10-ulid-refresh-state-2026-07-22.md`; `research/10-p4.15-ulid-refresh-isolation.md`; `/tmp/azguard-p415-{sqlite,pgsql-seed,pgsql,mysql}.log`; item commit `704d16b`.

**Open risks:** fresh `composer update` requires network and may reveal dependency drift because no lockfile is committed; any command-level red result keeps CI/docs/baseline/B6 prohibited.

**Workarounds/Deferred/Open questions:** workarounds — no copied/symlinked local vendor; deferred — CI/docs/baseline/B6 until P4.10 command-level union proof is green; open_questions — stale `tests/Pest.php` DebugPgAbort registration remains outside P4.10 unless a clean proof reaches it.
