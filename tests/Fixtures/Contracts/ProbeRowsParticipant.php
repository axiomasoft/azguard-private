<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Changes\ChangeRecord;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** A participant that keeps the contract: one row of `contract_rows` per record, through its writer. */
final class ProbeRowsParticipant implements ChangeParticipant
{
    public function tables(): array
    {
        return ['contract_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        $rows->insert('contract_rows', array_map(static fn (ChangeRecord $record): array => ['note' => $record->event->eventId], $records));
    }
}
