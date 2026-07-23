---
name: laravel
bucket: php
version: 0.4.0
description: "Laravel architecture patterns: actions, repositories, resources"
risk: write
persona: oss-dev
tags: ["php", "laravel", "architecture", "patterns"]
requires: []
produces_for: ["repositories", "dependency-injection", "database", "modular-architecture", "filament", "botkit"]
outputs: []
snippets: ["service-provider.php", "repository-pattern.php", "action-class.php", "form-request.php", "api-resource.php", "event-listener.php", "custom-middleware.php", "actions.md", "authorization.md", "db-conventions.md", "dto.md", "eloquent-model.md", "layer-boundaries.md", "routes-organization.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

Hub-navigator over Laravel architecture patterns. Thin skill; specifics live in 14 snippets (`.php` class skeletons, `.md` rule breakdowns). Placement/naming → sibling skills (Links), not duplicated.

## When to activate

- Creating a new layer class (Action / Repository / Resource / FormRequest / Middleware / Listener / Policy / Service Provider) and need the canonical skeleton.
- Unsure **which layer** logic belongs to (mutation, business decision, query, side-effect).
- Configuring an Eloquent model, migration, authorization, or route organization.

## Algorithm

1. **Pick the layer first** (wrong layer = costliest mistake): open `snippets/layer-boundaries.md`, decide where logic belongs (see Layer boundaries below).
2. **Take the skeleton:** matching `.php` skeleton from the table below.
3. **Pull rules:** for non-trivial topics (action, authorization, DTO, model, DB, routes) read the `.md` breakdown — rules, antipatterns, forbidden-matrices.
4. **Cross-check siblings** for naming/placement/style (Links) — not repeated here.

## Layer boundaries (altitude)

Each class knows its altitude, never reaches into another's. Full map + forbidden-matrix + antipatterns: `snippets/layer-boundaries.md`.

| Layer | Path | Lives here | NOT its job |
|:---|:---|:---|:---|
| **Action** | `app/Actions/` | use-case, the **only** `DB::transaction()` boundary, entity mutation, event dispatch | accepting `Request`, queries-for-UI |
| **Repository** | `app/Repositories/` | data-access: `*ReadRepository` (Builder/filters) + `*StoreRepository` (mutations) | opening a use-case transaction, business decisions, history/events → details `php/repositories` |
| **Model** | `app/Models/` | casts, relations, scopes (reusable predicates), accessors | business scenarios, HTTP |
| **Resource** | `app/Http/Resources/` | shape of API JSON response (read-only transformation) | queries, mutations, authorization |
| **Service** | `app/Services/` | evaluator (read-only) · read-side facade · side-effect without business decision | entity mutation, `Request`, `abort()` |
| **Controller** | `app/Http/Controllers/` | HTTP-only: authorize → validate (FormRequest) → Action → respond | business logic, direct mutations |
| **Policy** | `app/Policies/` | authorization checks | persistence, side-effects |

## Which snippet to open

| Situation | File |
|---|---|
| Deciding which layer logic lives in; need forbidden-matrix "who can't do what" | `snippets/layer-boundaries.md` |
| Use-case with mutation: Action class skeleton | `snippets/action-class.php` |
| Action rules: `final readonly`, Command-DTO, `DB::transaction()`, ValidationException | `snippets/actions.md` |
| Repository skeleton (interface + Eloquent impl) | `snippets/repository-pattern.php` |
| Eloquent model: PHP attributes, fillable, casts, accessors, relations | `snippets/eloquent-model.md` |
| Table naming (domain prefix), migrations via model reference | `snippets/db-conventions.md` |
| HTTP input validation: FormRequest skeleton | `snippets/form-request.php` |
| API JSON response shape: Resource skeleton | `snippets/api-resource.php` |
| DTO on Spatie LaravelData: Form/View/Policy/Mapper/Command + TypeScript generation | `snippets/dto.md` |
| Authorization: PermissionEnum + Policy with `#[GateAbility]` + Abilities-DTO for frontend | `snippets/authorization.md` |
| Registering/bootstrapping a service: ServiceProvider skeleton | `snippets/service-provider.php` |
| Per-request HTTP filter: Middleware skeleton | `snippets/custom-middleware.php` |
| Reacting to a domain event: Listener skeleton | `snippets/event-listener.php` |
| Organizing `routes/web.php`/`api.php`: `Route::controller()->prefix()->name()->group()`, domain subprefixes, precognitive, `throttle:30,1` | `snippets/routes-organization.md` |

## Quality checklist

- [ ] Layer chosen per `layer-boundaries.md`: mutation → Action, query → Repository, JSON → Resource.
- [ ] Action is the only transactional boundary; repository opens no transaction.
- [ ] No class below Controller/FormRequest accepts `Illuminate\Http\Request`.
- [ ] Resource does no queries and no authorization — only transforms already-loaded data.
- [ ] Reusable where-chains extracted into model scopes (see `eloquent-model.md` / `php/repositories`).
- [ ] Placement/naming cross-checked with `php/laravel-structure` and `general/naming-conventions` (not guessed).
- [ ] Code style conforms to `php/code-style-spatie` (`final`, typing, named arguments).

## References

- Project structure & class placement (domain taxonomy, mirror rule) → `php/laravel-structure`
- Repositories (Read/Store split, scopes, visibility) → `php/repositories`
- Class/method naming by intent → `general/naming-conventions`
- Code style (final, typing, named arguments, Spatie conventions) → `php/code-style-spatie`
- DI and component interaction → `php/dependency-injection`
- DTO on the package → `laravel-data-layer/laravel-data`; "bare" domain patterns (VO/pipeline/specification) → `php/php-patterns`
- Migrations & models → `php/database`; growth into modules → `laravel-architecture/modular-architecture`
- Tests for architecture layers → `laravel-testing/laravel-testing`
- General Laravel best practices → `php/laravel-best-practices` (external)

<!-- ru-source-sha256: e097cfa2333db63da2a87d4005133dea1d88cbd3e79bae72329ec538084a46f6 -->
