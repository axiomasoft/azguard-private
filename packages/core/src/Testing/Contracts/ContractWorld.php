<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Authorization\Authorizer;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Testing\FakeSubject;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
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

        try {
            $registry->freeze();
        } finally {
            self::reset();
        }
        app()->instance(PanelRegistry::class, $registry);
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
            'sources' => array_map(static fn ($source): string => $source->id.'|'.implode(',', $source->capabilities), $panel->sources()),
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
     * The number of rows of every table of every configured connection that can be reached.
     *
     * @return array<string, int>
     */
    public static function rowCounts(): array
    {
        $counts = [];

        foreach (array_keys((array) config('database.connections')) as $name) {
            try {
                $connection = app('db')->connection((string) $name);

                foreach ($connection->getSchemaBuilder()->getTables() as $table) {
                    $counts[$name.'.'.$table['name']] = $connection->table((string) $table['name'])->count();
                }
            } catch (Throwable) {
                continue;
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
            if ($log->active && preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql) === 1) {
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
