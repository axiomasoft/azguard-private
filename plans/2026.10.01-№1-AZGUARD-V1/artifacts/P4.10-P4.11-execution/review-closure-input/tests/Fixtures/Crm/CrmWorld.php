<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Crm\Guards\Backoffice\BackofficePanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\AnalystProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers\ClientScopeResolver;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\City;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

final class CrmWorld
{
    public static ?Closure $configure = null;

    public static ?Closure $backofficeConfigure = null;

    public static array $sources = [];

    public static function storage(): Storage
    {
        return app(StorageRegistry::class)->get('default');
    }

    public static function seed(): void
    {
        expect(config('database.connections.testbench.database'))->toBe(':memory:');
        self::storage()->connection()->statement('PRAGMA foreign_keys = ON');
        Relation::morphMap(['crm.user' => User::class, 'crm.organization' => Organization::class], false);
        Carbon::setTestNow('2026-10-06T12:00:00Z');
        (new CrmSchema)->up();
        app(StorageSchema::class)->create('default');
        Organization::query()->insert([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        City::query()->insert([['id' => 1, 'name' => 'Казань'], ['id' => 2, 'name' => 'Самара']]);
        User::query()->insert([
            ['id' => 1, 'name' => 'Анна', 'city_id' => 1, 'is_root' => false],
            ['id' => 2, 'name' => 'Борис', 'city_id' => 2, 'is_root' => false],
            ['id' => 3, 'name' => 'Дарья', 'city_id' => 1, 'is_root' => true],
            ['id' => 4, 'name' => 'outsider', 'city_id' => 1, 'is_root' => true],
        ]);
        Organization::query()->getConnection()->table('organization_user')->insert([
            ['organization_id' => 1, 'user_id' => 1], ['organization_id' => 2, 'user_id' => 1],
            ['organization_id' => 1, 'user_id' => 2], ['organization_id' => 1, 'user_id' => 3],
        ]);
        Project::query()->insert([
            ['id' => 1, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
            ['id' => 2, 'organization_id' => 1, 'city_id' => 2, 'region' => 'R1', 'is_active' => true],
            ['id' => 3, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R1', 'is_active' => false],
            ['id' => 4, 'organization_id' => 2, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
            ['id' => 5, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R2', 'is_active' => true],
        ]);
        Client::query()->insert([
            ['id' => 1, 'organization_id' => 1, 'project_id' => 1, 'do_not_call' => false, 'owner_user_id' => 1],
            ['id' => 2, 'organization_id' => 1, 'project_id' => 1, 'do_not_call' => true, 'owner_user_id' => 1],
            ['id' => 3, 'organization_id' => 1, 'project_id' => 2, 'do_not_call' => false, 'owner_user_id' => 1],
            ['id' => 4, 'organization_id' => 1, 'project_id' => 3, 'do_not_call' => false, 'owner_user_id' => 1],
            ['id' => 5, 'organization_id' => 2, 'project_id' => 4, 'do_not_call' => false, 'owner_user_id' => 1],
            ['id' => 6, 'organization_id' => 1, 'project_id' => 5, 'do_not_call' => false, 'owner_user_id' => 2],
        ]);
        self::assign('seller', 1, 1);
        self::assign('analyst', 1, 2);
        self::assign('analyst', 1, 4, 2);
        self::assign('seller', 2, 2);
        self::assign('tenant-admin', 3, 1);
        self::resetRuntime();
    }

    public static function resetRuntime(): void
    {
        self::$configure = null;
        self::$backofficeConfigure = null;
        self::$sources = [];
        ProjectScope::$failure = null;
        ClientScopeResolver::$throws = false;
        ClientPolicy::$override = false;
        ClientPolicy::$result = true;
        ActiveProjects::$observed = SellerProjects::$observed = AnalystProjects::$observed = [];
    }

    public static function database(): DatabaseSource
    {
        return DatabaseSource::make()->models(roleGrant: CrmRoleGrant::class, permissionGrant: CrmPermissionGrant::class)
            ->decisionFields(roleGrant: ['region', 'eligible'], permissionGrant: ['region', 'eligible']);
    }

    public static function compile(?Closure $configure = null, ?array $sources = null, ?Closure $backoffice = null): Panel
    {
        self::$configure = $configure;
        self::$backofficeConfigure = $backoffice;
        self::$sources = $sources ?? [self::database()];
        $registry = new PanelRegistry(app());
        $registry->register(CrmGuardPanelProvider::class);
        $registry->register(BackofficePanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry->get('crm');
    }

    public static function scope(int $tenant = 1, ?int $project = null): AccessScope
    {
        return AccessScope::in(TenantRef::of('crm.organization', $tenant), $project === null ? null : AssignmentScopeRef::of('crm.project', $project));
    }

    public static function decide(Panel $panel, int|Client $client = 1, ClientPermission $action = ClientPermission::View, int $user = 1, int $tenant = 1, ?ActorRef $actor = null): Decision
    {
        $resource = is_int($client) ? Client::query()->findOrFail($client) : $client;
        $request = AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of($panel->id(), $action->value))->inTenant(self::scope($tenant)->tenant)->on(null, $resource)->traced();

        return app(Authorizer::class)->decide($panel, $request, $actor);
    }

    public static function assertDecision(Decision $decision, bool $allow, DecisionReason $reason, ?string $component = null): void
    {
        expect($decision->allowed())->toBe($allow)->and($decision->reason)->toBe($reason);

        if ($component !== null) {
            expect($decision->component)->toBe($component);
        }
    }

    public static function assign(string $key, int $user, int $project, int $tenant = 1, string $kind = 'role', array $fields = [], string $panel = 'crm', string $origin = 'manual', ?AccessScope $scope = null): void
    {
        $scope ??= self::scope($tenant, $project);
        self::storage()->mutate($panel, static function (StorageMutation $mutation) use ($key, $user, $scope, $kind, $fields, $panel, $origin): void {
            $mutation->table($kind.'_grants')->insert([
                'panel' => $panel, 'tenant_key' => $scope->tenant->key(), 'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
                'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
                'subject_type' => 'crm.user', 'subject_id' => (string) $user, $kind => $key, 'origin' => $origin, 'meta' => json_encode($fields, JSON_THROW_ON_ERROR),
            ]);
            $mutation->touch($panel);
        });
    }

    public static function clear(int $user = 1): void
    {
        self::storage()->mutate('crm', static function (StorageMutation $mutation) use ($user): void {
            foreach (['role_grants', 'permission_grants'] as $table) {
                $mutation->table($table)->where('subject_id', (string) $user)->delete();
            }
            $mutation->touch('crm');
        });
    }
}
