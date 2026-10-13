<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Changes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Changes\ChangeRecord;

/**
 * A participant of the write operations of a panel: it appends rows to its own tables inside the transaction of the
 * operation, so the rows become durable together with the stored grants and vanish with a rollback or a retry.
 *
 * The engine calls `record()` once per operation that produced at least one effect, after the last effect and before
 * the transaction of the operation ends, in the order the panel declares its participants; `$records` keeps the order
 * of the effects. The writer only appends to the tables of `tables()`, only while `record()` runs; an exception rolls
 * the whole operation back, and a retried operation calls the participant again with the records of the new attempt.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ChangeParticipant
{
    /**
     * Base names (without prefix) of the tables this participant appends to; validated at compilation.
     *
     * @return list<non-empty-string>
     */
    public function tables(): array;

    /** @param non-empty-list<ChangeRecord> $records */
    public function record(array $records, OwnedRowsWriter $rows): void;
}
