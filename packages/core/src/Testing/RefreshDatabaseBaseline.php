<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Storage\AuthorityReadBaseline;
use AzGuard\Storage\Storage;
use Fiber;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use PDO;

/**
 * Recognizes only the wrapping transactions already installed by Laravel's test lifecycle.
 *
 * @internal
 */
final class RefreshDatabaseBaseline implements AuthorityReadBaseline
{
    /** @var array<string, array{record: DatabaseTransactionRecord, pdo: PDO, identity: string}> */
    private array $baselines = [];

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private readonly ?Fiber $fiber;

    public function __construct(private readonly DatabaseTransactionsManager $manager)
    {
        $this->fiber = Fiber::getCurrent();
        foreach ($manager->getPendingTransactions() as $record) {
            if ($record->level !== 1 || $manager->callbackApplicableTransactions()->contains($record)) {
                continue;
            }
            $connection = app('db')->connection($record->connection);
            $pdo = $connection->getRawPdo();

            if ($connection->transactionLevel() === 1 && $pdo instanceof PDO && $pdo->inTransaction()) {
                $this->baselines[$record->connection] = ['record' => $record, 'pdo' => $pdo, 'identity' => bin2hex(random_bytes(32))];
            }
        }
    }

    public function identity(Storage $storage): ?string
    {
        $baseline = $this->baselines[$storage->connectionName()] ?? null;
        $connection = $storage->connection();

        if ($baseline === null || ! app()->runningUnitTests() || app('db.transactions') !== $this->manager
            || $this->fiber !== Fiber::getCurrent() || $connection->transactionLevel() !== 1
            || $connection->getRawPdo() !== $baseline['pdo'] || ! $baseline['pdo']->inTransaction()
            || ! $this->manager->getPendingTransactions()->contains(static fn (DatabaseTransactionRecord $record): bool => $record === $baseline['record'])
            || $this->manager->callbackApplicableTransactions()->contains(static fn (DatabaseTransactionRecord $record): bool => $record === $baseline['record'])) {
            return null;
        }

        return $baseline['identity'];
    }
}
