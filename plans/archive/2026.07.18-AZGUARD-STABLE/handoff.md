# HANDOFF — 2026-07-22 — after P5

**Next:** план закрыт

| Параметр | Значение |
|:--|:--|
**Done:** План архивирован после GREEN audit P5. P5.1–P5.3 delivery завершены; D44 устранил stale roadmap, противоречивый Next и пустой Phase Handoff.

**Remaining:** —

**Sources of truth:** `phases/P5.md` Completion Notes и Audit P5; tag `v0.3.0`; commits `d26db85`, `2378029`, `c734634`.

**Open risks:** локальные coverage/mutation без драйвера и FFI warnings; release CI success. `packages/task/lib` отсутствует в standalone-репозитории.

**Workarounds/Deferred/Open questions:** split/Packagist отложены D25; открытых вопросов нет.

## Migration checklist

**Перенесено в docs:**

- `root/package-hardening-track.md` → `docs/05_AI/package-hardening-track.md`
- `root/api-surface.md` → `docs/05_AI/api-surface.md`
- `root/glossary.md` → `docs/05_AI/glossary.md`
- `root/semver-policy.md` → `docs/introduction/versioning.md` и `docs/ru/introduction/versioning.md`
- `root/known-limitations.md` → `docs/introduction/known-limitations.md` и `docs/ru/introduction/known-limitations.md`

**Остаётся в архиве:** `root/architecture.md`, `root/contracts/facade-cutline.md`, а также `artifacts/`, `findings/`, `research/` и `workflows/`.
