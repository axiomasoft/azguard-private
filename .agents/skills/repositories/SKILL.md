---
name: repositories
bucket: php
version: 0.1.0
description: "Laravel repositories: read/store split, model scopes, delta-DTO, facade, VisibilityService"
risk: write
persona: oss-dev
tags: ["php", "laravel", "repository", "architecture"]
requires: []
produces_for: []
outputs: []
snippets: ["read-repository.php", "store-repository.php", "member-store-repository.php", "members-delta.php", "repository-facade.php", "model-scopes.php", "visibility-service.php"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Laravel repositories: read/store split

Eloquent is already repository + active record; a wrapper over `find`/`create` is an antipattern. Add a repository layer only when:
- **repeated queries**: same query reused across controller / Livewire / command;
- **write-side isolation**: nontrivial mutations (pivot sync, pair normalization, prioritized updateOrCreate) belong in repository, not Action (Action = business scenario, repository = row work);
- **testability**: Action tested with mocked repository; query logic tested separately (integration);
- **data visibility**: rights filtering unforgettable via single entry `queryForUser()`.

Canon — lightweight-CQRS split in `app/Repositories/<Domain>/`:

| Class | Side | Responsibility |
|---|---|---|
| `XxxReadRepository` | read | queries, filters, pagination, eager load |
| `XxxStoreRepository` | write | mutations, sync ops |
| `Repository` (facade) | read | delegation, single entry for controllers (optional) |

## Algorithm

1. **Pick side.** Query → `XxxReadRepository`; mutation → `XxxStoreRepository`. Never mix in one class.
2. **Read-side: start from visibility.** Every public read method builds atop `queryForUser(?User $user, bool $ignorePermissions = false)`, delegating to `XxxVisibilityService::apply()`. Signature signals filtering by rights.
3. **Move repeated predicates into model scopes** with domain names (`withStatus`, `withListRelations`, `forUser`). Repository COMPOSES them — a second identical where-chain = create a scope.
4. **Choose return type:**
   - `Builder` — consumer composes further (counters, sections, extra filters);
   - `Model` / `Collection` / `Paginator` — terminal query;
   - **delta-DTO** (`added`/`removed`) — for write-side sync ops: Action learns whom to notify without re-reading DB.
5. **Write-side accepts DTO commands** (`Form`), not arrays nor `Request`. `persistFromForm(Form $form, User $user): Model` — typical entry.
6. **Open no transactions** in repository: `DB::transaction()` belongs to Action; repository runs inside it. Multiple store-repo calls in one Action are atomic automatically.
7. **Add facade only when needed:** many read methods + controllers inject repository everywhere → add `Repository` with pure delegation. At 2-3 methods — inject read-repository directly.

## Which snippet to open

| Situation | File |
|---|---|
| New query/list/search, needs rights filtering | `snippets/read-repository.php` |
| Create/update model from DTO-form, sync relation | `snippets/store-repository.php` |
| Sync members/roles, Action must learn who added/removed | `snippets/member-store-repository.php` |
| Need sync-op result type | `snippets/members-delta.php` |
| Many read methods, controllers need single entry | `snippets/repository-facade.php` |
| Where-chain repeats a second time | `snippets/model-scopes.php` |
| Rules "who sees which records" (roles, statuses, ownership) | `snippets/visibility-service.php` |

## Quality checklist

- [ ] Read and store separated: no mutations in `ReadRepository`, no display-queries in `StoreRepository`.
- [ ] Every public read method goes through `queryForUser()`; bypass rights only via explicit `ignorePermissions: true`.
- [ ] Repository opens **NO transactions** — runs inside Action's transaction.
- [ ] Repository does **NOT write history and does NOT dispatch events**. Typical bug: `recordHistory()` inside `persistFromForm()` — business-logic leak; history and events belong to Action/StateMachine.
- [ ] Repository does **NOT accept `Request`** — only DTO commands (write) and scalars/enums/models (read).
- [ ] Repository makes **NO authorization decisions** — visibility only via injected `VisibilityService` with explicit signature `apply(Builder, ?User, bool $ignorePermissions): Builder`.
- [ ] Repeated predicates moved to model scopes with domain names; repository does not duplicate where-chains.
- [ ] Sync ops idempotent (updateOrCreate + delete dropped) and return delta-DTO, not `void`/`bool`.
- [ ] "Not found" and "no access" indistinguishable to consumer (`findByIdOrFail` searches atop `queryForUser`). This is the **only canonical** lookup; do NOT breed `findByCodeOrFail`/`findBySlugOrFail` per field — see `general/naming-conventions`.
- [ ] Facade — pure delegation, no logic; classes `final`, store-repos `final readonly`.
- [ ] Delta-DTO — `final readonly`, in `app/Dto/<Domain>/Repository/`.

## Links

- `php/laravel-structure` — layer placement (`app/Repositories/<Domain>/`, `app/Dto/<Domain>/Repository/`, `app/Services/<Domain>/Access/`).
- `php/dependency-injection` — who injects whom: VisibilityService → ReadRepository → Repository(facade) → controller; StoreRepository → Action.
- `php/laravel` → `layer-boundaries.md` — layer boundaries: what's allowed to Action, repository, model.
- `laravel-data-layer/laravel-data` — DTO commands (`Form`) accepted by write-side; `php/php-patterns` — delta-DTO and specification.
- `general/naming-conventions` — naming repository methods by intent (vs `findByX...OrFail`-explosion) and scopes.
- `php/database` — models and scopes the queries build on.
- `laravel-auth/laravel-permissions` / `azguard/azguard` — where rights read by `VisibilityService` are defined.

<!-- ru-source-sha256: 1d285385a8437272f357a835012184306c8490d13873c8f5f7371b5dd9cb03fa -->
