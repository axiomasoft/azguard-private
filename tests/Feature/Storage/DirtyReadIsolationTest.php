<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Panels\Reads;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;

it('refuses authority snapshots on SQLite handles configured for dirty reads', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'azguard-dirty-read-');
    config()->set('database.connections.unsafe', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
    $connection = app('db')->connection('unsafe');
    $reader = new PDO('sqlite:file:'.$file.'?cache=shared');
    $writer = new PDO('sqlite:file:'.$file.'?cache=shared');
    $connection->setPdo($reader)->setReadPdo($reader);
    $storage = Storage::own('unsafe', 'unsafe_');
    app(StorageSchema::class)->create($storage->id());
    $reader->exec('PRAGMA read_uncommitted=1');
    $writer->exec('BEGIN IMMEDIATE');

    try {
        $writer->exec("INSERT INTO unsafe_panel_state (panel, incarnation, version, epoch, updated_at) VALUES ('admin', 'tentative', 1, 1, '2026-10-10 00:00:00')");
        // This is a real dirty row, not a committed fixture that happens to use an unsafe PRAGMA.
        expect($reader->query("SELECT version FROM unsafe_panel_state WHERE panel='admin'")->fetchColumn())->toBe(1);
        expect(function () use ($storage): void {
            $session = $storage->readSession(Reads::Primary);
            $session->snapshot(static fn () => $session->state('admin'));
        })->toThrow(InvalidConfigurationException::class);
    } finally {
        $writer->exec('ROLLBACK');
        $connection->disconnect();
        unset($reader, $writer);
        unlink($file);
    }
});
