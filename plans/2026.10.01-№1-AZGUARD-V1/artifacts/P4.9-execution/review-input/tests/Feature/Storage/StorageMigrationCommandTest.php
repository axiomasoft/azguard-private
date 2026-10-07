<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Support\ServiceProvider;

it('uses the same template for default and host migrations', function (): void {
    $root = dirname(__DIR__, 3).'/packages/core/';
    expect(str_replace('{{ storage }}', 'default', file_get_contents($root.'stubs/storage-migration.stub')))
        ->toBe(file_get_contents($root.'database/migrations/2026_10_01_000000_create_azguard_storage.php'));
});

it('generates a configured migration once and refuses default and unknown names', function (): void {
    $scratch = sys_get_temp_dir().'/azguard-migration-'.bin2hex(random_bytes(8));
    app()->useDatabasePath($scratch);
    config()->set('azguard.storages.own', ['connection' => null, 'table_prefix' => 'own_', 'host_keys' => null]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);

    try {
        $this->artisan('azguard:storage:migration', ['name' => 'own'])->assertExitCode(0);
        $files = glob($scratch.'/migrations/*_create_azguard_own_storage.php');
        expect($files)->toHaveCount(1);
        $content = file_get_contents($files[0]);
        expect($content)->toContain("create('own')", "drop('own')", 'Add host columns');
        $this->artisan('azguard:storage:migration', ['name' => 'own'])->assertExitCode(1);
        expect(file_get_contents($files[0]))->toBe($content);
        $this->artisan('azguard:storage:migration', ['name' => 'default'])->assertExitCode(1);
        $this->artisan('azguard:storage:migration', ['name' => '../unknown'])->assertExitCode(1);
        $migration = require $files[0];
        $migration->up();
        expect(app(StorageRegistry::class)->get('own')->connection()->getSchemaBuilder()->hasTable('own_storage_state'))->toBeTrue();
        $migration->down();
    } finally {
        foreach (glob($scratch.'/migrations/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($scratch.'/migrations')) {
            rmdir($scratch.'/migrations');
        }

        if (is_dir($scratch)) {
            rmdir($scratch);
        }
    }
});

it('loads the default migration and publishes azguard-migrations', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $migration = require dirname(__DIR__, 3).'/packages/core/database/migrations/2026_10_01_000000_create_azguard_storage.php';

    try {
        $migration->up();
        expect($storage->state('admin'))->toBeNull();
        expect(ServiceProvider::pathsToPublish(AzGuardServiceProvider::class, 'azguard-migrations'))->toHaveCount(1);
        expect(app('migrator')->paths())->toContain(dirname(__DIR__, 3).'/packages/core/src/../database/migrations');
    } finally {
        $migration->down();
    }
});
