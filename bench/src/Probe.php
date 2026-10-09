<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use PDO;

/**
 * Timings the package does not report, taken from the query events of the worker (audits/2026-10-09-consistency-design.md,
 * owner decision 4). It is bench-side on purpose: no event, hook or clock in the package hot path and no new public API.
 * The cost is precision, stated per probe:
 *
 * - `probe.snapshot`: the span of the statements a check ran inside a snapshot read, from the start of the first to the end
 *   of the last. A snapshot is a transaction on the PDO that Laravel does not know about (`inTransaction()` while
 *   `transactionLevel()` is 0). The BEGIN and COMMIT round trips are not seen, so this is a lower bound by two round trips.
 * - `probe.lock_wait`: the time of the statement that takes the panel lock: `SELECT … FOR UPDATE` on `panel_state`
 *   (PostgreSQL, MySQL, MariaDB) or the first write to `panel_state` (SQLite takes its write lock there). It includes one
 *   round trip.
 * - `probe.lock_hold`: from the end of that statement to COMMIT or ROLLBACK of the write transaction.
 *
 * Samples are named `probe.*`; the runner writes them after the iteration that produced them, with 0 SQL.
 */
final class Probe
{
    /** @var list<array{string, float}> op and microseconds */
    private static array $samples = [];

    private static ?float $snapshotStart = null;

    private static float $snapshotEnd = 0.0;

    private static ?float $locked = null;

    public static function listen(): void
    {
        self::$samples = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            $end = hrtime(true) / 1e3;
            $start = $end - $event->time * 1e3;
            $pdo = $event->connection->getRawPdo();
            $inSnapshot = $pdo instanceof PDO && $pdo->inTransaction() && $event->connection->transactionLevel() === 0;

            if ($inSnapshot) {
                self::$snapshotStart ??= $start;
                self::$snapshotEnd = $end;
            } else {
                self::closeSnapshot();
            }

            if (self::$locked === null && $event->connection->transactionLevel() > 0 && self::takesPanelLock($event->connection, $event->sql)) {
                self::$samples[] = ['probe.lock_wait', $event->time * 1e3];
                self::$locked = $end;
            }
        });
        $release = static function (): void {
            if (self::$locked !== null) {
                self::$samples[] = ['probe.lock_hold', hrtime(true) / 1e3 - self::$locked];
                self::$locked = null;
            }
        };
        Event::listen(TransactionCommitted::class, $release);
        Event::listen(TransactionRolledBack::class, $release);
    }

    /** @return list<array{string, float}> the samples since the last drain */
    public static function drain(): array
    {
        self::closeSnapshot();
        [$samples, self::$samples] = [self::$samples, []];

        return $samples;
    }

    private static function closeSnapshot(): void
    {
        if (self::$snapshotStart !== null) {
            self::$samples[] = ['probe.snapshot', self::$snapshotEnd - self::$snapshotStart];
            self::$snapshotStart = null;
        }
    }

    private static function takesPanelLock(Connection $connection, string $sql): bool
    {
        $sql = strtolower(ltrim($sql));

        if (! str_contains($sql, 'panel_state')) {
            return false;
        }

        return $connection->getDriverName() === 'sqlite'
            ? str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')
            : str_starts_with($sql, 'select') && str_contains($sql, 'for update');
    }
}
