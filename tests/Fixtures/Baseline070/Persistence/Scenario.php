<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence;

use AzGuard\AzGuardServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The migration files of an installed 0.7.0 application per scenario, in a scratch database path: none besides the
 * package-loaded core migrations (`default`, `schema1`), the published copies (`published`) or the generated
 * named-storage migration (`named`). The writer migrates with them; the reader proves none of them is pending.
 */
final class Scenario
{
    public const string CREATE = '2026_10_01_000000_create_azguard_storage';

    public const string UPGRADE = '2026_10_09_000000_upgrade_azguard_storage_to_schema_2';

    /** The basename the generated named-storage migration gets: the generator dates it from the wall clock. */
    public const string NAMED_MIGRATION = '2026_10_10_000000_create_azguard_ledger_storage';

    /** Points the application database path at `$scratch` and writes the application migrations of the scenario. */
    public static function prepare(string $scenario, string $scratch): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($scratch.'/migrations');
        app()->useDatabasePath($scratch);

        if ($scenario === 'published') {
            self::publish($scratch.'/migrations');
        }

        if ($scenario === 'named') {
            self::artisan('azguard:storage:migration', ['name' => Restorer::NAMED['id']]);
            $generated = $files->glob($scratch.'/migrations/*_create_azguard_'.Restorer::NAMED['id'].'_storage.php');

            if (count($generated) !== 1) {
                throw new RuntimeException('azguard:storage:migration generated no migration.');
            }
            $files->move($generated[0], $scratch.'/migrations/'.self::NAMED_MIGRATION.'.php');
        }
    }

    /** @return list<string> basenames of the application and package-loaded migrations that have not run */
    public static function pending(string $scratch): array
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([$scratch.'/migrations', ...$migrator->paths()]);

        return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    /** @return list<string> basenames of the application migration files of the scenario */
    public static function applicationMigrations(string $scratch): array
    {
        return array_keys(app('migrator')->getMigrationFiles([$scratch.'/migrations']));
    }

    /**
     * `vendor:publish --tag=azguard-migrations` as the application runs it. The provider recorded the target
     * `database_path('migrations')` at boot; only that target is redirected to the scratch path for the call (in the
     * provider and the tag lists of `ServiceProvider`), so the shared test application directory is never written.
     */
    private static function publish(string $target): void
    {
        $tag = 'azguard-migrations';
        $access = (static function (?array $restore = null) use ($tag): array {
            $recorded = [static::$publishes[AzGuardServiceProvider::class], static::$publishGroups[$tag]];

            if ($restore !== null) {
                [static::$publishes[AzGuardServiceProvider::class], static::$publishGroups[$tag]] = $restore;
            }

            return $recorded;
        })->bindTo(null, ServiceProvider::class);
        $recorded = $access();
        $redirect = static function (array $paths) use ($target): array {
            foreach ($paths as $from => $to) {
                $paths[$from] = str_ends_with($from, '/database/migrations') ? $target : $to;
            }

            return $paths;
        };

        try {
            $access(array_map($redirect, $recorded));
            self::artisan('vendor:publish', ['--tag' => $tag]);
        } finally {
            $access($recorded);
        }
    }

    /** @param array<string, mixed> $parameters */
    private static function artisan(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException($command.' failed: '.Artisan::output());
        }
    }
}
