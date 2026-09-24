---
id: D3
date: 2026-09-22
status: accepted
item: P1
items: [P1, P1.1, P1.2]
supersedes: []
superseded_by: null
---
# D3 — Typed cache identity и additive absolute expiry

**Actor:** owner-request / plan-designer Codex root
**Evidence:** RAG:— `findings/verification.md` F1–F3 и current cache/source/layer code; RAG:✅ 2026-09-22 Perplexity query с переходом к official PSR-16 и Laravel Cache docs, зафиксированным в `docs/reference.md`; owner directive о bounded work и одном light seam review.

## Решение

Cache identity — каноническая пара persisted morph type + string-cast ID. Один internal value object строит fixed-length portable digest и используется в request cache, durable permission/epoch keys, invalidation и scoped-role cache. Cache namespace меняется на v2; v1 entries естественно вытесняются по TTL.

Expiry передаётся как nullable absolute `validUntil` в текущем immutable `PermissionSet`: это additive API, а не замена `GrantSource`/`PermissionLayer`. Merge берёт nearest deadline; built-in DirectGrant/context paths его заполняют. Cache v2 хранит strict envelope и проверяет `now >= validUntil` на каждом hit; backend TTL выбирается не позже этого deadline. Legacy custom source/layer без metadata продолжает работать с configured TTL; exact expiry обещается только при переданном deadline.

P1 использует один light review изменённых identity/expiry seams в owning execution. Нет отдельных design-review, post-review и phase-audit стадий без новой материальной гипотезы и owner consent.

## Почему

ID-only keys воспроизводимо смешивают два model types. Raw concatenation morph/panel/context оставляет separator и backend-key ambiguity; portable digest даёт одинаковую семантику во всех stores. TTL-only не защищает request cache и может округляться backend; absolute metadata закрывает authorization boundary. Отдельные v2 source/layer interfaces дублируют текущий `PermissionSet` pipeline; breaking return-type migration не нужна. Full per-key provenance не нужен: recompute всего set на nearest expiry простой и safe.

## Consequences

P1.1 владеет identity и v2 keys; P1.2 потребляет их и владеет deadline/envelope. `PermissionSet` API snapshot, RU/EN extension/cache docs и root CHANGELOG обновляются как additive release-visible contract. Короткоживущий legacy custom source без deadline остаётся TTL-limited и явно документируется; его принудительная миграция не входит в P1.
