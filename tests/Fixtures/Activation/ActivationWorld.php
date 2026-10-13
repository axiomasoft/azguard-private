<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Activation;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Changes\ChangeResult;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\OrganizationMembership;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Closure;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;

/**
 * Two panels, `alpha` and `beta`, over one storage and the CRM stand's data: the test says which plugins each carries, so a
 * module is active on one panel and absent from the other (PLAN1 P3.12, the activation matrix of p3-execution#activation).
 */
final class ActivationWorld
{
    /** @var array<string, list<object>> the plugins of each panel, by panel id */
    public static array $plugins = [];

    /** The panels carry the analyst role, bound to the project scope; without the Scopes plugin they declare the mode None for it. */
    public static bool $scopedRoles = false;

    /**
     * @param  list<object>  $alpha  plugins of the panel `alpha`
     * @param  list<object>  $beta  plugins of the panel `beta`
     */
    public static function compile(array $alpha = [], array $beta = [], bool $scopedRoles = false): PanelRegistry
    {
        self::$plugins = ['alpha' => $alpha, 'beta' => $beta];
        self::$scopedRoles = $scopedRoles;
        $registry = new PanelRegistry(app());
        $registry->register(AlphaActivationPanel::class);
        $registry->register(BetaActivationPanel::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    public static function reset(): void
    {
        self::$plugins = [];
        self::$scopedRoles = false;
    }

    /** The description both panels share: the CRM stand's tenants, users and database writer, with the plugins the test gave. */
    public static function describe(PanelBuilder $panel, string $id): PanelBuilder
    {
        $plugins = self::$plugins[$id] ?? [];
        $panel->for(model: User::class, guard: 'web')->permissions([CrmWorld::database(), ClientPermission::class])
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new OrganizationMembership))
            ->roles([RootRole::class, AuditorRole::class, SupportRole::class, ...(self::$scopedRoles ? [AnalystRole::class] : [])])
            ->policies([ClientPolicy::class])
            ->plugins($plugins);

        if (self::$scopedRoles && array_filter($plugins, static fn (object $plugin): bool => $plugin instanceof ScopesPlugin) === []) {
            $panel->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, [ProjectScope::make()]));
        }

        return $panel;
    }

    /** Grants a role of the panel through the real change pipeline (ChangeWorld names the role of panel `crm` only). */
    public static function grant(Panel $panel, string $role, int $user, ?int $project, ?DateTimeImmutable $until = null): ChangeResult
    {
        return W::pipeline()->grant($panel, W::tenant(), W::user($user), RoleKey::of($panel->id(), $role), W::project($project), 'manual', $until, [], null);
    }

    public static function revoke(Panel $panel, string $role, int $user, ?int $project): ChangeResult
    {
        return W::pipeline()->revoke($panel, W::tenant(), W::user($user), RoleKey::of($panel->id(), $role), W::project($project), 'manual');
    }

    /** Whether the subject may view the clients of the panel in the tenant (optionally in the scope of a project). */
    public static function decide(Panel $panel, int $user, ?AccessScope $scope = null, string $permission = 'clients.view_any'): Decision
    {
        $request = AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of($panel->id(), $permission))
            ->inScope($scope ?? AccessScope::in(TenantRef::of('crm.organization', 1)));

        return app(Authorizer::class)->decide($panel, $request);
    }

    /**
     * Runs the work and reports what an inactive module would cost during it: the module-owned services the container
     * resolved or already held, and the module tables a query touched (the same measure as the I13 harness).
     *
     * @return list<string> "module-service <class>" / "module-query <table>", sorted
     */
    public static function costsOf(Closure $work): array
    {
        $ownership = Ownership::fromOracle();
        $resolved = [];
        $queries = [];
        app()->resolving(static function (mixed $object) use (&$resolved): void {
            if (is_object($object)) {
                $resolved[$object::class] = true;
            }
        });
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $work();
        $costs = [];

        // The console application instantiates every registered command; those are a cost of the console, not of a panel.
        $isCommand = static fn (string $type): bool => str_contains($type, '\\Internal\\Console\\');

        foreach (array_keys($resolved) as $type) {
            if (! $isCommand($type) && in_array($ownership->referenceOwner('reference', $type), Ownership::MODULES, true)) {
                $costs[] = 'module-service '.$type;
            }
        }

        foreach ((new ReflectionProperty(Container::class, 'instances'))->getValue(app()) as $instance) {
            if (is_object($instance) && ! $isCommand($instance::class) && in_array($ownership->referenceOwner('reference', $instance::class), Ownership::MODULES, true)) {
                $costs[] = 'module-service '.$instance::class;
            }
        }

        foreach (array_keys(Ownership::TABLES) as $table) {
            if (array_filter($queries, static fn (string $sql): bool => str_contains($sql, $table)) !== []) {
                $costs[] = 'module-query '.$table;
            }
        }
        $costs = array_values(array_unique($costs));
        sort($costs);

        return $costs;
    }
}
