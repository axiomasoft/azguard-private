<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Events\AccessEvent;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use Closure;
use DateTimeZone;
use Fiber;
use InvalidArgumentException;
use WeakMap;

/**
 * The durable journal a change pipe may write to: one row per effective change, in the same transaction as the stored
 * rows, so a rollback or a retry leaves no row.
 *
 * Only the change pipeline opens it, around the pipes of one change and only inside the active mutation of the panel;
 * a call at any other time is `UnsupportedDirectWriteException`. A row is the columns of the journal table with the
 * payload as an array.
 *
 * @api
 */
final class ChangeJournal
{
    /** The columns of a journal row; every one is given, a value that has no meaning is null. */
    public const array COLUMNS = ['event_id', 'type', 'panel', 'tenant_key', 'tenant_type', 'tenant_id', 'subject_type', 'subject_id',
        'actor_type', 'actor_id', 'actor_reason', 'correlation_id', 'payload', 'occurred_at'];

    /** @var list<Closure(array<string, mixed>): void> */
    private array $stack = [];

    /** @var WeakMap<object, list<Closure(array<string, mixed>): void>> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap;
    }

    /**
     * The journal row of an event: its envelope as columns and its whole serialized form as the payload.
     *
     * @return array<string, mixed>
     */
    public static function row(AccessEvent $event): array
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
     * Appends one row to the journal of the change being applied.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws UnsupportedDirectWriteException outside the pipes of a change inside its mutation
     * @throws InvalidArgumentException when the row does not have exactly the columns of the journal
     */
    public function append(array $row): void
    {
        $frames = $this->frames();
        $appender = $frames === [] ? null : $frames[count($frames) - 1];

        if ($appender === null) {
            throw new UnsupportedDirectWriteException('The change journal is written only by a change pipe inside its mutation.');
        }
        $keys = array_keys($row);
        sort($keys);
        $columns = self::COLUMNS;
        sort($columns);

        if ($keys !== $columns || ! is_array($row['payload'])) {
            throw new InvalidArgumentException('A journal row has exactly the columns '.implode(', ', self::COLUMNS).', the payload an array.');
        }

        foreach (['event_id', 'type', 'panel', 'tenant_key', 'correlation_id', 'occurred_at'] as $column) {
            if (! is_string($row[$column]) || $row[$column] === '') {
                throw new InvalidArgumentException('Journal column '.$column.' is a non-empty string.');
            }
        }
        $appender($row);
    }

    /**
     * @internal opened by the change pipeline around the pipes of one change
     *
     * @template T
     *
     * @param  Closure(array<string, mixed>): void  $appender
     * @param  Closure(): T  $work
     * @return T
     */
    public function within(Closure $appender, Closure $work): mixed
    {
        $frames = $this->frames();
        $this->store([...$frames, $appender]);

        try {
            return $work();
        } finally {
            $this->store($frames);
        }
    }

    /** @return list<Closure(array<string, mixed>): void> */
    private function frames(): array
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->stack : ($this->fibers[$fiber] ?? []);
    }

    /** @param list<Closure(array<string, mixed>): void> $frames */
    private function store(array $frames): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->stack = $frames;
        } elseif ($frames === []) {
            unset($this->fibers[$fiber]);
        } else {
            $this->fibers[$fiber] = $frames;
        }
    }
}
