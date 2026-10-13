<?php

declare(strict_types=1);

namespace AzGuard\Changes\Internal;

use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\StorageDescriptor;

/**
 * @internal The `OwnedRowsWriter` of one participant in one operation: it appends to the tables that participant
 * declared, through the active mutation of the operation, while its call window is open. It never hands out a
 * builder, a connection or the mutation.
 */
final class OperationRows implements OwnedRowsWriter
{
    private bool $open = true;

    /** @param list<string> $tables the base names the participant declared */
    public function __construct(private readonly StorageMutation $mutation, private readonly StorageDescriptor $storage, private readonly array $tables) {}

    public function storage(): StorageDescriptor
    {
        return $this->storage;
    }

    public function insert(string $table, array $rows): void
    {
        if (! $this->open) {
            throw new UnsupportedDirectWriteException('A participant writes its rows only while record() runs.');
        }

        if (! in_array($table, $this->tables, true)) {
            throw new UnsupportedDirectWriteException('A participant writes only to the tables it declares ('.implode(', ', $this->tables).'), not to '.$table.'.');
        }

        $given = self::given($rows);

        if ($given === [] || ! array_is_list($given)) {
            throw new UnsupportedDirectWriteException('A participant inserts a non-empty list of rows into '.$table.'.');
        }

        foreach ($given as $row) {
            if (! is_array($row) || $row === []) {
                throw new UnsupportedDirectWriteException('A row of '.$table.' is a non-empty map of columns.');
            }

            foreach ($row as $column => $value) {
                if (! is_string($column) || ($value !== null && ! is_scalar($value))) {
                    throw new UnsupportedDirectWriteException('Column '.$column.' of '.$table.' takes a scalar or null; encode structured values first.');
                }
            }
        }

        $this->mutation->table($table)->insert($rows);
    }

    /**
     * The rows as they arrive: the documented type is a promise of the participant, not a fact.
     *
     * @param  array<mixed>  $rows
     * @return array<mixed>
     */
    private static function given(array $rows): array
    {
        return $rows;
    }

    /** Ends the call window: every later insert is refused. */
    public function close(): void
    {
        $this->open = false;
    }
}
