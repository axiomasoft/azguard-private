> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel DTO (Spatie LaravelData)

## Buckets — five types DTO

```
app/Dto/
├── <Domain>/
│   ├── Form/       ← editable contracts (user login)
│   ├── View/       ← read-only projections (list, detail)
│   ├── Policy/     ← Abilities DTO for frontend (boolean-rights)
│   └── Mapper/     ← transformation Model → View DTO
└── Actions/
    └── <Domain>/   ← Command DTO for use-cases
```

| Bucket | Purpose | Examples |
|:---|:---|:---|
| `Form/` | User login, editable contracts | `Form`, `Participants`, `AttachmentData` |
| `View/` | Read-only projections for UI | `ListItemView`, `DetailView`, `HistoryView` |
| `Policy/` | Boolean-abilities for frontend | `CommonAbilities`, `QuestionAbilities` |
| `Mapper/` | Model → View DTO transformation | `ViewMapper`, `ListMapper` |
| `Actions/<Domain>/` | Command DTO for Action::execute() | `StoreCommand`, `WrittenReplyCommand` |

---

## Basic Rules

```php
// Always: final + declare(strict_types=1)
final class ListItemView extends Data {}

// Nested Data instead raw arrays
// ✅
public OrderParticipant $creator;
// ❌
public array $creator;

// Typed collections instead array
// ✅
/** @var array<int, ParticipantView> */
public array $participants;
// ❌
public array $participants; // without element typing
```

---

## Factory: internal assembly from model

For assembly DTO inside the application (not from user input) — always via factory:

```php
public static function fromDb(Order $ticket): self
{
    $ticket->load([
        'creator',
        'participants.user',
        'responsibles.user',
    ]);

    return self::factory()
        ->withoutValidation()
        ->withoutMagicalCreation()
        ->from(self::buildPayload(ticket: $ticket));
}

private static function buildPayload(Order $ticket): array
{
    return [
        'id' => $ticket->id,
        'status' => $ticket->status,
        'creator' => UserView::fromModel(user: $ticket->creator),
        'participants' => $ticket->participants
            ->map(fn (Participant $p) => ParticipantView::fromModel(participant: $p))
            ->all(),
    ];
}
```

---

## Static factory methods

Standard named constructors:

```php
// From Eloquent models
public static function fromModel(Model $model): self { ... }

// From the database (with eager-load inside)
public static function fromDb(Order $ticket): self { ... }

// Empty state (for create-forms)
public static function fromEmpty(): self { ... }

// From FormRequest
public static function fromRequest(OrderRequest $request): self { ... }
```

---

## TypeScript generation

`#[TypeScript]` on the class or enum → auto-generation TypeScript types:

```php
#[TypeScript]
final class ListItemView extends Data
{
    public function __construct(
        public int $id,
        public string $subject,
        public OrderStatus $status,
        public UserView $creator,
    ) {}
}
```

After the changes, run:

```bash
php artisan typescript:transform
```

Generates TypeScript interface/type, which is used in Vue-components without manual duplication.

---

## toArray() — only if necessary

Custom `toArray()` is written only when:
- Need a special contract (`false|array`, custom flattening)
- Controlled serialization required enum/union, not covered by default

In most cases - standard serialization Spatie Data is sufficient.

---

## Form DTO — example

```php
#[TypeScript]
final class Form extends Data
{
    public function __construct(
        public ?string $subject,
        public ?string $description,
        /** @var array<int, AttachmentFileData> */
        public array $attachmentFiles = [],
    ) {}

    public static function fromRequest(StoreRequest $request): self
    {
        return self::factory()
            ->withoutMagicalCreation()
            ->from([
                'subject' => $request->input(key: 'subject'),
                'description' => $request->input(key: 'description'),
                'attachmentFiles' => AttachmentFileData::collect(
                    items: $request->file(key: 'attachment_files', default: []),
                ),
            ]);
    }
}
```

---

## View DTO — example

```php
#[TypeScript]
final class ListItemView extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $subject,
        public OrderStatus $status,
        public UserView $creator,
        public \Carbon\CarbonImmutable $createdAt,
    ) {}

    public static function fromModel(Order $ticket): self
    {
        return new self(
            id: $ticket->id,
            number: $ticket->number,
            subject: $ticket->subject,
            status: $ticket->status,
            creator: UserView::fromModel(user: $ticket->creator),
            createdAt: $ticket->created_at,
        );
    }
}
```

---

## Checklist code review DTO

- [ ] Class `final` and `declare(strict_types=1)`
- [ ] No «raw» `array`, if possible to express nested Data
- [ ] `toArray()` missing or justified by domain logic
- [ ] Factory-assembly via `withoutValidation()` / `withoutMagicalCreation()`
- [ ] `#[TypeScript]` and `typescript:transform` after contract change
- [ ] Test for shape payload when changing an external contract
