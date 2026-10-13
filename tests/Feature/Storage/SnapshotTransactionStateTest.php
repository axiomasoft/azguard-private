<?php

declare(strict_types=1);

use AzGuard\Panels\Reads;
use AzGuard\Storage\Internal\Storage;
use AzGuard\Storage\Internal\StorageReadSession;
use AzGuard\Storage\Schema\StorageSchema;

it('reads the real transaction state of a SQLite handle on every PHP version', function (): void {
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    expect(StorageReadSession::transactionOpen($pdo))->toBeFalse();

    // PDO::inTransaction() of pdo_sqlite is false here before PHP 8.4.
    $pdo->exec('BEGIN DEFERRED');
    expect(StorageReadSession::transactionOpen($pdo))->toBeTrue();
    $pdo->exec('COMMIT');
    expect(StorageReadSession::transactionOpen($pdo))->toBeFalse();

    // The PDO flag stays true after a raw COMMIT before PHP 8.4; SQLite is out of the transaction.
    $pdo->beginTransaction();
    expect(StorageReadSession::transactionOpen($pdo))->toBeTrue();
    $pdo->exec('COMMIT');
    expect(StorageReadSession::transactionOpen($pdo))->toBeFalse();

    // The probe leaves no transaction behind.
    $pdo->exec('BEGIN IMMEDIATE');
    expect(StorageReadSession::transactionOpen($pdo))->toBeTrue()->and(StorageReadSession::transactionOpen($pdo))->toBeTrue();
    $pdo->exec('ROLLBACK');
    expect($pdo->exec('BEGIN'))->toBe(0);
    $pdo->exec('ROLLBACK');
});

it('commits and rolls back the snapshot it opens on SQLite', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'azguard-snapshot-state-');
    config()->set('database.connections.snapshot_state', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    $connection = app('db')->connection('snapshot_state');
    $storage = Storage::own('snapshot_state', 'state_');
    app(StorageSchema::class)->create($storage->id());
    $session = $storage->readSession(Reads::Primary);
    $pdo = $connection->getRawPdo();

    try {
        expect($session->snapshot(static fn (): bool => StorageReadSession::transactionOpen($pdo)))->toBeTrue()
            ->and(StorageReadSession::transactionOpen($pdo))->toBeFalse();

        expect(static fn () => $session->snapshot(static fn () => throw new RuntimeException('read failed')))->toThrow(RuntimeException::class)
            ->and(StorageReadSession::transactionOpen($pdo))->toBeFalse();

        // The handle stays usable: a new transaction starts on it.
        $connection->beginTransaction();
        $connection->rollBack();
        expect($connection->transactionLevel())->toBe(0);
    } finally {
        $connection->disconnect();
        @unlink($file);
    }
});
