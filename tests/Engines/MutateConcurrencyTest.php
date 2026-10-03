<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Engines\Support\Processes;

beforeEach(function (): void {
    $this->storage = app(StorageRegistry::class)->get('default');
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    $this->storage->mutate('admin', fn () => null);
});
afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
});

it('serializes fifty independent processes without losing a bump', function (): void {
    $processes = new Processes;
    $barrier = sys_get_temp_dir().'/azguard-barrier-'.bin2hex(random_bytes(8));
    $workers = [];
    for ($i = 0; $i < 50; $i++) {
        $workers[] = $processes->start(['barrier' => $barrier]);
    }
    touch($barrier);

    try {
        $attempts = array_map(fn (int $worker): int => $processes->finish($worker)['attempts'], $workers);
        expect(max($attempts))->toBeLessThanOrEqual(3)->and($this->storage->state('admin')->version)->toBe(50);
        file_put_contents(sys_get_temp_dir().'/azguard-attempts-'.getenv('DB_CONNECTION').'.json', json_encode($attempts));
    } finally {
        unlink($barrier);
    }
})->group('engines');
