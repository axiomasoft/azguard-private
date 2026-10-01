> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel Authorization

## Three-layer authorization

```
PermissionEnum  →  Policy (Gate)  →  Abilities DTO (frontend)
```

Each layer has its own responsibilities. Adding a new right = update of all three.

---

## Layer 1: PermissionEnum

Gate ability identifier — string value enum.

```
app/Enums/<Domain>/Permissions/<Subprocess>Permission.php
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Order\Permissions;

enum CommonPermission: string
{
    case View = 'ticket.view';
    case Edit = 'ticket.edit';
    case Create = 'ticket.create';
    case Register = 'ticket.register';
    case Finish = 'ticket.finish';
}
```

Meaning enum (`'ticket.view'`) = string that is accepted `Gate::allows()` and `$this->authorize()`.

---

## Layer 2: Policy

```
app/Policies/<Domain>/<Subprocess>Policy.php
```

Rules:
- Class: `final class <Subprocess>Policy`
- Methods: `can<Action>(User $user, Model $model): bool`
- Attribute `#[GateAbility(permission: PermissionEnum::Case)]` on each method
- None persistence, none side effects

```php
<?php

declare(strict_types=1);

namespace App\Policies\Order;

use App\Attributes\Auth\GateAbility;
use App\Enums\Order\Permissions\CommonPermission;
use App\Models\Order\Order;
use App\Models\User\User;
use App\Services\Order\OrderAccessEvaluator;

final class CommonPolicy
{
    public function __construct(
        private readonly OrderAccessEvaluator $accessEvaluator,
    ) {}

    #[GateAbility(permission: CommonPermission::View)]
    public function canView(User $user, Order $ticket): bool
    {
        return $this->accessEvaluator->hasAccess(user: $user, ticket: $ticket);
    }

    #[GateAbility(permission: CommonPermission::Edit)]
    public function canEdit(User $user, Order $ticket): bool
    {
        return $this->accessEvaluator->hasAccess(user: $user, ticket: $ticket)
            && $ticket->status->isEditable();
    }

    #[GateAbility(permission: CommonPermission::Finish)]
    public function canFinish(User $user, Order $ticket): bool
    {
        return $this->accessEvaluator->isResponsible(user: $user, ticket: $ticket)
            && $ticket->status->canFinish();
    }
}
```

### Registration Gate (AppServiceProvider)

```php
Gate::define(
    ability: CommonPermission::View->value,
    callback: [CommonPolicy::class, 'canView'],
);
```

### Checks in code

```php
// B Controller
$this->authorize(ability: CommonPermission::Edit->value, arguments: $ticket);

// In code directly
Gate::allows(ability: CommonPermission::Edit->value, arguments: $ticket);
if (Gate::denies(...)) { abort(403); }
```

---

## Layer 3: Abilities DTO (frontend projection)

```
app/Dto/<Domain>/Policy/<Subprocess>Abilities.php
```

Serializable DTO with boolean properties - broadcasts Gate-checks in Inertia props.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Order\Policy;

use App\Enums\Order\Permissions\CommonPermission;
use App\Models\Order\Order;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Data;

#[TypeScript]
final class CommonAbilities extends Data
{
    public function __construct(
        public bool $view,
        public bool $edit,
        public bool $create,
        public bool $register,
        public bool $finish,
    ) {}

    public static function fromOrder(Order $ticket): self
    {
        return new self(
            view: Gate::allows(ability: CommonPermission::View->value, arguments: $ticket),
            edit: Gate::allows(ability: CommonPermission::Edit->value, arguments: $ticket),
            create: Gate::allows(ability: CommonPermission::Create->value),
            register: Gate::allows(ability: CommonPermission::Register->value, arguments: $ticket),
            finish: Gate::allows(ability: CommonPermission::Finish->value, arguments: $ticket),
        );
    }
}
```

### Transfer to Inertia

```php
// B Controller
return Inertia::render('Orders/Show', [
    'ticket' => OrderResource::make($ticket),
    'abilities' => CommonAbilities::fromOrder(ticket: $ticket),
]);
```

```ts
// In Vue (typed via #[TypeScript])
const props = defineProps<{ abilities: CommonAbilities }>();

if (props.abilities.edit) { /* show button */ }
```

---

## Checklist: adding a new right

1. Add case in `PermissionEnum`
2. Add method `can<Action>()` in Policy + `#[GateAbility]` attribute
3. Register in `Gate::define()` (AppServiceProvider or ServiceProvider domain)
4. Add boolean field in Abilities DTO
5. Add check `Gate::allows()` in Abilities DTO `fromOrder()` / `fromModel()`
6. Run `php artisan typescript:transform` (if `#[TypeScript]` yes)

---

## File structure (example)

```
app/
├── Enums/Order/Permissions/
│   ├── CommonPermission.php
│   ├── QuestionPermission.php
│   └── ApplicationPermission.php
├── Policies/Order/
│   ├── CommonPolicy.php
│   ├── QuestionPolicy.php
│   └── ApplicationPolicy.php
└── Dto/Order/Policy/
    ├── CommonAbilities.php
    ├── QuestionAbilities.php
    └── ApplicationAbilities.php
```
