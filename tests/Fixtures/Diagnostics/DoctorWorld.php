<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Authorization\Authorizer;
use AzGuard\Catalog\CatalogCache;
use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\NotesGuardPanelProvider;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Compiles fixture panels into the registry of the application and runs the doctor over them.
 */
final class DoctorWorld
{
    /**
     * The doctor redacts every database password wherever it occurs; the password of the server test databases is
     * `azguard`, which would also be cut out of `azguard.storages…` or `azguard.can:…` in a message. The connection
     * is opened first, then the password leaves the configuration the doctor reads.
     */
    public static function withoutPasswordInMessages(): void
    {
        DB::connection()->getPdo();
        config(['database.connections.testbench.password' => null, 'database.connections.secondary.password' => null]);
    }

    /**
     * @param  array<class-string<FixturePanel>, Closure(PanelBuilder): mixed>  $panels
     */
    public static function panels(array $panels): PanelRegistry
    {
        [, , $registry] = PanelWorld::compile($panels);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    /**
     * The notes folder panel in a registry that reads and writes the catalog cache, as the application does.
     *
     * @param  (Closure(PanelBuilder): mixed)|null  $configure
     */
    public static function notes(?Closure $configure = null): PanelRegistry
    {
        NotesGuardPanelProvider::$configure = $configure;
        $registry = new PanelRegistry(app(), new PanelCompiler, static fn (): CatalogCache => app(CatalogCache::class));
        $registry->register(NotesGuardPanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    /** Creates the schema of the storage once; a later call keeps the tables as they are. */
    public static function migrate(string $storage = 'default'): void
    {
        $registered = app(StorageRegistry::class)->get($storage);

        if (! $registered->connection()->getSchemaBuilder()->hasTable($registered->prefix().'storage_state')) {
            app(StorageSchema::class)->create($storage);
        }
    }

    /**
     * @param  list<string>  $panels
     * @param  list<string>  $storages
     * @return list<DoctorFinding>
     */
    public static function run(array $panels = [], array $storages = [], bool $production = false): array
    {
        $doctor = app(Doctor::class);

        return $doctor->run($doctor->context($panels, $storages, $production));
    }

    /**
     * @param  list<DoctorFinding>  $findings
     * @return list<string> `scope key severity` of each finding
     */
    public static function summary(array $findings): array
    {
        return array_map(static fn (DoctorFinding $finding): string => $finding->scope.' '.$finding->key.' '.$finding->severity->value, $findings);
    }

    /**
     * @param  list<DoctorFinding>  $findings
     * @return list<DoctorFinding>
     */
    public static function only(array $findings, string $key): array
    {
        return array_values(array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->key === $key));
    }
}
