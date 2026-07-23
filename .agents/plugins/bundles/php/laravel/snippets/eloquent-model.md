# Laravel Eloquent Model

> Нейминг таблиц и шаблон миграции — в `db-conventions.md`.

## Шаблон новой модели

```php
<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Enums\Order\OrderStatus;
use App\Models\User\User;
use App\Observers\Order\Observer;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'tickets')]
#[ObservedBy(Observer::class)]
final class Order extends Model
{
    protected $fillable = [
        'subject',
        'description',
        'status',
        'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'status'     => OrderStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // Relations
    public function creator(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'creator_id');
    }
}
```

---

## PHP атрибуты на классе

| Атрибут | Когда |
|:---|:---|
| `#[Table(name: '...')]` | Всегда — явное имя таблицы (единственный источник) |
| `#[ObservedBy(Observer::class)]` | Если у домена есть Observer |

```php
#[Table(name: 'ticket_participants')]
#[ObservedBy(ParticipantObserver::class)]
final class Participant extends Model {}
```

---

## Mass assignment

Предпочтительно — явный `$fillable`:

```php
// ✅ Явный список — видно что assignable
protected $fillable = ['subject', 'status', 'creator_id'];

// Альтернатива — если нужны все поля (осторожно)
protected $guarded = [];
```

---

## casts() — приоритет над $casts

```php
// ✅ Метод casts() — предпочтительно (Laravel 11+)
protected function casts(): array
{
    return [
        'status'       => OrderStatus::class,     // enum
        'meta'         => 'array',                  // JSON → array
        'is_active'    => 'boolean',
        'published_at' => 'immutable_datetime',     // CarbonImmutable
        'settings'     => 'collection',
    ];
}

// ❌ Устаревший $casts массив
protected $casts = ['status' => OrderStatus::class];
```

---

## Accessors и mutators

```php
// ✅ Новый стиль — Attribute::make()
protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn () => "{$this->first_name} {$this->last_name}",
    );
}

// Getter + setter
protected function slug(): Attribute
{
    return Attribute::make(
        get: fn (string $value) => strtolower(value: $value),
        set: fn (string $value) => Str::slug(title: $value),
    );
}

// ❌ Устаревший стиль
public function getFullNameAttribute(): string { ... }
public function setSlugAttribute(string $value): void { ... }
```

### Правила Attribute::make()

- **Видимость `protected`** — методы-аксессоры всегда `protected`, не `public`.
- **Имя метода — camelCase без префикса `get`**; Laravel сам приводит к
  snake_case для доступа: `fullName()` → `$model->full_name`,
  `isActive()` → `$model->is_active`.
- **Возвращаемый тип `: Attribute`** объявляется всегда; импорт —
  `use Illuminate\Database\Eloquent\Casts\Attribute;`.
- **Короткие замыкания** (`fn`) для простых аксессоров; полное замыкание с
  телом — когда нужна логика. Сигнатура замыкания: `fn ($value, $attributes)`
  (`$value` — сырое значение из БД, `$attributes` — все атрибуты модели).
- **Позиция в классе** — блок аксессоров после связей (relations) и перед
  скоупами (scopes).

### Миграция с legacy-аксессоров

1. Удали `getXxxAttribute()` / `setXxxAttribute()`.
2. Добавь `protected function xxx(): Attribute { return Attribute::make(...); }`.
3. Добавь импорт `Attribute`, если его нет.
4. Прогони тесты — поведение доступа должно остаться прежним.

---

## $hidden

```php
// Поля, которые не должны попадать в JSON / toArray()
protected $hidden = ['password', 'remember_token', 'api_token'];
```

---

## Relations — именование и named arguments

```php
public function creator(): BelongsTo
{
    return $this->belongsTo(related: User::class, foreignKey: 'creator_id');
}

public function participants(): HasMany
{
    return $this->hasMany(related: Participant::class, foreignKey: 'ticket_id');
}

public function tags(): BelongsToMany
{
    return $this->belongsToMany(
        related: Tag::class,
        table: (new OrderTag())->getTable(),
        foreignPivotKey: 'ticket_id',
        relatedPivotKey: 'tag_id',
    );
}
```

---

## $with — eager load по умолчанию

```php
// Только если связь нужна в 95%+ случаев
// Осторожно: увеличивает нагрузку на list-запросы
protected $with = ['creator'];
```

---

## Scopes

```php
// Local scope
public function scopeActive(Builder $query): Builder
{
    return $query->where(column: 'is_active', operator: true);
}

// Использование: Order::query()->active()->get()
```

---

## Чеклист новой модели

- [ ] `#[Table(name: '...')]` — явное имя таблицы
- [ ] `final class` — нет наследования без явной причины
- [ ] `$fillable` — явный список mass-assignable полей
- [ ] `casts()` — enum, datetime, json, boolean типизированы
- [ ] `#[ObservedBy]` — если нужен Observer
- [ ] Relations — named arguments, через `getTable()` для pivot
