<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal;

use AzGuard\Events\AccessEvent;
use AzGuard\Storage\StorageDescriptor;
use DateTimeZone;

/**
 * @internal The row of the audit journal for one event: its envelope as columns and its whole serialized form as the
 * payload.
 */
final class JournalRow
{
    /** The columns of a journal row; every one is given, a value that has no meaning is null. */
    public const array COLUMNS = ['event_id', 'type', 'panel', 'tenant_key', 'tenant_type', 'tenant_id', 'subject_type', 'subject_id',
        'actor_type', 'actor_id', 'actor_reason', 'correlation_id', 'payload', 'occurred_at'];

    /**
     * The journal row of an event with the payload as an array.
     *
     * @return array<string, mixed>
     */
    public static function of(AccessEvent $event): array
    {
        $subject = $event->subject();

        return [
            'event_id' => $event->eventId, 'type' => $event->type()->value, 'panel' => $event->panel,
            'tenant_key' => $event->tenant->key(), 'tenant_type' => $event->tenant->type(), 'tenant_id' => $event->tenant->id(),
            'subject_type' => $subject?->type(), 'subject_id' => $subject?->id(),
            'actor_type' => $event->actor?->type, 'actor_id' => $event->actor?->id, 'actor_reason' => $event->actor?->reason,
            'correlation_id' => $event->correlationId, 'payload' => $event->toArray(),
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The row as stored in a storage: subject and actor ids in the canonical form of its host keys, the payload JSON.
     *
     * @return array<string, scalar|null>
     */
    public static function stored(AccessEvent $event, StorageDescriptor $storage): array
    {
        $row = self::of($event);

        foreach (['subject_id', 'actor_id'] as $column) {
            if (is_int($row[$column]) || is_string($row[$column])) {
                $row[$column] = $storage->canonicalKey($row[$column]);
            }
        }
        $row['payload'] = json_encode($row['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        /** @var array<string, scalar|null> $row */
        return $row;
    }
}
