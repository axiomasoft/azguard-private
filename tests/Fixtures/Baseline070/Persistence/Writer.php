<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence;

use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Baseline070\Formats\FormatSnapshot;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Generates the dump of a scenario, only in writer mode `AZGUARD_BASELINE_WRITE=1` on the current tree (I16, D16). The
 * storage is prepared the way an installed 0.7.0 application prepared it, through its migrations and commands; the
 * dataset is written through the public API; then every AzGuard table and the AzGuard rows of `migrations` are dumped.
 */
final class Writer
{
    public static function write(string $scenario, string $scratch): void
    {
        $connection = app('db')->connection();
        $storages = $scenario === 'named' ? ['default', Restorer::NAMED['id']] : ['default'];
        $seeded = $scenario === 'named' ? Restorer::NAMED['id'] : 'default';
        Restorer::configure($storages);
        Scenario::prepare($scenario, $scratch);
        self::artisan('migrate', []);
        $prefix = app(StorageRegistry::class)->get($seeded)->prefix();
        Dataset::addApplicationColumns($connection, $prefix);
        Dataset::seed($seeded);

        if ($scenario === 'schema1') {
            self::toSchema1($connection);
            self::artisan('migrate', []);
        }

        $dump = ['header' => [
            'baseline' => '0.7.0',
            'commit' => Restorer::BASELINE,
            'driver' => $connection->getDriverName(),
            'scenario' => $scenario,
            'generator' => 'AZGUARD_BASELINE_WRITE=1 vendor/bin/pest tests/Regression/Baseline070/PopulatedDatabaseTest.php',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'normalisation' => 'Restorer::rows(): JSON columns decoded with sorted object keys; other values as strings; '
                .'panel_state.updated_at (written from the wall clock) replaced by Dataset::CLOCK. Generated ULIDs are fixed by Dataset::fixUlids().',
        ], 'storages' => [], 'migrations' => Restorer::migrations($connection)];

        foreach ($storages as $id) {
            $storage = app(StorageRegistry::class)->get($id);
            $entry = ['prefix' => $storage->prefix(), 'host_keys' => $storage->hostKeys(),
                'application_columns' => $id === $seeded ? ['role_grants' => [Dataset::APPLICATION_COLUMN]] : [], 'ddl' => [], 'tables' => []];

            foreach (Legacy070Schema::TABLES as $base) {
                $entry['ddl'][$base] = Restorer::ddl($storage, $base);
                $entry['tables'][$base] = Restorer::rows($connection, $storage->prefix(), $base, wallClock: true);
            }
            $dump['storages'][$id] = $entry;
        }
        $path = Restorer::path($dump['header']['driver'], $scenario);
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, FormatSnapshot::encode($dump));
    }

    /**
     * Turns the populated default storage into the shape schema 1 left (no `subject_revisions`, no `panel_state.epoch`,
     * `storage_state` version 1) with only the create migration run, as `SubjectRevisionsTest` reconstructs it.
     */
    private static function toSchema1(Connection $connection): void
    {
        $storage = app(StorageRegistry::class)->get('default');
        $schema = $connection->getSchemaBuilder();
        $schema->drop($storage->prefix().'subject_revisions');
        $schema->table($storage->prefix().'panel_state', static fn (Blueprint $table) => $table->dropColumn('epoch'));
        $storage->table('storage_state')->update(['schema' => json_encode([...$storage->schema(), 'version' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        $connection->table('migrations')->where('migration', Scenario::UPGRADE)->delete();
        app()->forgetInstance(StorageRegistry::class);
    }

    /** @param array<string, mixed> $parameters */
    private static function artisan(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException($command.' failed: '.Artisan::output());
        }
    }
}
