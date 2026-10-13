<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Changes\ChangeRecord;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** The consumer's participant: one row of its own table `consumer_rows` per effective change, through the writer. */
final class ConsumerParticipant implements ChangeParticipant
{
    public function tables(): array
    {
        return ['consumer_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        $rows->insert('consumer_rows', array_map(static fn (ChangeRecord $record): array => ['note' => $record->event->eventId], $records));
    }
}
