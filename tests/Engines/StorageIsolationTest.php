<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Engines\Support\Processes;

it('isolates storage locks and reads only committed state', function (): void {
    $registry = app(StorageRegistry::class);
    $storage = $registry->get('default');
    $other = new Storage('other', app('db')->connection('secondary'), 'adm_');
    $registry->register($other);
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->drop('other');
    $schema->create('default');
    $schema->create('other');
    $storage->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));
    $ready = sys_get_temp_dir().'/azguard-hold-'.bin2hex(random_bytes(8));
    $done = $ready.'-done';
    $processes = new Processes;
    // The holding worker signals from the pre-commit connection event, after its version UPDATE.
    $worker = $processes->start(['ready' => $ready, 'done' => $done, 'hold_ms' => 2500, 'mode' => 'hold-commit']);

    try {
        Processes::wait($ready);
        expect($storage->state('admin')->version)->toBe(1);
        $other->connection()->statement($other->connection()->getDriverName() === 'pgsql'
            ? "SET lock_timeout = '2s'" : 'SET innodb_lock_wait_timeout = 2');
        $other->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));
        expect(file_exists($done))->toBeFalse()->and($other->state('admin')->version)->toBe(1);
        $processes->finish($worker);
        expect($storage->state('admin')->version)->toBe(2);
    } finally {
        $schema->drop('default');
        $schema->drop('other');
        @unlink($ready);
        @unlink($done);
    }
})->group('engines');
