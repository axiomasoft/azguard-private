# Laravel Authorization

## Трёхслойная авторизация

```
PermissionEnum  →  Policy (Gate)  →  Abilities DTO (frontend)
```

Каждый слой имеет свою ответственность. Добавление нового права = обновление всех трёх.

---

## Слой 1: PermissionEnum

Gate ability identifier — строковое значение enum.

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

Значение enum (`'ticket.view'`) = строка, которую принимает `Gate::allows()` и `$this->authorize()`.

---

## Слой 2: Policy

```
app/Policies/<Domain>/<Subprocess>Policy.php
```

Правила:
- Класс: `final class <Subprocess>Policy`
- Методы: `can<Action>(User $user, Model $model): bool`
- Атрибут `#[GateAbility(permission: PermissionEnum::Case)]` на каждом методе
- Никакой persistence, никаких side effects

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

### Регистрация Gate (AppServiceProvider)

```php
Gate::define(
    ability: CommonPermission::View->value,
    callback: [CommonPolicy::class, 'canView'],
);
```

### Проверки в коде

```php
// В Controller
$this->authorize(ability: CommonPermission::Edit->value, arguments: $ticket);

// В коде напрямую
Gate::allows(ability: CommonPermission::Edit->value, arguments: $ticket);
if (Gate::denies(...)) { abort(403); }
```

---

## Слой 3: Abilities DTO (frontend projection)

```
app/Dto/<Domain>/Policy/<Subprocess>Abilities.php
```

Serializable DTO с boolean свойствами — транслирует Gate-чеки в Inertia props.

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

### Передача в Inertia

```php
// В Controller
return Inertia::render('Orders/Show', [
    'ticket' => OrderResource::make($ticket),
    'abilities' => CommonAbilities::fromOrder(ticket: $ticket),
]);
```

```ts
// Во Vue (типизированный через #[TypeScript])
const props = defineProps<{ abilities: CommonAbilities }>();

if (props.abilities.edit) { /* показать кнопку */ }
```

---

## Чеклист: добавление нового права

1. Добавить case в `PermissionEnum`
2. Добавить метод `can<Action>()` в Policy + `#[GateAbility]` атрибут
3. Зарегистрировать в `Gate::define()` (AppServiceProvider или ServiceProvider домена)
4. Добавить boolean поле в Abilities DTO
5. Добавить проверку `Gate::allows()` в Abilities DTO `fromOrder()` / `fromModel()`
6. Запустить `php artisan typescript:transform` (если `#[TypeScript]` есть)

---

## Структура файлов (пример)

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
