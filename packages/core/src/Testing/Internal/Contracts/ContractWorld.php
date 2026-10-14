<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Testing\FakeSubject;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use ReflectionClass;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * @internal The stand the contract suites share: a fresh registry with the panel `contract` (and `other` when asked),
 * built the way the application builds its own, so a source, a plugin or a hook is checked against the real engine.
 */
final class ContractWorld
{
    public const string PANEL = 'contract';

    public const string OTHER = 'other';

    /**
     * Registers the panels in a fresh registry and makes it the registry of the application.
     *
     * @param  (Closure(PanelBuilder): mixed)|null  $contract
     * @param  (Closure(PanelBuilder): mixed)|null  $other  the second panel exists only when this is given
     * @param  (Closure(PanelBuilder): mixed)|null  $all  applied to every panel, as `AzGuard::configurePanels()` does
     */
    public static function build(?Closure $contract = null, ?Closure $other = null, ?Closure $all = null): PanelRegistry
    {
        ContractPanel::$configure = $contract;
        ContractOtherPanel::$configure = $other;
        $registry = new PanelRegistry(app());
        $registry->register(ContractPanel::class);

        if ($all !== null) {
            $registry->configureAll($all);
        }

        if ($other !== null) {
            $registry->register(ContractOtherPanel::class);
        }

        $previous = app(PanelRegistry::class);
        app()->instance(PanelRegistry::class, $registry);

        try {
            $registry->freeze();
        } catch (Throwable $error) {
            app()->instance(PanelRegistry::class, $previous);

            throw $error;
        } finally {
            self::reset();
        }
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    /** @param (Closure(PanelBuilder): mixed)|null $configure */
    public static function panel(?Closure $configure = null): Panel
    {
        return self::build($configure)->get(self::PANEL);
    }

    public static function reset(): void
    {
        ContractPanel::$configure = ContractOtherPanel::$configure = null;
    }

    public static function subject(int $id = 1): FakeSubject
    {
        return FakeSubject::of($id);
    }

    /** The decision of the real engine for the subject, in the tenant-wide scope of the panel. */
    public static function decide(Panel $panel, Model $subject, string|UnitEnum $permission): Decision
    {
        return AzGuard::panel($panel->id())->for($subject)->decide($permission);
    }

    /**
     * What the panel is, as values: two panels with the same snapshot are the same panel.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Panel $panel): array
    {
        $catalog = app(PanelRegistry::class)->catalog($panel->id());

        return [
            'settings' => $panel->settings()->toArray(),
            'plugins' => $panel->pluginIds(),
            'sources' => array_map(static fn (SourceDescription $source): string => $source->id.'|'.implode(',', $source->capabilities), $panel->sources()),
            'permissions' => array_keys($catalog->all()),
            'roles' => array_keys($catalog->roles()),
            'before' => count($panel->before()),
            'after' => count($panel->after()),
            'restrictions' => count($panel->restrictions()),
            'conditions' => count($panel->grantConditions()),
            'changing' => count($panel->changing()),
            'doctor' => count($panel->doctorChecks()),
            'fingerprint' => app(PanelRegistry::class)->fingerprint($panel->id()),
        ];
    }

    /**
     * The number of rows of every table in the current database or schema of every configured connection that can be
     * reached. A connection that cannot be reached is left out; a table it lists is always counted.
     *
     * @return array<string, int>
     */
    public static function rowCounts(): array
    {
        $counts = [];

        foreach (array_keys((array) config('database.connections')) as $name) {
            try {
                $connection = app('db')->connection((string) $name);
                $schema = $connection->getSchemaBuilder();

                // Only the current database (MySQL, MariaDB), the search path (PostgreSQL) or `main` (SQLite): without
                // it the list holds tables of other databases or attached files that this connection does not write.
                if ((new ReflectionClass($schema))->hasMethod('getCurrentSchemaListing')) {
                    $current = $schema->getCurrentSchemaListing();
                    $tables = $current === null ? $schema->getTables() : $schema->getTables($current);
                } else {
                    // Laravel 11 has neither the listing nor the schema argument. Its MySQL, MariaDB and SQLite lists
                    // are already the current database or `main`, but on PostgreSQL it lists the tables of every
                    // schema: the list is narrowed to the search path the server reports.
                    $current = $connection->getDriverName() === 'pgsql' ? array_values(array_filter(array_map(
                        static fn (mixed $row): mixed => is_object($row) ? ($row->name ?? null) : null,
                        $connection->select('select unnest(current_schemas(false)) as name'),
                    ), is_string(...))) : null;
                    $tables = array_filter($schema->getTables(), static fn (array $table): bool => $current === null || in_array($table['schema'] ?? null, $current, true));
                }
            } catch (Throwable) {
                continue;
            }
            foreach ($tables as $table) {
                $owner = is_string($table['schema'] ?? null) ? $table['schema'] : null;
                $key = $owner === null || $current === null || $owner === ($current[0] ?? null) ? $table['name'] : $owner.'.'.$table['name'];
                // The listing holds physical names: wrapping them with the connection prefix would prefix them twice.
                // (`withoutTablePrefix()` returns nothing on Laravel 11, so the prefix is swapped by hand.)
                $prefix = $connection->getTablePrefix();
                $connection->setTablePrefix('');

                try {
                    $row = $connection->selectOne(
                        'select count(*) as aggregate from '.$connection->getQueryGrammar()->wrap($owner === null ? $table['name'] : $owner.'.'.$table['name']),
                    );
                } finally {
                    $connection->setTablePrefix($prefix);
                }
                $count = is_object($row) ? ($row->aggregate ?? null) : null;
                $counts[$name.'.'.$key] = is_numeric($count) ? (int) $count : throw new RuntimeException('Table '.$table['name'].' of '.$name.' could not be counted.');
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * The statements that change data or schema which ran while the work did, on any connection.
     *
     * @param  Closure(): mixed  $work
     * @return list<string>
     */
    public static function writesDuring(Closure $work): array
    {
        $log = new WriteLog;
        app('events')->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($log): void {
            if ($log->active && WriteLog::isWrite($query->sql)) {
                $log->writes[] = $query->sql;
            }
        });

        try {
            $work();
        } finally {
            $log->active = false;
        }

        return $log->writes;
    }
}
