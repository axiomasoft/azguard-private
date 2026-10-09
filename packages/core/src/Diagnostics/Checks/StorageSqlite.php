<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use Throwable;

/**
 * `storage.sqlite`: a storage on an SQLite file outside WAL mode or without a busy timeout. Decisions read in one
 * `BEGIN DEFERRED` snapshot; without WAL a reader and a writer exclude each other, so a check can wait on a write or
 * fail with SQLITE_BUSY (audits/2026-10-09-consistency-design.md, step 7). AzGuard never switches the journal mode:
 * WAL changes the files of the database and is the host's decision. An in-memory database has one connection and
 * is not reported.
 *
 * @internal
 */
final readonly class StorageSqlite implements DoctorCheck
{
    public function key(): string
    {
        return 'storage.sqlite';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->storages() as $storage) {
            $connection = $storage->connection();

            if ($connection->getDriverName() !== 'sqlite' || in_array($connection->getConfig('database'), [':memory:', null, ''], true)) {
                continue;
            }

            try {
                $mode = $connection->scalar('PRAGMA journal_mode');
                $timeout = $connection->scalar('PRAGMA busy_timeout');
            } catch (Throwable) {
                continue; // storage.migrated reports a storage it cannot reach.
            }
            $mode = is_string($mode) ? strtolower($mode) : 'unknown';
            $timeout = is_numeric($timeout) ? (int) $timeout : 0;
            $problems = [];

            if ($mode !== 'wal') {
                $problems[] = 'journal_mode is '.$mode.', not wal: checks and writes exclude each other';
            }

            if ($timeout === 0) {
                $problems[] = 'busy_timeout is 0: a check that meets a write fails with SQLITE_BUSY at once';
            }

            if ($problems !== []) {
                yield DoctorFinding::warning($this->key(), 'Storage '.$storage->id().' uses SQLite with '.implode('; ', $problems)
                    .'. Supported, but enable WAL (journal_mode=wal) and set busy_timeout for concurrent use.',
                    'storage:'.$storage->id(), ['journal_mode' => $mode, 'busy_timeout' => $timeout]);
            }
        }
    }
}
