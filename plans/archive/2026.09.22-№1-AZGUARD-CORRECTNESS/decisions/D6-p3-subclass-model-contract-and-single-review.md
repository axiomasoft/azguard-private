---
id: D6
date: 2026-09-22
status: accepted
item: P3
items: [P3, P3.1, P3.2]
supersedes: []
superseded_by: null
---
# D6 — Subclass model contract и один итоговый review P3

**Actor:** owner-request / plan-designer Codex root
**Evidence:** RAG:— `findings/verification.md` F9–F10 и current core/Filament paths; RAG:✅ 2026-09-22 focused Perplexity query по official Laravel 13 / Filament 5 sources и installed Filament 5.7.1 `Resource::getModel()`; owner directive о bounded work и отказе от трёхстадийного review.

## Решение

Публичные `models.role`, `scope`, `direct_grant` и `role_permission` остаются Eloquent subclass seams. Каждое значение обязано быть existing subclass соответствующей AzGuard base model. Официальные paths строят relations/queries из configured class, поэтому наблюдают его table, events, casts, local/global scopes. Concrete base type-hints совместимы с subclass и не запрещаются.

AzGuard persistence и permission-state revision работают на одной effective database connection. Единую custom connection можно использовать, если все AzGuard models/tables и P2 revision находятся на ней. Split connections и cross-database relations/transactions не поддерживаются и fail fast до write.

`DatabaseRoleGrantSource` начинает query с configured RolePermission Eloquent builder: это сохраняет его table, connection и global scopes. Joined raw pivot остаётся table contract и не получает несуществующие model scopes. Filament resources динамически возвращают configured model через `getModel()`.

P3 имеет один `light` review в P3.2 по итоговым core/Filament/diagnostics seams. P3.1 не получает отдельные design-review/post-review/phase-audit стадии. Plan-wide design audit D4 остаётся однократным гейтом после детализации P1–P7, а не третьим review P3.

## Почему

Удаление model config ломает опубликованный extension seam; repository/ModelRegistry дублирует Eloquent без измеренной необходимости. Model builder даёт точную семантику table/connection/scope; `DB::table()` её не даёт. Одинаковая connection boundary согласует P3 с transactional revision D5 и не обещает distributed commit.

Три review-прохода не добавляют evidence сверх behavioral regression матрицы и одного seam review, но увеличивают цену и длительность работы.

## Consequences

P3.1 владеет core Config/relations/builders/source и regression matrix. P3.2 владеет Filament, doctor, docs/CHANGELOG и единственным review. Invalid model config получает actionable exception/doctor output. Новая context model configurability и arbitrary/split-connection ORM остаются вне P3.
