<?php

declare(strict_types=1);

namespace AzGuard\Tests\Engines\Support;

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePolicy;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Closure;
use Illuminate\Database\Events\QueryExecuted;

final class BatchEngineWorld
{
    public static function compile(?Closure $after = null, bool $cabinet = false): Authorizer
    {
        $description = static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)->resourcePrefix(false)
            ->permissions([DatabasePermission::class, DatabaseSource::make()])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])
            ->scopes(AssignmentScopePolicy::inherit(new StoreScope(
                resolveUsing: static fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, TenantRef::global()),
            )))->after($after ?? []);
        $panels = [AdminPanel::class => $description];

        if ($cabinet) {
            $panels[CabinetPanel::class] = $description;
        }
        [, , $registry] = PanelWorld::compile($panels);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return app(Authorizer::class);
    }

    /** @return list<AccessRequest> */
    public static function requests(int $contexts = 101): array
    {
        return array_map(
            static fn (int $id): AccessRequest => DatabaseWorld::request()->on(AssignmentScopeRef::of('store', $id)),
            range(1, $contexts),
        );
    }

    public static function cabinetRequest(): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('cabinet', DatabasePermission::View->value));
    }

    /**
     * @param  array<string, list<array{table: string, contexts: list<string>}>>  $reads
     * @param  Closure(string, string, int): void|null  $barrier
     */
    public static function listen(array &$reads, ?Closure $barrier = null): void
    {
        DatabaseWorld::storage()->connection()->listen(static function (QueryExecuted $query) use (&$reads, $barrier): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            foreach (['panel_state', 'permission_grants', 'role_grants'] as $table) {
                if (! preg_match('/azg_'.$table.'["`]/', $query->sql)) {
                    continue;
                }
                // The observed-state read joins the subject's revision first; its panel is the last binding.
                $panel = $table === 'panel_state' ? $query->bindings[array_key_last($query->bindings)] : $query->bindings[0];
                // The first three bindings identify panel/subject; each scope then
                // contributes a tenant/context pair. A global tenant is not a context.
                $contexts = $table === 'panel_state' ? [] : array_column(array_chunk(array_slice($query->bindings, 3), 2), 1);
                $reads[$panel][] = ['table' => $table, 'contexts' => $contexts];
                $number = count(array_filter($reads[$panel], static fn (array $read): bool => $read['table'] === $table));
                $barrier?->__invoke($panel, $table, $number);

                break;
            }
        });
    }
}
