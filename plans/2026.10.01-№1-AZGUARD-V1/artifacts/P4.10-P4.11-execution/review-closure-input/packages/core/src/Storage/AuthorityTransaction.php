<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\InvalidConfigurationException;
use Fiber;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use PDO;

/** @internal A capability bound to one registered root attempt, handle and execution context. */
final class AuthorityTransaction
{
    private bool $active = true;

    /** @param Fiber<mixed, mixed, mixed, mixed>|null $fiber */
    private function __construct(
        private readonly Storage $storage,
        private readonly DatabaseTransactionsManager $manager,
        private readonly DatabaseTransactionRecord $root,
        private readonly PDO $pdo,
        private readonly ?Fiber $fiber,
    ) {}

    public static function begin(Storage $storage): self
    {
        $manager = app('db.transactions');
        $root = $manager->getPendingTransactions()->first(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $storage->connectionName() && $record->level === 1);

        if (! $root instanceof DatabaseTransactionRecord || $storage->connection()->transactionLevel() !== 1) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Authority marker requires a registered root transaction.');
        }

        return new self($storage, $manager, $root, $storage->connection()->getPdo(), Fiber::getCurrent());
    }

    public function assertActive(): void
    {
        $connection = $this->storage->connection();

        if (! $this->active || $this->fiber !== Fiber::getCurrent() || $connection->transactionLevel() < 1
            || $connection->getRawPdo() !== $this->pdo || ! $this->pdo->inTransaction()
            || ! $this->manager->getPendingTransactions()->contains(fn (DatabaseTransactionRecord $record): bool => $record === $this->root)) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Authority root attempt, PDO or execution context has changed.');
        }
    }

    public function close(): void
    {
        $this->active = false;
    }
}
