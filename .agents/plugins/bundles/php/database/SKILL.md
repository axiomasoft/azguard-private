---
name: database
bucket: php
version: 0.1.0
description: "Database migrations, models, and table naming. Activate when writing migrations, creating models, defining schema, foreign keys, or working in database/migrations/."
risk: write
persona: oss-dev
tags: [php, laravel, database, migrations]
requires: [laravel]
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Database

## Models & tables

- Models declare table via PHP attribute `#[Table(name: '...')]`:

```php
#[Table(name: 'tickets')]
final class Ticket extends Model { ... }
```

- `(new Ticket())->getTable()` returns the table name from the model — never a hardcoded string.

## Migrations: table names via Model

- NEVER hardcode table-name strings in migrations. Always get the name via `(new Model())->getTable()`.

### Migration template

```php
<?php

declare(strict_types=1);

use App\Models\Ticket\Ticket;
use App\Models\Meeting\MeetingAgendaItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // FK via getTable() — not a string
            $table->foreignId('meeting_agenda_item_id')
                ->nullable()
                ->index()
                ->constrained(table: (new MeetingAgendaItem())->getTable())
                ->nullOnDelete();

            $table->string('status', 50)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Ticket())->getTable();
    }
};
```

### Self-referencing FK

```php
// Table references itself (related_ticket_id → tickets.id)
$table->foreignId('related_ticket_id')
    ->nullable()
    ->index()
    ->constrained(table: $this->table())
    ->nullOnDelete();
```

### Alter migrations

```php
public function up(): void
{
    Schema::table($this->table(), function (Blueprint $table): void {
        $table->unsignedBigInteger('owner_id')->nullable()->after('creator_id');
        $table->foreign('owner_id', 'tickets_owner_id_foreign')
            ->references('id')
            ->on((new User())->getTable());
    });
}

private function table(): string
{
    return (new Ticket())->getTable();
}
```

## Table naming

- Plural. Group via short prefixes when needed (`meeting_`, `ticket_`). Name always set in `#[Table(name: '...')]` on the model.

## Create model + migration

```bash
php artisan make:model ModelName -m --no-interaction
```

- Immediately add `#[Table(name: 'table_name')]` to the model, then write the migration using the template above.

## Existing migrations

- Pre-existing migrations use hardcoded strings. New migrations: always use the `getTable()` pattern. When editing old migrations, migrate them to the new style when convenient.

## References

- `php/laravel` — architectural patterns (models, relations, Eloquent).
- `php/laravel-structure` — placing models and factories per domain (`Models/<Domain>`, `database/factories/<Domain>`).
- `laravel-testing/laravel-testing`, `laravel-testing/test-isolation-guard` — test DB isolation when running migrations.
- `architect/data-schema` — data schema design (entities, relations) before migrations.
- `devops/docker-postgres`, `devops/db-test-preflight` — Postgres in Docker and test DB pre-flight.

<!-- ru-source-sha256: be85a74d97166220389678a7dabf4411a78b2c2c0ff2d72e80fe0957d3689094 -->
