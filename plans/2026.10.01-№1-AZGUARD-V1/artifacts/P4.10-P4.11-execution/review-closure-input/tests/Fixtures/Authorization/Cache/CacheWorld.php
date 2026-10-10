<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization\Cache;

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePolicy;
use Closure;
use Illuminate\Database\Events\QueryExecuted;

final class CacheWorld
{
    /** @return array{Authorizer, Panel} */
    public static function database(Source $source, StateRefresh $refresh = StateRefresh::Request, ?Closure $configure = null, Reads $reads = Reads::Primary): array
    {
        [, , $registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use ($source, $refresh, $configure, $reads): void {
            $panel->for(User::class)->resourcePrefix(false)->permissions([DatabasePermission::class, $source])
                ->roles([GrantableRootRole::class])->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])
                ->cache('array')->consistency($reads, $refresh);
            $configure?->__invoke($panel);
        }]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return [app(Authorizer::class), $registry->get('admin')];
    }

    /** @return array{state: int, grants: int, sequence: list<string>} */
    public static function emptyBudget(): array
    {
        return ['state' => 0, 'grants' => 0, 'sequence' => []];
    }

    /** @param array{state: int, grants: int, sequence: list<string>} $budget */
    public static function listen(array &$budget): void
    {
        app('db')->connection()->listen(static function (QueryExecuted $query) use (&$budget): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            foreach (['panel_state', 'permission_grants', 'role_grants'] as $table) {
                if (str_contains($query->sql, 'azg_'.$table.'"')) {
                    $budget[$table === 'panel_state' ? 'state' : 'grants']++;
                    $budget['sequence'][] = $table;
                }
            }
        });
    }
}
