<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Engines\Support\Processes;

it('serializes file-backed SQLite mutations from two processes', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'azguard-sqlite-');
    config()->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'busy_timeout' => 5000]);
    app('db')->purge('testbench');
    app()->forgetInstance(StorageRegistry::class);
    $storage = app(StorageRegistry::class)->get('default');
    app(StorageSchema::class)->create('default');
    $processes = new Processes;

    try {
        $first = $processes->start(['sqlite' => $file, 'hold_ms' => 100]);
        $second = $processes->start(['sqlite' => $file, 'hold_ms' => 100]);
        $processes->finish($first);
        $processes->finish($second);
        expect($storage->state('admin')->version)->toBe(2);
    } finally {
        app('db')->purge('testbench');
        unlink($file);
    }
});
