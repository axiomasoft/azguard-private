---
name: dependency-injection
bucket: php
version: 0.1.0
description: "Layer interaction via constructor injection: final readonly + promotion, injection matrix, Action composition"
risk: write
persona: oss-dev
tags: ["php", "laravel", "di", "architecture"]
requires: []
produces_for: []
outputs: []
snippets: ["action-di.php", "service-orchestrator.php", "composite-action.php", "controller-action.php", "provider-bindings.php", "di-matrix.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

## Context

Laravel: **constructor injection = the only dependency channel for domain code**. Constructor declares collaborators (services, repositories, other Actions); method params carry data (models, DTOs, primitives). Laravel container resolves concrete classes zero-config — no provider bindings by default.

Class canon:

```php
final readonly class StoreAction
{
    public function __construct(
        private DocumentPersistenceService $persistence,
    ) {}
}
```

`final readonly` + **promoted properties**, empty constructor body.

## Algorithm

### 1. Identify layer, check injection direction
Open `di-matrix.md`, verify dependency allowed for layer: Controller → Action; Action → Service / Repository / other Actions; Service → Repository / Service; Repository → only narrow filter-services; Policy → read-only services. **Nobody injects Controller or Request.** Wrong direction → rethink class boundary, not "add a binding".

### 2. Declare deps in constructor
- `final readonly class` + promoted `private` props, no constructor body.
- Concrete classes only. Interface + bind only on real implementation variance (drivers, external integrations, swapping a complex dep in tests) — see `provider-bindings.php`.
- Data (Document, DTO, flags) → `execute()`/method params, not constructor.

### 3. Pick form by scenario
- One write step → Action with 1-2 deps, Command DTO, `DB::transaction` (`action-di.php`).
- Coordinate several repos/services → orchestrator service without its own transaction (`service-orchestrator.php`).
- Multi-step scenario → **composite Action** injecting atomic Actions, one `execute()`, one outer transaction (`composite-action.php`). No "use-case Service".
- Call from HTTP → thin controller: Action via method injection in the action-method (one method needs it) or constructor (several) (`controller-action.php`).

### 4. Collaborators vs infrastructure
Inject what has logic and gets swapped in tests. Infra statics allowed as-is: `DB::transaction`, `Event::dispatch` / `SomethingChanged::dispatch`, `Gate`. Forbidden: `app()` / `resolve()` / facade resolution of **dependencies** in domain code (hidden dep, untestable).

### 5. Validate business rules
Business-rule violation in Action → `ValidationException::withMessages([...])`, not `abort()`. Checks before opening transaction where possible.

### 6. Bad-boundary signals
- Circular dependency → extract a third class with shared logic.
- 6+ constructor deps → class does too much, split.
- Dependency needed by one method of ten → rethink class boundaries.

## Snippet map

| Task | Snippet |
|:---|:---|
| New Action: one dep, or StateMachine + repo, validation | `action-di.php` |
| Service coordinating several repos/services, events, broadcast | `service-orchestrator.php` |
| Multi-step scenario from existing Actions, one transaction | `composite-action.php` |
| Controller obtaining and calling an Action, method vs constructor injection | `controller-action.php` |
| Seems to need interface/bind/contextual binding | `provider-bindings.php` |
| Check injection direction, antipattern breakdown | `di-matrix.md` |

## Quality checklist

- [ ] Class with deps — `final readonly`, promoted properties, empty constructor body
- [ ] Constructor holds only collaborators; data via method params
- [ ] Deps are concrete classes; interface only on real variance (then bind in provider)
- [ ] Injection direction allowed by layer matrix; Request/Controller never injected
- [ ] No `app()` / `resolve()` / facade dependency resolution in Action/Service/Repository
- [ ] Multi-step scenario — composite Action with one outer transaction, not use-case Service
- [ ] Business validation in Action — `ValidationException`, not `abort()`
- [ ] Constructor ≤ 5 deps; no cycles; no "one-method" deps
- [ ] PHP snippets pass `php -l`

## Links

- `php/laravel-structure` — where Actions/Services/Repositories live, file placement rules.
- `php/repositories` — what repositories may inject, their internals.
- `php/laravel` → `layer-boundaries.md` — layer boundaries and call directions; `actions.md` — Action anatomy and Command DTO.
- `php/named-arguments` — constructors and method injection with named arguments.
- `php/php-patterns` — VO/DTO/pipeline assembled via injection.

<!-- ru-source-sha256: 506f411b383f884e8829644559eef0a406b9dbab12053e9b5cb7341db636eba1 -->
