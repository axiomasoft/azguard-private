<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Changes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\StorageDescriptor;

/**
 * The append-only writer a `ChangeParticipant` receives: it inserts rows into the tables the participant declared, on
 * the connection of the operation and with the prefix of its storage, and nothing else.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OwnedRowsWriter
{
    /** The storage the operation writes to: its connection, prefix and host key type. */
    public function storage(): StorageDescriptor;

    /**
     * Inserts rows into a declared table; a row is a column map with scalar or null values (JSON encoded by the
     * participant).
     *
     * @param  non-empty-list<array<string, scalar|null>>  $rows
     *
     * @throws UnsupportedDirectWriteException after `record()` returned, for an undeclared table or a non-scalar value
     */
    public function insert(string $table, array $rows): void;
}
