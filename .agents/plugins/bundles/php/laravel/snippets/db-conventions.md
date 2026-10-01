> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Laravel DB Conventions

> Setting up the model itself (attributes, fillable, casts) — in `eloquent-model.md`.

## Table naming scheme

Formula: `<domain>_<entity>`, snake_case, plural noun.

| Type | Pattern | Example |
|:---|:---|:---|
| Basic Domain Model | `<entities>` | `tickets`, `users`, `meetings` |
| Child in domain | `<domain>_<entity>` | `ticket_messages`, `ticket_participants` |
| History / audit | `<domain>_history` | `ticket_history` |
| Pivot two domains | `<domain_a>_<domain_b>` (alphab.) | `meeting_ticket_agendas` |
| Self-ref pivot | `<domain>_related` | `ticket_related` |
| Settings / config | `<domain>_settings` | `ticket_settings` |

**Rule:** if the entity belongs to a domain `Order` — all its auxiliary tables begin with `ticket_`.

## Model as the sole source of the table name

The table name is specified **once** — in `#[Table(name: '...')]` on the model. In migrations - only through `getTable()`, no string hardcodes.

```php
// ✅ Correct - through the model
Schema::create((new Participant())->getTable(), fn (Blueprint $table) => ...);

$table->foreignId(column: 'participant_id')
    ->constrained(table: (new Participant())->getTable())
    ->cascadeOnDelete();

// ❌ Wrong - hardcode
Schema::create('ticket_participants', ...);
$table->foreignId('participant_id')->constrained('ticket_participants');
```

**Why:** rename table = edit only `#[Table]` in the model, we do not touch migrations.

## Migration template

```php
<?php

declare(strict_types=1);

use App\Models\Order\Order;
use App\Models\User\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(table: $this->table(), callback: function (Blueprint $table): void {
            $table->id();
            $table->foreignId(column: 'ticket_id')
                ->constrained(table: (new Order())->getTable())
                ->cascadeOnDelete();
            $table->foreignId(column: 'user_id')
                ->constrained(table: (new User())->getTable())
                ->nullOnDelete();
            $table->string(column: 'status', length: 50)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(table: $this->table());
    }

    private function table(): string
    {
        return (new TargetModel())->getTable();
    }
};
```

## Rules cascade

| Communication | Cascade |
|:---|:---|
| Mandatory parent | `->cascadeOnDelete()` |
| Optional parent | `->nullOnDelete()` |
| Do not delete child | `->restrictOnDelete()` |

## Quickstart

```bash
php artisan make:model ModelName -m --no-interaction
```

After generation:
1. Set up model → `eloquent-model.md`
2. Write a migration using the template above
