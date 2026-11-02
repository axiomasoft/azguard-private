<?php

declare(strict_types=1);

use AzGuard\Storage\Internal\Storage;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

// This file is an opt-in fixture. Refuse unsafe environments before TestCase boots.
if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:' || getenv('AUTHORITY_REPLICA_TEST')) {
    throw new RuntimeException('Historical fixture requires DB_CONNECTION=sqlite, DB_DATABASE=:memory: and no replica.');
}

uses(TestCase::class);

it('reproduces the pinned historical schema incompatibility on disposable SQLite', function (): void {
    $checkout = getenv('AZGUARD_HISTORICAL_CHECKOUT');
    if (! is_string($checkout) || $checkout === '' || ! is_dir($checkout)) {
        throw new RuntimeException('UNAVAILABLE: set AZGUARD_HISTORICAL_CHECKOUT to the pinned historical checkout.');
    }
    $git = static function (array $args) use ($checkout): string {
        $process = new Process(['git', '-C', $checkout, ...$args]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    expect($git(['rev-parse', 'HEAD']))->toBe('fb89b1b3b7c778f9156ceccb877b4896fff3d1e8')
        ->and($git(['rev-parse', 'HEAD:packages/core']))->toBe('8c3f9d1963c97d3d9ebf98e8a2a6f726c17b4ffd')
        ->and($git(['status', '--porcelain', '--untracked-files=all', '--', 'packages/core']))->toBe('');
    $dir = sys_get_temp_dir().'/azguard-historical-'.bin2hex(random_bytes(16));
    expect(mkdir($dir, 0700))->toBeTrue();
    $file = $dir.'/historical_test.sqlite';
    $database = app('db');
    $previous = $database->getDefaultConnection();
    try {
        $process = new Process(['/usr/bin/env', '-i', 'PATH=/usr/bin:/bin', PHP_BINARY,
            __DIR__.'/historical-seed.php', $checkout, $file], timeout: 30);
        $process->mustRun();
        config(['database.connections.historical_test' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
        $database->setDefaultConnection('historical_test');
        app()->forgetInstance(StorageRegistry::class);
        $db = $database->connection('historical_test');
        expect($db->getDriverName())->toBe('sqlite')->and($db->getDatabaseName())->toBe($file)
            ->and($db->getSchemaBuilder()->hasColumn('azg_panel_state', 'epoch'))->toBeFalse()
            ->and($db->getSchemaBuilder()->hasTable('azg_subject_revisions'))->toBeFalse()
            ->and($db->table('azg_role_grants')->count())->toBe(1);
        $path = dirname(__DIR__, 3).'/packages/core/database/migrations';
        expect(Artisan::call('migrate', ['--path' => [$path], '--realpath' => true]))->toBe(0)
            ->and($db->getSchemaBuilder()->hasColumn('azg_panel_state', 'epoch'))->toBeFalse()
            ->and($db->getSchemaBuilder()->hasTable('azg_subject_revisions'))->toBeFalse()
            ->and($db->table('azg_role_grants')->count())->toBe(1);
        $storage = new Storage('default', $db);
        expect(fn () => $storage->state('fixture'))->toThrow(\AzGuard\Exceptions\StorageMismatchException::class);
        $migration = require $path.'/2026_10_01_000000_create_azguard_storage.php';
        expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class, 'already exists')
            ->and($db->table('azg_role_grants')->count())->toBe(1);
    } finally {
        $database->purge('historical_test');
        $database->setDefaultConnection($previous);
        app()->forgetInstance(StorageRegistry::class);
        @unlink($file);
        @rmdir($dir);
    }
});
