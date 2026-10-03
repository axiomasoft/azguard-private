<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Engines\Support\Processes;
use Illuminate\Database\Schema\Blueprint;

it('retries a real deadlock as its victim and bumps each panel once', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');
    $storage->mutate(['a', 'b'], fn () => null);
    $ddl = $storage->connection()->getSchemaBuilder();
    $ddl->dropIfExists('azg_probe_rows');
    $ddl->create('azg_probe_rows', fn (Blueprint $table) => $table->string('value'));
    $ready = sys_get_temp_dir().'/azguard-deadlock-'.bin2hex(random_bytes(8));
    $blocked = $ready.'-blocked';
    $processes = new Processes;
    $raw = $processes->start(['mode' => 'raw-deadlock', 'ready' => $ready, 'blocked' => $blocked]);

    try {
        Processes::wait($ready);
        $mutation = $processes->start(['mode' => 'deadlock', 'panels' => ['b', 'a'], 'blocked' => $blocked]);
        $processes->finish($raw);
        expect($processes->finish($mutation)['attempts'])->toBe(2)
            ->and($storage->state('a')->version)->toBe(1)->and($storage->state('b')->version)->toBe(1);
    } finally {
        $schema->drop('default');
        $ddl->dropIfExists('azg_probe_rows');
        @unlink($ready);
        @unlink($blocked);
    }
})->group('engines');
