> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel Layer Boundaries

## Layer map

| Layer | Path | Responsibility |
|:---|:---|:---|
| **Action** | `app/Actions/` | Single mutation point domain entity |
| **Service** | `app/Services/` | Evaluator / read-side / side-effect |
| **Repository** | `app/Repositories/` | Data-access: read-side + write-side |
| **Controller** | `app/Http/Controllers/` | HTTP-only: auth → validate → action → respond |
| **Policy** | `app/Policies/` | Authorization checks |

---

## Action

**Single transactional boundary use-case.**

- One public `execute()`.
- Accepts models DTO, primitives - **not `Request`**.
- The entire mutation is wrapped in `DB::transaction()`.
- Violation of business rules - through `ValidationException`.
- Domain events (`dispatch`) — from Action or model observer.

```php
final readonly class StoreAction
{
    public function __construct(
        private OrderStoreRepository $storeRepository,
        private NotificationService $notificationService,
    ) {}

    public function execute(StoreCommand $command): Order
    {
        return DB::transaction(function () use ($command): Order {
            $ticket = $this->storeRepository->create(command: $command);
            $this->notificationService->notifyCreated(ticket: $ticket);
            return $ticket;
        });
    }
}
```

---

## Service — three valid subtypes

### 1. Domain evaluator (read-only)

Calculations, predicates, business rules - without recording in the database.

```php
final class WorkflowService
{
    public function canTransition(Order $ticket, OrderStatus $to): bool { ... }
    public function resolveAssignee(Order $ticket): User { ... }
}
```

### 2. Read-side facade

Assembly view-model / DTO for UI from several sources.

```php
final class StageViewService
{
    public function buildStageView(Order $ticket): StageView { ... }
}
```

### 3. Side-effect without business solution

Infrastructure wrappers (sending notifications, broadcast). Doesn't make decisions about who or when.

```php
final class NotificationService
{
    public function notifyCreated(Order $ticket): void { ... }
}
```

**Service cannot:**
- Execute mutation domain entity
- Accept `Illuminate\Http\Request`
- Open transaction as primary boundary use-case
- Do `abort()` / `abort_if()` — this Controller / Gate

---

## Repository

| Type | Suffix | Responsibility |
|:---|:---|:---|
| Read-side | `*ReadRepository` | `Builder`, filters, pagination, eager-load |
| Write-side | `*StoreRepository` | Mutations, sync-operations |

- Write-side **does not open a self-transaction** — runs inside the caller's transaction Action.
- Repeatable query-predicates → model scopes, is not a copy-paste.

```php
// Read
final class OrderReadRepository
{
    public function queryForUser(?User $user = null): Builder
    {
        return Order::query()->where(...)->with([...]);
    }
}

// Write
final class OrderStoreRepository
{
    public function create(StoreCommand $command): Order
    {
        return Order::query()->create([...]);
    }
}
```

---

## Controller

**Only HTTP-layer.** No business logic.

```
authorize → validate (FormRequest) → call Action → response/redirect
```

```php
final class OrdersController
{
    public function store(StoreRequest $request): RedirectResponse
    {
        $this->authorize(ability: CommonPermission::Create->value);

        $command = StoreCommand::fromRequest(request: $request);
        $ticket = $this->storeAction->execute(command: $command);

        return to_route(route: 'tickets.show', parameters: $ticket);
    }
}
```

---

## Policy

Authorization checks only. None persistence. None side effects.

```php
final class CommonPolicy
{
    #[GateAbility(permission: CommonPermission::Edit)]
    public function canEdit(User $user, Order $ticket): bool
    {
        return $this->accessEvaluator->hasAccess(user: $user, ticket: $ticket)
            && $ticket->status->isEditable();
    }
}
```

---

## Forbidden Matrix

| Action | Action | Service | Repository | Controller | Policy |
|:---|:---:|:---:|:---:|:---:|:---:|
| Accept `Request` | ❌ | ❌ | ❌ | ✅ | ❌ |
| Mutation domain entity | ✅ | ❌ | ✅ | ❌ | ❌ |
| Open transaction (use-case) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Business Solutions | ✅ | ✅ (evaluator) | ❌ | ❌ | ✅ |
| `abort()` / `abort_if()` | ❌ | ❌ | ❌ | ✅ | ❌ |
| dispatch Event | ✅ | ❌ | ❌ | ❌ | ❌ |
| Notifications | via Service | ✅ (side-effect) | ❌ | ❌ | ❌ |

---

## Anti-patterns

```php
// ❌ Request in Action
class StoreAction {
    public function execute(Request $request): Order { ... }
}

// ❌ Mutation in Service
class OrderService {
    public function save(Order $ticket): void {
        $ticket->save(); // mutation in Service!
    }
}

// ❌ Business logic in Controller
class OrdersController {
    public function store(Request $request): Response {
        if ($request->user()->hasRole('admin')) { // solution in HTTP-layer!
            Order::create(...);
        }
    }
}

// ❌ Two write-call from Controller without transaction
class OrdersController {
    public function approve(Order $ticket): Response {
        $ticket->update(['status' => 'approved']);     // mutation 1
        $this->historyService->log($ticket, 'approved'); // mutation 2 - not in a transaction!
    }
}
```
