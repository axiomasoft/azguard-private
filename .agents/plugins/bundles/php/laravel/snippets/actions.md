> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel Actions

## Concept

**Action** — the only entry point into use-case. One operation, one transaction.

- `final readonly class` (always)
- One public `execute()` — accepts Command DTO, returns Domain object
- `DB::transaction()` wraps the entire mutation
- Does not accept `Illuminate\Http\Request`

## Naming

| Artifact | Template | Example |
|:---|:---|:---|
| Action class | `VerbNounAction` | `StoreAction`, `RegisteredAction`, `WrittenReplyAction` |
| Command DTO | `VerbNounCommand` | `StoreCommand`, `WrittenReplyCommand` |

## File locations

```
app/Actions/<Domain>/<Subprocess>/VerbNounAction.php
app/Dto/Actions/<Domain>/<Subprocess>/VerbNounCommand.php
```

Example for ticket-domain:
```
app/Actions/Order/Common/StoreAction.php
app/Actions/Order/Question/WrittenReplyAction.php
app/Dto/Actions/Order/Common/StoreCommand.php
app/Dto/Actions/Order/Question/WrittenReplyCommand.php
```

## Template Action

```php
<?php

declare(strict_types=1);

namespace App\Actions\Order\Common;

use App\Dto\Actions\Order\Common\StoreCommand;
use App\Models\Order\Order;
use App\Repositories\Order\OrderStoreRepository;
use App\Services\Order\NotificationService;
use Illuminate\Support\Facades\DB;

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

            $this->notificationService->notifyCreated(
                ticket: $ticket,
                user: $command->user,
            );

            return $ticket;
        });
    }
}
```

## Template Command DTO

```php
<?php

declare(strict_types=1);

namespace App\Dto\Actions\Order\Common;

use App\Dto\Order\Form\Form;
use App\Models\User\User;

final readonly class StoreCommand
{
    public function __construct(
        public Form $form,
        public User $user,
        public bool $syncParticipants = true,
    ) {}

    public static function fromRequest(StoreRequest $request): self
    {
        return new self(
            form: Form::fromRequest(request: $request),
            user: $request->user(),
        );
    }
}
```

## Composite Action

When a script consists of several atomic steps − Action is compiled from other Actions. **Not via Service-orchestrator.**

```php
final readonly class RegisterStoreAction
{
    public function __construct(
        private StoreAction $storeAction,
        private RegisteredAction $registeredAction,
    ) {}

    public function execute(RegisterStoreCommand $command): Order
    {
        return DB::transaction(function () use ($command): Order {
            $ticket = $this->storeAction->execute(command: $command->storeCommand);

            return $this->registeredAction->execute(
                command: new RegisteredCommand(ticket: $ticket, user: $command->user),
            );
        });
    }
}
```

## Transaction rules

1. **Action** = single transactional boundary use-case.
2. `Service`, `StateMachine`, `*StoreRepository` work **inside** transaction already open Action.
3. Domain events - `ShouldDispatchAfterCommit` (are published after commit, not up to).
4. Self-transaction in Service — for standalone only integration/batch scripts (not ticket use-case).

## Business rule violations

```php
// Throw ValidationException, not abort()
throw ValidationException::withMessages([
    'status' => 'Transition from the current status is not possible.',
]);
```

## Architectural guardrails (tests)

Contract tests protect the structure Actions:
- Action class should be `final`
- One public method `execute()`
- `execute()` does not accept `Request`
