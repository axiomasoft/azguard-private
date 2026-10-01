> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel Eloquent Model

> Table naming and migration template - in `db-conventions.md`.

## New model template

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

## PHP attributes on the class

| Attribute | When |
|:---|:---|
| `#[Table(name: '...')]` | Always - explicit table name (single source) |
| `#[ObservedBy(Observer::class)]` | If the domain has Observer |

```php
#[Table(name: 'ticket_participants')]
#[ObservedBy(ParticipantObserver::class)]
final class Participant extends Model {}
```

---

## Mass assignment

Preferably - explicit `$fillable`:

```php
// ✅ Explicit list - it is clear that assignable
protected $fillable = ['subject', 'status', 'creator_id'];

// Alternative - if all fields are needed (carefully)
protected $guarded = [];
```

---

## casts() — priority over $casts

```php
// ✅ Method casts() — preferred (Laravel 11+)
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

// ❌ Deprecated $casts array
protected $casts = ['status' => OrderStatus::class];
```

---

## Accessors and mutators

```php
// ✅ New style - Attribute::make()
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

// ❌ Outdated style
public function getFullNameAttribute(): string { ... }
public function setSlugAttribute(string $value): void { ... }
```

### Rules Attribute::make()

- **Visibility `protected`** — accessor methods are always `protected`, not `public`.
- **Method name - camelCase without prefix `get`**; Laravel itself leads to
  snake_case to access: `fullName()` → `$model->full_name`,
  `isActive()` → `$model->is_active`.
- **Return type `: Attribute`** is always declared; import -
  `use Illuminate\Database\Eloquent\Casts\Attribute;`.
- **Short circuits** (`fn`) for simple accessors; full circuit with
  body - when logic is needed. Closure signature: `fn ($value, $attributes)`
  (`$value` — raw value from the database, `$attributes` — all model attributes).
- **Class position** — accessor block after connections (relations) and before
  scopes (scopes).

### Migration from legacy-accessors

1. Delete `getXxxAttribute()` / `setXxxAttribute()`.
2. Add `protected function xxx(): Attribute { return Attribute::make(...); }`.
3. Add import `Attribute`, if it is not there.
4. Run the tests - the access behavior should remain the same.

---

## $hidden

```php
// Fields that should not be included in JSON / toArray()
protected $hidden = ['password', 'remember_token', 'api_token'];
```

---

## Relations — naming and named arguments

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

## $with — eager load default

```php
// Only if communication is needed in 95%+ cases
// Caution: Increases stress on list-requests
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

// Usage: Order::query()->active()->get()
```

---

## Checklist for the new model

- [ ] `#[Table(name: '...')]` — explicit table name
- [ ] `final class` — no inheritance without explicit reason
- [ ] `$fillable` — explicit list mass-assignable fields
- [ ] `casts()` — enum, datetime, json, boolean typed
- [ ] `#[ObservedBy]` — if needed Observer
- [ ] Relations — named arguments, via `getTable()` for pivot
