---
id: D5
date: 2026-09-22
status: superseded
item: P2
items: [P2, P2.1, P2.2, P2.3]
supersedes: []
superseded_by: D13
---
# D5 — Transactional revision и один итоговый seam review P2

**Actor:** owner-request / plan-designer Codex root
**Evidence:** RAG:— `findings/verification.md` F4–F8 и current role/grant/cache paths; RAG:✅ 2026-09-22 focused Perplexity query по official Laravel 13 / Filament 5 sources, зафиксированным в `docs/reference.md`; owner directive о bounded work и отказе от трёхстадийного review.

## Решение

Официальные authorization mutations изменяют данные и глобальную монотонную permission-state revision на той же DB connection в одной транзакции. Resolver читает revision перед каждым effective-permission cache lookup и включает её в request/durable key. Поэтому cache backend остаётся advisory: committed revoke нельзя скрыть старым cache entry, а cache read/write failure ведёт к uncached resolve либо явной ошибке, но не к stale allow.

Role-permission sync получает один core synchronizer. Filament передаёт выбранные значения вместе с явно управляемым множеством ключей и expected fingerprint: невидимые wildcard/dynamic/unknown-panel rows сохраняются, а устаревший concurrent editor получает conflict без частичного delete. CLI задаёт свою явную panel scope. No-op не меняет rows и revision.

`guard:cache-reset` повышает только AzGuard permission-state revision и очищает local request state; чужие ключи configured store не удаляются. Явная deployment generation входит в cache identity. Прямые bulk SQL/Eloquent mutations вне официальных services документируются как maintenance path с обязательным revision bump/reset.

P2 имеет один `light` review изменённых role-sync/revision/failure seams в P2.3. P2.1 и P2.2 не получают отдельные design-review/post-review/phase-audit стадии. Общий однократный design audit D4 остаётся plan-wide gate, а не третьим item review.

## Почему

Обход `Role::users()` неполон для polymorphic/scoped holders. Cache-only epoch и after-commit callback оставляют crash window между DB commit и invalidation; Laravel не обещает persistence/retry callback. Durable outbox закрывает доставку, но избыточен для синхронного permission lookup и исключён scope. DB revision в той же транзакции даёт простой observable fence; глобальная гранулярность сознательно выбрана вместо новой матрицы per-subject/per-role revision rows.

Полный delete+insert ломает atomicity, model validation и невидимые grants. Явный managed set + optimistic fingerprint сохраняет текущий UI contract и делает concurrent edit результатом, а не скрытым last-write-wins. Три review-прохода не добавляют evidence сверх одного итогового seam review.

## Consequences

P2.1 владеет synchronizer и caller migration; P2.2 — permission-state migration/revision и официальные mutation paths; P2.3 — backend failure, deployment generation и безопасный reset. Разные DB connections между authorization rows и state revision не объявляются поддержанными: они fail fast и возвращаются в P3/P6 как явный support вопрос. Новая migration/config/command semantics отражаются в root `CHANGELOG.md`, RU/EN docs и последующей P6 fresh/upgrade qualification.
