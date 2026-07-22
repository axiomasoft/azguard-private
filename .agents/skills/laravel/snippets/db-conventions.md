# Laravel DB Conventions

> Настройка самой модели (атрибуты, fillable, casts) — в `eloquent-model.md`.

## Схема именования таблиц

Формула: `<domain>_<entity>`, snake_case, существительное во множественном числе.

| Тип | Паттерн | Пример |
|:---|:---|:---|
| Основная модель домена | `<entities>` | `tickets`, `users`, `meetings` |
| Дочерняя в домене | `<domain>_<entity>` | `ticket_messages`, `ticket_participants` |
| History / audit | `<domain>_history` | `ticket_history` |
| Pivot двух доменов | `<domain_a>_<domain_b>` (alphab.) | `meeting_ticket_agendas` |
| Self-ref pivot | `<domain>_related` | `ticket_related` |
| Settings / config | `<domain>_settings` | `ticket_settings` |

**Правило:** если сущность принадлежит домену `Order` — все её вспомогательные таблицы начинаются с `ticket_`.

## Модель как единственный источник имени таблицы

Имя таблицы задаётся **один раз** — в `#[Table(name: '...')]` на модели. В миграциях — только через `getTable()`, никаких строковых хардкодов.

```php
// ✅ Правильно — через модель
Schema::create((new Participant())->getTable(), fn (Blueprint $table) => ...);

$table->foreignId(column: 'participant_id')
    ->constrained(table: (new Participant())->getTable())
    ->cascadeOnDelete();

// ❌ Неправильно — хардкод
Schema::create('ticket_participants', ...);
$table->foreignId('participant_id')->constrained('ticket_participants');
```

**Зачем:** переименование таблицы = правка только `#[Table]` в модели, миграции не трогаем.

## Шаблон миграции

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

## Правила cascade

| Связь | Cascade |
|:---|:---|
| Обязательный родитель | `->cascadeOnDelete()` |
| Опциональный родитель | `->nullOnDelete()` |
| Не удалять дочернее | `->restrictOnDelete()` |

## Quickstart

```bash
php artisan make:model ModelName -m --no-interaction
```

После генерации:
1. Настроить модель → `eloquent-model.md`
2. Написать миграцию по шаблону выше
