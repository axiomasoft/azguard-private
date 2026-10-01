> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Injection matrix: who injects whom

Source: anonymized production Laravel project.

Constructor injection — is the only domain code dependency channel.
Constructor = dependencies (collaborators), method parameters = data (models, DTO, primitives).

## Allowed directions

| Layer | Can inject | Cannot inject | Note |
|:---|:---|:---|:---|
| **Controller** | Action, Mapper, ReadRepository, View-services | Service records directly (past Action) | Action — via method injection to action method (if needed by one method) or constructor (if several) |
| **Action** | Service, Repository, others Actions | Controller, Request | Composition Actions = composite action, one transaction |
| **Service** | Repository, others Service | Action, Controller, Request | Orchestration and delegation |
| **Repository** | Only narrow filter services (VisibilityService) | Service-orchestrators, Action | Repository - bottom layer, almost no dependencies |
| **Policy** | Service (read-only evaluators) | Repository records, Action | Policy only reads and responds bool |
| **FormRequest** | — (rules via rules()) | Domain services in the constructor | Service for rule - resolve in rules() let's say this HTTP-border |

**NO ONE injects Controller or Request.** Request ends at the border HTTP:
controller maps it into DTO and passes data as method parameters.

## What is NOT considered a dependency (no need to inject)

Infrastructure statics are not collaborators; calling them from domain code is acceptable:

- `DB::transaction(...)` — atomicity boundaries;
- `Event::dispatch(...)` / `SomethingChanged::dispatch(...)` — publish domain events;
- `Gate::allows(...)` — checking permissions in the controller/representation.

The line is simple: the collaborator has his own logic and wants to replace him/test
separately - inject. Framework transport - let's call it static.

## Antipatterns

### 1. `app()` / `resolve()` / façade resolve in domain code

```php
// BAD: hidden dependency - not visible in the signature, not replaced in the test
final readonly class StoreAction
{
    public function execute(StoreCommand $command): Document
    {
        $persistence = app(DocumentPersistenceService::class); // <-- service locator
        ...
    }
}

// GOOD: dependency declared in constructor
final readonly class StoreAction
{
    public function __construct(private DocumentPersistenceService $persistence) {}
}
```

### 2. Injection Request in Service/Action

```php
// BAD: domain code is tied to HTTP, untestable without request
final readonly class StoreDocumentService
{
    public function __construct(private Request $request) {} // <-- prohibited
}

// GOOD: mappit controller Request → DTO, only data goes deeper
$action->execute(command: new StoreCommand(form: $form, user: $request->user()));
```

### 3. Circular dependency

`ServiceA → ServiceB → ServiceA` — the container will crash on resolution, but the attempt itself
means an invalid boundary: the overall logic belongs to the third class. Highlight
`ServiceC`, which both inject.

### 4. Cohesion signals

Many constructor dependencies, or a collaborator used by only one method, warrant checking
cohesion. They do not automatically require splitting a class; follow actual responsibilities.
A controller can use method injection for an operation-specific collaborator.

### 6. Interface without second implementation

`FooServiceInterface + FooService + bind()` for the sake of the only implementation - noise.
The concrete class is resolved by the container zero-config. Interface - only when
real variability (drivers, external integrations, substitution in tests).
