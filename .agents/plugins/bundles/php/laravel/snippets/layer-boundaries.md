# Laravel Layer Boundaries

## Карта слоёв

| Слой | Путь | Ответственность |
|:---|:---|:---|
| **Action** | `app/Actions/` | Единственная точка мутации domain entity |
| **Service** | `app/Services/` | Evaluator / read-side / side-effect |
| **Repository** | `app/Repositories/` | Data-access: read-side + write-side |
| **Controller** | `app/Http/Controllers/` | HTTP-only: auth → validate → action → respond |
| **Policy** | `app/Policies/` | Authorization checks |

---

## Action

**Единственная транзакционная граница use-case.**

- Один публичный `execute()`.
- Принимает модели, DTO, примитивы — **не `Request`**.
- Вся мутация обёрнута в `DB::transaction()`.
- Нарушение бизнес-правил — через `ValidationException`.
- Доменные события (`dispatch`) — из Action или model observer.

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

## Service — три допустимых подтипа

### 1. Domain evaluator (read-only)

Вычисления, предикаты, бизнес-правила — без записи в БД.

```php
final class WorkflowService
{
    public function canTransition(Order $ticket, OrderStatus $to): bool { ... }
    public function resolveAssignee(Order $ticket): User { ... }
}
```

### 2. Read-side facade

Сборка view-model / DTO для UI из нескольких источников.

```php
final class StageViewService
{
    public function buildStageView(Order $ticket): StageView { ... }
}
```

### 3. Side-effect без бизнес-решения

Инфраструктурные обёртки (отправка уведомлений, broadcast). Не принимает решений о том, кому и когда.

```php
final class NotificationService
{
    public function notifyCreated(Order $ticket): void { ... }
}
```

**Service не может:**
- Выполнять mutation domain entity
- Принимать `Illuminate\Http\Request`
- Открывать транзакцию как основную границу use-case
- Делать `abort()` / `abort_if()` — это Controller / Gate

---

## Repository

| Тип | Суффикс | Ответственность |
|:---|:---|:---|
| Read-side | `*ReadRepository` | `Builder`, фильтры, пагинация, eager-load |
| Write-side | `*StoreRepository` | Mutations, sync-операции |

- Write-side **не открывает самостоятельную транзакцию** — работает внутри транзакции вызывающего Action.
- Повторяемые query-предикаты → model scopes, не копипаст.

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

**Только HTTP-слой.** Никакой бизнес-логики.

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

Только проверки авторизации. Никакой persistence. Никаких side effects.

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

| Действие | Action | Service | Repository | Controller | Policy |
|:---|:---:|:---:|:---:|:---:|:---:|
| Принять `Request` | ❌ | ❌ | ❌ | ✅ | ❌ |
| Мутация domain entity | ✅ | ❌ | ✅ | ❌ | ❌ |
| Открыть транзакцию (use-case) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Бизнес-решения | ✅ | ✅ (evaluator) | ❌ | ❌ | ✅ |
| `abort()` / `abort_if()` | ❌ | ❌ | ❌ | ✅ | ❌ |
| dispatch Event | ✅ | ❌ | ❌ | ❌ | ❌ |
| Уведомления | через Service | ✅ (side-effect) | ❌ | ❌ | ❌ |

---

## Anti-patterns

```php
// ❌ Request в Action
class StoreAction {
    public function execute(Request $request): Order { ... }
}

// ❌ Мутация в Service
class OrderService {
    public function save(Order $ticket): void {
        $ticket->save(); // mutation в Service!
    }
}

// ❌ Бизнес-логика в Controller
class OrdersController {
    public function store(Request $request): Response {
        if ($request->user()->hasRole('admin')) { // решение в HTTP-слое!
            Order::create(...);
        }
    }
}

// ❌ Два write-вызова из Controller без транзакции
class OrdersController {
    public function approve(Order $ticket): Response {
        $ticket->update(['status' => 'approved']);     // мутация 1
        $this->historyService->log($ticket, 'approved'); // мутация 2 — не в транзакции!
    }
}
```
