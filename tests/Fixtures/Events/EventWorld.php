<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Events;

use AzGuard\Events\AccessEvent;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Facades\Event;

/** Records every access event with the transaction level of the host connection at the moment of delivery. */
final class EventWorld
{
    /** @var list<array{event: AccessEvent, level: int}> */
    public static array $seen = [];

    public static function listen(): void
    {
        self::$seen = [];
        Event::listen('AzGuard\Events\*', static function (string $name, array $payload): void {
            $event = $payload[0] ?? null;

            if ($event instanceof AccessEvent) {
                self::$seen[] = ['event' => $event, 'level' => CrmWorld::storage()->connection()->transactionLevel()];
            }
        });
    }

    /** @return list<AccessEvent> */
    public static function events(): array
    {
        return array_map(static fn (array $entry): AccessEvent => $entry['event'], self::$seen);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (AccessEvent $event): string => $event->type()->value, self::events());
    }

    /** @return list<int> */
    public static function levels(): array
    {
        return array_map(static fn (array $entry): int => $entry['level'], self::$seen);
    }

    /** @return list<array<string, mixed>> */
    public static function auditRows(): array
    {
        return array_map(static fn (object $row): array => (array) $row,
            CrmWorld::storage()->table('audit_log')->orderBy('id')->get()->all());
    }

    public static function reset(): void
    {
        self::$seen = [];
    }

    /** Whether the value is made of scalars, null and arrays only, at any depth. */
    public static function plain(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::plain($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }
}
