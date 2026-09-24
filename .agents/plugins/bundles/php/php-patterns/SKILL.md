---
name: php-patterns
bucket: php
version: 0.2.0
description: "Use when choosing a PHP domain pattern: VO, DTO, pipeline, specification — selection guide."
risk: write
persona: oss-dev
tags: ["php", "laravel", "patterns", "dto", "architecture"]
requires: []
produces_for: []
outputs: []
snippets: ["value-object.php", "dto.php", "pipeline.php", "specification.php"]
adapters: [claude, cursor, fable]
sha256: ""
---

# PHP Domain Patterns: VO · DTO · Pipeline · Specification

Selection guide for four framework-free domain patterns. Pick the right one per task (often none); know boundaries vs Laravel/spatie. Implementation templates live in snippets.

- **Value Object (VO)** — value with guarantee: typed wrapper over a primitive with invariant + behavior. "An invalid Email cannot exist."
- **DTO** — data without behavior: typed structure to move data between layers.
- **Pipeline** — sequential transformation: processing split into reorderable stages over one value.
- **Specification** — composable predicate: "matches / not" business rule as an object, combined and/or/not.

## When to activate

- Writing/reviewing a class that moves or checks data, unsure: VO, DTO, service, or plain array.
- A primitive (`string $email`, `int $amount`) validated in several places → VO candidate.
- A method grew into a long sequence of transformations → Pipeline candidate.
- A filter/admission condition is duplicated and combined → Specification candidate.
- On review: "God-DTO" with methods, anemic VO without invariant, or specification over DB.

## Selection algorithm

### Step 1 — first ask: is any pattern needed

Anti-bloat first. A pattern is justified only if it removes duplication or protects an invariant.
- Validation already in `FormRequest`/`Data::from()` and value checked nowhere else → **no VO**.
- Structure needed only inside one method → local array/named vars, **no DTO**.
- One linear transformation, no branching → plain method, **no Pipeline**.
- Single condition in one place → `if`/scope, **no Specification**.

### Step 2 — distinguish by task nature

| Question | → pattern |
|:--|:--|
| A **value** with a validity rule that must always hold? | **VO** |
| A **set of fields** to pass between layers, no logic/invariants? | **DTO** |
| A **process** of several independent stages over one object? | **Pipeline** |
| A **rule** "matches / not" that must be combined? | **Specification** |

Patterns **combine**, not compete: DTO may hold VO fields; Pipeline stage takes/returns DTO; Specification checks VO/model. Choice is about each class's role, not either/or.

### Step 3 — criteria & anti-patterns

**Value Object** (`snippets/value-object.php`)
- Use when: primitive has rules (email, phone, money, date range, percent) AND those rules/operations occur more than once.
- Marks: `final readonly`; invariant in constructor (throws on invalid input); value equality (`equals`); domain behavior (`Money::add`, `Email::domain`).
- Anti-patterns: **anemic VO** — wrapper without invariant/behavior (then just `string`, no VO); setters/mutators (VO is immutable — operation returns new instance); validation *outside* VO while VO exists (invariant MUST live IN constructor); `-VO`/`-Object` name suffix (name = domain noun, see `general/naming-conventions`).

**DTO** (`snippets/dto.php`)
- Use when: a set of fields passes between layers (controller→Action→repository) and you want a typed contract instead of an associative array; sync-operation result → delta-DTO.
- Marks: `final readonly`; promoted properties; **no methods with logic**; no deps on `Request`/Eloquent/DB; created via named arguments (`php/named-arguments`).
- Anti-patterns: **God-DTO** — DTO grows methods (`save()`, `calculate()`), becomes a service (logic → Action/Service, DTO stays data); mixing with VO — field with invariant → make it a VO, DTO carries it; DTO for its own sake where an in-method array suffices.
- **Package boundary:** need validation-from-request, casts, `DataCollection`, `#[TypeScript]` → `spatie/laravel-data` (`laravel-data-layer/laravel-data`), not a bare DTO. Bare = when package dep is undesirable (domain core, library code) or structure is purely internal.

**Pipeline** (`snippets/pipeline.php`)
- Use when: processing splits into 3+ independent stages (normalize → enrich → compute → check) you want to test and reorder separately.
- Marks: each step is its own class/closure with one signature; steps don't know each other; order set by a list, not hardcoded.
- Anti-patterns: pipeline for two lines (one-two transforms → plain method); steps with side effects on others' data (step must be local and predictable); **inventing your own Pipeline** when `Illuminate\Pipeline\Pipeline`/`Pipeline` facade is available — use the framework one, custom only when dep undesirable; **named argument in a polymorphic call** `handle()`/`isSatisfiedBy()` via interface — implementation's param name isn't fixed by the interface, such call fails `Unknown named parameter`; call positionally (exception to `php/named-arguments`, which is about concrete signatures).

**Specification** (`snippets/specification.php`)
- Use when: a "matches / not" rule applies in several places AND is combined (A and B, A or not C); you want to name, test, reuse it as an object.
- Marks: single `isSatisfiedBy(): bool`; elementary specs compose via `and/or/not` without editing source classes.
- Anti-patterns: **specification over DB** — if the rule filters a query, that's a model scope / `queryForUser` (`php/repositories`), not an in-memory predicate over a loaded collection; specification for a single `if` in one place (over-engineering); mixing check with action (specification only answers yes/no, performs no side effects).

### Step 4 — place & name correctly

- Tree placement → `php/laravel-structure`.
- Names (VO = domain noun, no suffix; DTO = `Data`/`Form`) → `general/naming-conventions`.
- Pipeline/Specification assembly via DI → `php/dependency-injection`.
- Construction (concrete VO/DTO constructors) via named args → `php/named-arguments`.
- NB: named arg = concrete signature only; polymorphic call via interface (`handle`/`isSatisfiedBy`) → positional only.

## Which snippet to open

| Situation | File |
|:--|:--|
| Primitive with validity rule/behavior, checked in several places (email, money, range) | `snippets/value-object.php` |
| Move field set between layers without logic; sync result (delta) — without `spatie/laravel-data` | `snippets/dto.php` |
| Processing splits into reorderable stages over one value | `snippets/pipeline.php` |
| "Matches / not" business rule to combine and/or/not (in-memory) | `snippets/specification.php` |

## Quality checklist

- [ ] Before introducing a pattern, verified it removes duplication/protects an invariant, not added "for the future" (anti-bloat).
- [ ] **VO** has invariant in constructor + behavior; not anemic wrapper; immutable (`final readonly`, no setters); value equality.
- [ ] **DTO** has no business logic, no deps on `Request`/Eloquent/DB; `final readonly`, promoted properties; not a God-object.
- [ ] "Bare DTO vs `spatie/laravel-data`" choice is deliberate: need casts/validation-from-request/`#[TypeScript]` → package (`laravel-data-layer/laravel-data`), else bare.
- [ ] **Pipeline** not reinvented when `Illuminate\Pipeline` available; steps independent, no side effects on others' data; order = data, not code.
- [ ] **Specification** used for in-memory rules, not query filtering (filter → scope/`queryForUser`, see `php/repositories`); predicate only, no actions.
- [ ] Polymorphic interface calls (`PipeStage::handle`, `Specification::isSatisfiedBy`) — positional, not named (impl param name not fixed by interface → `Unknown named parameter`).
- [ ] Value fields with invariant extracted into VO; DTO only carries them (patterns combine, don't duplicate responsibility).
- [ ] Domain names: VO without `-VO/-Object` suffix, DTO = `Data`/`Form` (see `general/naming-conventions`); constructors called via named arguments (`php/named-arguments`).
- [ ] Namespaces/names neutral (`App\Domain\...`), no project-specific business terms.

## Links

- `laravel-data-layer/laravel-data` — DTO on `spatie/laravel-data` (typed data layer, validation from request, casts, `#[TypeScript]`); this skill is bare VO/DTO/pipeline/specification without a package and choosing between them.
- `php/repositories` — where "specification over DB" goes (model scopes, `queryForUser`) and delta-DTO as a store-repository sync result.
- `php/named-arguments` — calling VO/DTO constructors via named arguments.
- `general/naming-conventions` — entity names: VO (domain noun, no suffix), DTO (`Data`/`Form`), and why a `findByX…OrFail` explosion is replaced by criterion/specification.
- `php/laravel-structure` — where VO/DTO/Pipeline/Specification sit in the project tree.
- `php/dependency-injection` — assembling Pipeline/Specification via constructor injection.
