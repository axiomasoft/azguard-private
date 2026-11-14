<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Storage\StorageDescriptor;
use Closure;

/** @internal Counts writes made by a participant through its supplied writer. */
final readonly class CountedRowsWriter implements OwnedRowsWriter
{
    /** @param Closure(Closure(): void, string, int): void $through */
    public function __construct(private OwnedRowsWriter $rows, private Closure $through) {}

    public function storage(): StorageDescriptor
    {
        return $this->rows->storage();
    }

    public function insert(string $table, array $rows): void
    {
        ($this->through)(fn () => $this->rows->insert($table, $rows), $table, count($rows));
    }
}
