<?php

declare(strict_types=1);

/**
 * Сводная галерея именования Laravel/PHP: плохо → хорошо.
 * Принцип: имя раскрывает НАМЕРЕНИЕ, а не реализацию и не тип.
 */

// ─────────────────────────────────────────────────────────────────────────────
// 1. Методы репозитория: НЕ метод-на-поле
// ─────────────────────────────────────────────────────────────────────────────

// ❌ комбинаторный взрыв: каждая колонка порождает свой *OrFail
final class OrderRepository
{
    public function findByCodeOrFail(string $code): Order { /* ... */ }
    public function findByEmailOrFail(string $email): Order { /* ... */ }
    public function findBySlugOrFail(string $slug): Order { /* ... */ }
    public function getAllActiveOrdersList(): Collection { /* ... */ }
}

// ✅ scope по намерению + штатные методы Eloquent; один канонический lookup
final class OrderReadRepository
{
    // route model binding / firstWhere покрывают поиск по полю — метод не нужен.
    public function findByIdOrFail(int $id, ?User $user = null): Order
    {
        return $this->queryForUser($user)->findOrFail($id);
    }

    // именованный запрос по бизнес-смыслу, а не по колонке
    public function pendingForReview(?User $user = null): Collection
    {
        return $this->queryForUser($user)->pending()->get();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Классы: роль, а не «менеджер чего-то»
// ─────────────────────────────────────────────────────────────────────────────

// ❌ свалка без ответственности              // ✅ роль в имени
final class OrderManager {}                    final class CreateOrder {}          // Action
final class UserHelper {}                      final class UserRegistrar {}        // Service
final class DataProcessor {}                   final class CsvOrderImporter {}     // Importer
final class StringUtils {}                     final class Slugifier {}

// ─────────────────────────────────────────────────────────────────────────────
// 3. DTO / VO / Enum
// ─────────────────────────────────────────────────────────────────────────────

// ❌ суффикс типа = шум                       // ✅
final class OrderDTO {}                         final class OrderData {}            // spatie/laravel-data
final class OrderObject {}                      final class CreateOrderForm {}      // вход команды
// VO — доменное существительное без суффикса: Money, EmailAddress, DateRange

enum OrderStatuses: string {}                   // ❌ имя во мн.ч.
enum OrderStatus: string                        // ✅ единственное, кейсы PascalCase
{
    case Pending = 'pending';
    case Shipped = 'shipped';
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Event / Job / Listener
// ─────────────────────────────────────────────────────────────────────────────

// Event — факт в прошедшем времени:        OrderShipped, InvoicePaid
// Job/Listener — императив (что сделать):   ProcessPayment, SendShipmentNotification

// ─────────────────────────────────────────────────────────────────────────────
// 5. Action: один публичный метод, императив + объект
// ─────────────────────────────────────────────────────────────────────────────

final readonly class PublishDocument
{
    public function __construct(private DocumentReadRepository $documents) {}

    public function __invoke(Document $document, User $actor): Document
    {
        // happy path последним, early returns — см. code-style-spatie
        return DB::transaction(fn () => $document->publish($actor));
    }
}
