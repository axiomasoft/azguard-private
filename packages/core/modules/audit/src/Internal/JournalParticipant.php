<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal;

use AzGuard\Changes\ChangeRecord;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/**
 * @internal The participant of the audit plugin: one journal row per effect, with the id and payload of the event the
 * root commit publishes, in the transaction of the change. An operation that changed nothing calls no participant,
 * so a repeat leaves no row.
 */
final readonly class JournalParticipant implements ChangeParticipant
{
    /** Rows per insert statement: far below the bound parameters every supported driver accepts. */
    private const int CHUNK = 500;

    public function tables(): array
    {
        return ['audit_log'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        $storage = $rows->storage();
        $journal = array_map(static fn (ChangeRecord $record): array => JournalRow::stored($record->event, $storage), $records);

        foreach (array_chunk($journal, self::CHUNK) as $chunk) {
            $rows->insert('audit_log', $chunk);
        }
    }
}
