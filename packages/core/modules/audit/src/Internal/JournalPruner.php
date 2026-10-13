<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal;

use AzGuard\Storage\StorageDescriptor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * @internal Journal retention: deletes the `audit_log` rows of one panel that occurred before a cut-off.
 *
 * Each batch selects up to `BATCH` ids (`panel = ? AND occurred_at < ?`, by id) and deletes `WHERE id IN (…)` in one
 * short transaction of its own on the connection of the storage, until a batch finds nothing. The journal is not
 * authority: pruning takes no panel lock, opens no storage mutation, moves no revision and publishes no event, so it
 * never waits for a write and a write never waits for it beyond the rows of one batch. A row at or after the cut-off
 * is never selected, also while new rows are journaled. A deadlock or serialization failure retries only its batch,
 * up to `RETRIES` times.
 */
final class JournalPruner
{
    public const int BATCH = 1000;

    public const int RETRIES = 3;

    /** SQLSTATE of a serialization failure (MySQL and MariaDB report a deadlock with it) and of a PostgreSQL deadlock. */
    private const array RETRIED_STATES = ['40001', '40P01'];

    /** MySQL and MariaDB driver code of a deadlock. */
    private const int MYSQL_DEADLOCK = 1213;

    /**
     * Returns the number of rows deleted.
     *
     * @throws QueryException when a batch still fails after `RETRIES` retries, or fails otherwise
     */
    public static function prune(string $panel, StorageDescriptor $storage, DateTimeImmutable $before): int
    {
        $connection = app(DatabaseManager::class)->connection($storage->connection);
        $table = $storage->prefix.AuditSchema::TABLE;
        $cutoff = $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $removed = 0;

        do {
            [$selected, $deleted] = self::batch($connection, $table, $panel, $cutoff);
            $removed += $deleted;
        } while ($selected > 0);

        return $removed;
    }

    /** @return array{int, int} rows selected and rows deleted by one batch */
    private static function batch(Connection $connection, string $table, string $panel, string $cutoff): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $connection->transaction(static function () use ($connection, $table, $panel, $cutoff): array {
                    $ids = $connection->table($table)->where('panel', $panel)->where('occurred_at', '<', $cutoff)
                        ->orderBy('id')->limit(self::BATCH)->pluck('id')->all();

                    return [count($ids), $ids === [] ? 0 : $connection->table($table)->whereIn('id', $ids)->delete()];
                });
            } catch (QueryException $error) {
                if ($attempt >= self::RETRIES || ! self::retried($error)) {
                    throw $error;
                }
            }
        }
    }

    private static function retried(QueryException $error): bool
    {
        $info = $error->errorInfo ?? [];
        $state = $info[0] ?? $error->getCode();
        $code = $info[1] ?? null;

        return (is_string($state) && in_array($state, self::RETRIED_STATES, true))
            || ((is_int($code) || is_string($code)) && (int) $code === self::MYSQL_DEADLOCK);
    }
}
