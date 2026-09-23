---
id: D7
date: 2026-09-22
status: accepted
item: P4
items: [P4, P4.1, P4.2]
supersedes: []
superseded_by: null
---
# D7 — Scoped panel state, explicit validation boundaries и один review P4

**Actor:** owner-request / plan-designer Codex root
**Evidence:** RAG:— `findings/verification.md` F11–F15 и current manager/panel/middleware/authorizer/context paths; RAG:✅ 2026-09-22 focused Perplexity query по official Laravel scoped lifecycle, сверенный с official source в `docs/reference.md`; owner directive о bounded work и отказе от трёхстадийного review.

## Решение

Default manager остаётся long-lived panel registry, но current panel живёт в internal container-scoped holder. Existing `currentPanel()` / `setCurrentPanel()` и Facade/configured-manager seams не меняются. Nested middleware всегда восстанавливает previous panel в `finally`. Scoped lifecycle даёт request/job reset, но не обещает fiber isolation. Existing non-sync reset hooks и sync exemption сохраняются для custom manager compatibility. HasScopedRoles не применяет auth-dependent global scope к самой effective auth-provider model до вызова Auth.

`strict_panels=false` остаётся upgrade default. В strict mode любой final explicit/default/current/fallback panel должен быть registered; empty registry не является bypass, configured default проверяется после provider registration. Новый `require_permission_attributes` opt-in по умолчанию false: legacy request без attributes остаётся compatible, strict request падает с named configuration error, `SkipGuardCheck` явно разрешает bypass. Doctor показывает warning/error согласно mode.

Строки, pure/backed enums и Permission classes проходят одну internal normalization/grammar boundary. Она сохраняет global/hierarchical wildcards и dynamic placeholders, не требует ровно три сегмента и не дважды добавляет panel prefix. AzGuard `Gate::before` сначала доказывает ownership через exact catalog key или registered dynamic definition; для чужой ability возвращает `null` до wildcard check, чтобы Laravel policy продолжила работу.

P4.1 имеет `Review=none`; P4.2 имеет один `light` review по итоговым lifecycle/validation/Gate seams. Design-review, post-review и phase-audit для P4 не создаются. Однократный plan-wide design audit D4 остаётся отдельным гейтом перед всем execution.

## Почему

Смена singleton manager на scoped потеряла бы boot-time panel registry; маленький delegated holder отделяет только mutable lifecycle state. Strict defaults без opt-in создали бы upgrade break. Prefix-only Gate ownership слишком широко, а exact-only потеряло бы documented dynamic keys; catalog + dynamic match совпадает с уже принятой source-of-truth boundary. Один behavioral matrix и один seam review дают нужное evidence без трёхкратного workflow.

## Consequences

P4.1 владеет runtime state/lifecycle и F15; P4.2 владеет resolution, grammar, route enforcement, Gate fallback, diagnostics/docs и единственный review. Strict adoption требует явной config и upgrade note. Fiber/coroutine isolation, arbitrary non-catalog abilities и public PanelContext API остаются вне P4.
