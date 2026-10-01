<?php

declare(strict_types=1);

/**
 * Naming summary gallery Laravel/PHP: bad → good.
 * Principle: The name reveals the INTENT, not the implementation or the type.
 */

// ─────────────────────────────────────────────────────────────────────────────
// 1. Repository methods: NOT method-on-field
// ─────────────────────────────────────────────────────────────────────────────

// ❌ combinatorial explosion: each column generates its own *OrFail
final class OrderRepository
{
    public function findByCodeOrFail(string $code): Order { /* ... */ }
    public function findByEmailOrFail(string $email): Order { /* ... */ }
    public function findBySlugOrFail(string $slug): Order { /* ... */ }
    public function getAllActiveOrdersList(): Collection { /* ... */ }
}

// ✅ scope by intent + standard methods Eloquent; one canonical lookup
final class OrderReadRepository
{
    // route model binding / firstWhere cover field searches - no method needed.
    public function findByIdOrFail(int $id, ?User $user = null): Order
    {
        return $this->queryForUser($user)->findOrFail($id);
    }

    // named query by business sense, not by column
    public function pendingForReview(?User $user = null): Collection
    {
        return $this->queryForUser($user)->pending()->get();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Classes: role, not «manager of something»
// ─────────────────────────────────────────────────────────────────────────────

// ❌ dump without responsibility              // ✅ role in name
final class OrderManager {}                    final class CreateOrder {}          // Action
final class UserHelper {}                      final class UserRegistrar {}        // Service
final class DataProcessor {}                   final class CsvOrderImporter {}     // Importer
final class StringUtils {}                     final class Slugifier {}

// ─────────────────────────────────────────────────────────────────────────────
// 3. DTO / VO / Enum
// ─────────────────────────────────────────────────────────────────────────────

// ❌ type suffix = noise                       // ✅
final class OrderDTO {}                         final class OrderData {}            // spatie/laravel-data
final class OrderObject {}                      final class CreateOrderForm {}      // command input
// VO — domain noun without suffix: Money, EmailAddress, DateRange

enum OrderStatuses: string {}                   // ❌ plural name
enum OrderStatus: string                        // ✅ the only thing, cases PascalCase
{
    case Pending = 'pending';
    case Shipped = 'shipped';
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Event / Job / Listener
// ─────────────────────────────────────────────────────────────────────────────

// Event — fact in past tense:        OrderShipped, InvoicePaid
// Job/Listener — imperative (what to do):   ProcessPayment, SendShipmentNotification

// ─────────────────────────────────────────────────────────────────────────────
// 5. Action: one public method, imperative + object
// ─────────────────────────────────────────────────────────────────────────────

final readonly class PublishDocument
{
    public function __construct(private DocumentReadRepository $documents) {}

    public function __invoke(Document $document, User $actor): Document
    {
        // happy path last, early returns — see code-style-spatie
        return DB::transaction(fn () => $document->publish($actor));
    }
}
