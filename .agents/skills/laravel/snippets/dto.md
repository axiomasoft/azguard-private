# Laravel DTO (Spatie LaravelData)

## Buckets — пять типов DTO

```
app/Dto/
├── <Domain>/
│   ├── Form/       ← редактируемые контракты (вход от пользователя)
│   ├── View/       ← read-only проекции (список, detail)
│   ├── Policy/     ← Abilities DTO для frontend (boolean-права)
│   └── Mapper/     ← трансформация Model → View DTO
└── Actions/
    └── <Domain>/   ← Command DTO для use-cases
```

| Bucket | Назначение | Примеры |
|:---|:---|:---|
| `Form/` | Вход от пользователя, редактируемые контракты | `Form`, `Participants`, `AttachmentData` |
| `View/` | Read-only проекции для UI | `ListItemView`, `DetailView`, `HistoryView` |
| `Policy/` | Boolean-способности для frontend | `CommonAbilities`, `QuestionAbilities` |
| `Mapper/` | Model → View DTO трансформация | `ViewMapper`, `ListMapper` |
| `Actions/<Domain>/` | Command DTO для Action::execute() | `StoreCommand`, `WrittenReplyCommand` |

---

## Базовые правила

```php
// Всегда: final + declare(strict_types=1)
final class ListItemView extends Data {}

// Nested Data вместо raw arrays
// ✅
public OrderParticipant $creator;
// ❌
public array $creator;

// Типизированные коллекции вместо array
// ✅
/** @var array<int, ParticipantView> */
public array $participants;
// ❌
public array $participants; // без типизации элементов
```

---

## Factory: внутренняя сборка из модели

Для сборки DTO внутри приложения (не из user input) — всегда через factory:

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

Стандартные именованные конструкторы:

```php
// Из Eloquent модели
public static function fromModel(Model $model): self { ... }

// Из БД (с eager-load внутри)
public static function fromDb(Order $ticket): self { ... }

// Пустое состояние (для create-форм)
public static function fromEmpty(): self { ... }

// Из FormRequest
public static function fromRequest(OrderRequest $request): self { ... }
```

---

## TypeScript генерация

`#[TypeScript]` на классе или enum → авто-генерация TypeScript типов:

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

После изменений запустить:

```bash
php artisan typescript:transform
```

Генерирует TypeScript interface/type, который используется во Vue-компонентах без ручного дублирования.

---

## toArray() — только при необходимости

Кастомный `toArray()` пишется только когда:
- Нужен особый контракт (`false|array`, custom flattening)
- Требуется контролируемая сериализация enum/union, не покрываемая дефолтом

В большинстве случаев — стандартная сериализация Spatie Data достаточна.

---

## Form DTO — пример

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

## View DTO — пример

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

- [ ] Класс `final` и `declare(strict_types=1)`
- [ ] Нет «сырых» `array`, если можно выразить nested Data
- [ ] `toArray()` отсутствует или обоснован доменной логикой
- [ ] Factory-сборка через `withoutValidation()` / `withoutMagicalCreation()`
- [ ] `#[TypeScript]` и `typescript:transform` после изменения контракта
- [ ] Тест на shape payload при изменении внешнего контракта
