<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\StateRefresh;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\ModelTenantDefinition;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseRoleGrant;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Relation\EditorRole;
use AzGuard\Tests\Fixtures\Sources\Relation\Member;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationPermission;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use AzGuard\Tests\Fixtures\Sources\Relation\Store;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;

/** The identical scalar acceptance matrix runs on SQLite and each supported SQL engine. */
final class ScopedSourceWorld
{
    public const array CASES = [
        'database role global exact outside',
        'independent tenant context role and direct assignments',
        'GrantedToAll and equivalent database role',
        'renamed removed automatic-only and FQCN roles',
        'decision fields and cold warm read budgets',
        'dynamic tenant catalogue and action existence',
        'dynamic scoped fence discards stale grants',
        'relation project membership and store owner',
        'mixed folder database relation and fail closed source',
    ];

    public static function seed(): void
    {
        app(StorageSchema::class)->drop('default');
        app(StorageSchema::class)->create('default');
        DatabaseWorld::seedSubject();
        app()->instance(StoreScope::class, new StoreScope(static function (AssignmentScopeRef $ref): ?ResolvedAssignmentScope {
            if ($ref->type() !== 'store' || ! in_array($ref->id(), ['1', '2', '3', '4'], true)) {
                return null;
            }

            return new ResolvedAssignmentScope($ref, TenantRef::of('org', in_array($ref->id(), ['1', '3'], true) ? 'A' : 'B'));
        }));
    }

    public static function reset(): void
    {
        ConcurrentWriter::close();
        app(StorageSchema::class)->drop('default');
        DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
        foreach (['relation_project_user', 'relation_memberships', 'relation_projects', 'relation_stores', 'relation_teams', 'relation_members', 'relation_vendors'] as $table) {
            DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists($table);
        }
        RelationWorld::reset();
    }

    /** @param list<class-string<BaseRole>> $roles
     * @param  list<GrantCondition>  $conditions
     */
    public static function database(?DatabaseSource $source = null, array $roles = [OrderRole::class], bool $tenant = true, bool $everyone = false, ?Closure $after = null, array $conditions = []): Panel
    {
        $source ??= DatabaseSource::make();
        [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)->resourcePrefix(false)->permissions([$everyone ? EveryonePermission::class : OrderPermission::class, $source])
            ->roles($roles)->scopes(AssignmentScopePolicy::inherit(StoreScope::class))
            ->tenants($tenant ? TenantPolicy::required(Organization::class)->requireMembership(new Membership) : TenantPolicy::none())
            ->consistency(refresh: StateRefresh::Check)->after($after ?? [])->grantConditions($conditions)]);

        return self::install($registry);
    }

    private static function install(PanelRegistry $registry): Panel
    {
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return $registry->get('admin');
    }

    public static function request(string $permission = 'orders.view', ?AccessScope $scope = null, int $subject = 1): AccessRequest
    {
        $request = AccessRequest::for(SubjectRef::of('user', $subject), PermissionKey::of('admin', $permission));

        return $scope === null ? $request : $request->inScope($scope);
    }

    public static function run(string $scenario): void
    {
        match ($scenario) {
            'database role global exact outside' => self::roleMatrix(),
            'independent tenant context role and direct assignments' => self::pairs(),
            'GrantedToAll and equivalent database role' => self::everyone(),
            'renamed removed automatic-only and FQCN roles' => self::staleRoles(),
            'decision fields and cold warm read budgets' => self::fieldsAndReads(),
            'dynamic tenant catalogue and action existence' => self::dynamic(),
            'dynamic scoped fence discards stale grants' => self::fence(),
            'relation project membership and store owner' => self::relations(),
            'mixed folder database relation and fail closed source' => self::mixed(),
        };
    }

    public static function roleMatrix(): void
    {
        DatabaseWorld::insert('role', [DatabaseWorld::row(overrides: ['role' => 'orders'])]);
        $panel = self::database(tenant: false);
        foreach (['orders.view', 'orders.export', 'other.view'] as $permission) {
            expect(app(Authorizer::class)->decide($panel, self::request($permission))->allowed())->toBe($permission !== 'other.view');
        }

        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->delete();
            $mutation->touch('admin');
        });
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope(context: 1), overrides: ['role' => 'orders'])]);
        $panel = self::database();
        foreach ([['A', 1, true], ['A', 3, false], ['A', null, false], ['B', 2, false]] as [$tenant, $context, $allowed]) {
            foreach (['orders.view', 'orders.export'] as $permission) {
                $scope = ScopeWorld::scope($tenant, $context);
                $decision = app(Authorizer::class)->decide($panel, self::request($permission, $scope));
                expect($decision->allowed())->toBe($allowed)->and($decision->scope->equals($scope))->toBeTrue();
            }
        }
    }

    private static function pairs(): void
    {
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope(context: 1), overrides: ['role' => 'orders'])]);
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', ScopeWorld::scope('B', 2), ['permission' => 'orders.view'])]);
        $panel = self::database();
        foreach ([['A', 1, true, true], ['B', 2, true, false], ['A', 3, false, false], ['B', 4, false, false], ['A', 2, false, false], ['B', 1, false, false], ['A', null, false, false], ['B', null, false, false]] as [$tenant, $context, $view, $export]) {
            foreach (['orders.view' => $view, 'orders.export' => $export] as $permission => $allowed) {
                expect(app(Authorizer::class)->decide($panel, self::request($permission, ScopeWorld::scope($tenant, $context)))->allowed())->toBe($allowed);
            }
        }

        // The same immutable code catalogue applies in B; only B's assignment changes.
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope('B', 2), overrides: ['role' => 'orders'])]);
        expect(app(Authorizer::class)->decide($panel, self::request('orders.export', ScopeWorld::scope('B', 2)))->allowed())->toBeTrue();
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->where('tenant_id', 'A')->delete();
            $mutation->touch('admin');
        });
        expect(app(Authorizer::class)->decide($panel, self::request(scope: ScopeWorld::scope(context: 1)))->allowed())->toBeFalse()
            ->and(app(Authorizer::class)->decide($panel, self::request('orders.export', ScopeWorld::scope('B', 2)))->allowed())->toBeTrue();
    }

    private static function everyone(): void
    {
        $scopes = [ScopeWorld::scope(), ScopeWorld::scope(context: 1), ScopeWorld::scope('B'), ScopeWorld::scope('B', 2)];
        $panel = self::database(roles: [], everyone: true);
        $folder = [];
        foreach ($scopes as $scope) {
            foreach (['orders.view', 'orders.export'] as $permission) {
                $decision = app(Authorizer::class)->decide($panel, self::request($permission, $scope));
                $folder[] = $decision->allowed();
                expect($decision->allowed())->toBe($permission === 'orders.view');
            }
        }
        foreach ([ScopeWorld::scope(), ScopeWorld::scope('B')] as $scope) {
            DatabaseWorld::insert('role', [DatabaseWorld::row(scope: $scope, overrides: ['role' => 'orders'])]);
        }
        $panel = self::database(roles: [ViewRole::class]);
        $database = [];
        foreach ($scopes as $scope) {
            foreach (['orders.view', 'orders.export'] as $permission) {
                $database[] = app(Authorizer::class)->decide($panel, self::request($permission, $scope))->allowed();
            }
        }
        expect($database)->toBe($folder)->toBe([true, false, true, false, true, false, true, false]);
        foreach ([false, true] as $everyone) {
            $panel = self::database(roles: $everyone ? [] : [ViewRole::class], everyone: $everyone);
            expect(app(Authorizer::class)->decide($panel, self::request(scope: $scopes[0], subject: 99))->allowed())->toBeFalse()
                ->and(app(Authorizer::class)->decide($panel, self::request(scope: ScopeWorld::scope('A', 2)))->reason)->toBe(DecisionReason::AssignmentScopeMismatch);
        }
    }

    private static function staleRoles(): void
    {
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope(context: 1), overrides: ['role' => 'orders'])]);
        foreach ([[], [RenamedOrderRole::class], [AutomaticOnlyRole::class]] as $roles) {
            $panel = self::database(roles: $roles);
            expect(app(Authorizer::class)->decide($panel, self::request(scope: ScopeWorld::scope(context: 1)))->reason)->toBe(DecisionReason::NotGranted);
        }
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->update(['role' => 'renamed']);
            $mutation->touch('admin');
        });
        $panel = self::database(roles: [RenamedOrderRole::class]);
        expect(app(Authorizer::class)->decide($panel, self::request(scope: ScopeWorld::scope(context: 1)))->allowed())->toBeTrue();
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->update(['role' => OrderRole::class]);
            $mutation->touch('admin');
        });
        $panel = self::database();
        expect(app(Authorizer::class)->decide($panel, self::request(scope: ScopeWorld::scope(context: 1)))->allowed())->toBeFalse();
    }

    private static function fieldsAndReads(): void
    {
        $connection = DatabaseWorld::storage()->connection();
        $connection->getSchemaBuilder()->table('azg_role_grants', static function (Blueprint $table): void {
            $table->integer('score')->nullable();
        });
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope(context: 1), overrides: [
            'role' => 'orders', 'score' => 7, 'meta' => '{"weekdays":[1,5],"private_note":"secret"}',
        ])]);
        $condition = new class implements GrantCondition
        {
            public array $observed = [];

            public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
            {
                $this->observed[] = $grant->fields();

                return $grant->fields()['score'] === 7;
            }
        };
        $panel = self::database(DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class)->decisionFields(roleGrant: ['score', 'weekdays']),
            conditions: [$condition]);
        $reads = [];
        $connection->listen(static function (QueryExecuted $event) use (&$reads): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && str_contains($event->sql, '_grants')) {
                $reads[] = $event->sql;
            }
        });
        $request = self::request(scope: ScopeWorld::scope(context: 1));
        expect(app(Authorizer::class)->decide($panel, $request)->allowed())->toBeTrue()->and($reads)->toHaveCount(2)
            ->and($condition->observed)->toBe([['score' => 7, 'weekdays' => [1, 5]]]);
        $reads = [];
        expect(app(Authorizer::class)->decide($panel, $request)->allowed())->toBeTrue()->and($reads)->toBe([]);
        expect($condition->observed)->toBe([
            ['score' => 7, 'weekdays' => [1, 5]], ['score' => 7, 'weekdays' => [1, 5]],
        ]);
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->update(['score' => 3]);
            $mutation->touch('admin');
        });
        expect(app(Authorizer::class)->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)
            ->and($condition->observed[2])->toBe(['score' => 3, 'weekdays' => [1, 5]]);
        $reads = [];
        DatabaseWorld::storage()->mutate('admin', static fn (StorageMutation $mutation) => $mutation->touch('admin'));
        $panel = self::database(DatabaseSource::make()->rolesOnly());
        expect(app(Authorizer::class)->decide($panel, $request)->allowed())->toBeTrue()->and($reads)->toHaveCount(1)
            ->and(str_contains($reads[0], 'role_grants'))->toBeTrue();
    }

    private static function dynamic(): void
    {
        DatabaseWorld::define('reports.export', ScopeWorld::scope()->tenant);
        DatabaseWorld::define('reports.other', ScopeWorld::scope('B')->tenant);
        DatabaseWorld::define('orders.refund', ScopeWorld::scope()->tenant);
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: ScopeWorld::scope(context: 1), overrides: ['role' => 'orders'])]);
        DatabaseWorld::insert('permission', [
            DatabaseWorld::row('permission', ScopeWorld::scope(context: 1), ['permission' => 'reports.*']),
            DatabaseWorld::row('permission', ScopeWorld::scope('B', 2), ['permission' => 'reports.*']),
        ]);
        $panel = self::database(DatabaseSource::make()->dynamicPermissions());
        expect(app(Authorizer::class)->decide($panel, self::request('orders.refund', ScopeWorld::scope(context: 1)))->allowed())->toBeTrue()
            ->and(app(Authorizer::class)->decide($panel, self::request('reports.export', ScopeWorld::scope(context: 1)))->allowed())->toBeTrue()
            ->and(app(Authorizer::class)->decide($panel, self::request('reports.other', ScopeWorld::scope('B', 2)))->allowed())->toBeTrue()
            ->and(app(Authorizer::class)->decide($panel, self::request('reports.export', ScopeWorld::scope()))->reason)->toBe(DecisionReason::NotGranted);
        foreach ([['reports.export', ScopeWorld::scope('B', 2)], ['reports.other', ScopeWorld::scope(context: 1)], ['reports.unknown', ScopeWorld::scope(context: 1)]] as [$permission, $scope]) {
            expect(fn () => app(Authorizer::class)->decide($panel, self::request($permission, $scope)))->toThrow(UnknownPermissionException::class);
        }
    }

    private static function fence(): void
    {
        $scope = ScopeWorld::scope(context: 1);
        DatabaseWorld::define('reports.export', $scope->tenant, 'Before');
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', $scope, ['permission' => 'reports.export'])]);
        $observed = null;
        $panel = self::database(DatabaseSource::make()->dynamicPermissions(), after: static function (EvaluationContext $context) use (&$observed): void {
            $observed = $context;
        });
        ConcurrentWriter::open();
        $version = DatabaseWorld::storage()->state('admin')->version;
        $changed = false;
        $reads = 0;
        DatabaseWorld::storage()->connection()->listen(static function (QueryExecuted $event) use (&$changed, &$reads): void {
            if (! str_starts_with(strtolower(ltrim($event->sql)), 'select') || ! str_contains($event->sql, '_grants')) {
                return;
            }
            $reads++;

            if (! $changed && str_contains($event->sql, 'role_grants')) {
                $changed = true;
                ConcurrentWriter::commit(static function (Connection $connection): void {
                    $connection->table('azg_permission_grants')->delete();
                    $connection->table('azg_permissions')->update(['label' => 'After']);
                    ConcurrentWriter::touch($connection);
                });
            }
        });
        $decision = app(Authorizer::class)->decide($panel, self::request('reports.export', $scope));
        // Another connection revokes during the assignment read: the snapshot keeps the state the read started at.
        expect($decision->reason)->toBe(DecisionReason::Granted)->and($changed)->toBeTrue()->and($reads)->toBe(2)
            ->and($observed->readAttempt->catalog()->get('reports.export')->label)->toBe('Before')
            ->and($decision->state)->toBeInstanceOf(StateToken::class)->and($decision->state->version)->toBe($version);
    }

    private static function relations(): void
    {
        RelationWorld::seed();
        $project = new ProjectDefinition;
        $store = new ProjectDefinition(alias: 'store', recordClass: Store::class);
        EditorRole::$definitions = [$project];
        RelationWorld::attach(RelationWorld::project(7, 'A'));
        RelationWorld::project(8, 'A');
        RelationWorld::attach(RelationWorld::project(9, 'B'), member: 2);
        Store::query()->create(['id' => 7, 'tenant_id' => 'A', 'owner_id' => 1]);
        Store::query()->create(['id' => 8, 'tenant_id' => 'A', 'owner_id' => 2]);
        [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(Member::class)->resourcePrefix(false)->permissions([RelationPermission::class,
                RelationSource::make($project, 'members', 'pivot.role'), RelationSource::make($store, 'owner', 'store-owner')])
            ->roles([EditorRole::class, StoreOwnerRole::class])->scopes(AssignmentScopePolicy::inherit($project, $store))
            ->tenants(TenantPolicy::required(ModelTenantDefinition::make(Organization::class, 'organization'))->requireMembership(new Membership))]);
        $panel = self::install($registry);
        foreach ([['project', 7, 'A', 'projects.edit', true], ['project', 8, 'A', 'projects.edit', false],
            ['project', 9, 'B', 'projects.edit', false], ['project', 7, 'B', 'projects.edit', false],
            ['store', 7, 'A', 'projects.view', true], ['store', 8, 'A', 'projects.view', false],
            ['store', 7, 'A', 'projects.edit', false]] as [$type, $id, $tenant, $permission, $allowed]) {
            $scope = RelationWorld::scope($id, $tenant, $type);
            expect(app(Authorizer::class)->decide($panel, self::request($permission, $scope))->allowed())->toBe($allowed);
        }
        $reads = 0;
        DatabaseWorld::storage()->connection()->listen(static function (QueryExecuted $event) use (&$reads): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && preg_match('/relation_(projects|stores|project_user)/', $event->sql)) {
                $reads++;
            }
        });
        expect(app(Authorizer::class)->decide($panel, self::request('projects.edit', AccessScope::in(TenantRef::of('organization', 'A'))))->reason)
            ->toBe(DecisionReason::NotGranted)->and($reads)->toBe(0);
    }

    private static function mixed(): void
    {
        RelationWorld::seed();
        $definition = new ProjectDefinition;
        EditorRole::$definitions = [$definition];
        RelationWorld::attach(RelationWorld::project(7));
        RelationWorld::project(8);
        $scope = RelationWorld::scope(8);
        DatabaseWorld::insert('role', [DatabaseWorld::row(scope: $scope, overrides: ['role' => 'editor'])]);
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', $scope, ['permission' => 'projects.edit'])]);
        [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(Member::class)->resourcePrefix(false)->permissions([EveryoneRelationPermission::class,
                DatabaseSource::make(), RelationSource::make($definition, 'members', 'pivot.role')])
            ->roles([ProjectEditorRole::class])->scopes(AssignmentScopePolicy::inherit($definition))
            ->tenants(TenantPolicy::required(ModelTenantDefinition::make(Organization::class, 'organization'))->requireMembership(new Membership))
            ->consistency(refresh: StateRefresh::Check)]);
        $panel = self::install($registry);
        expect(app(Authorizer::class)->decide($panel, self::request('projects.edit', RelationWorld::scope(7)))->allowed())->toBeTrue()
            ->and(app(Authorizer::class)->decide($panel, self::request('projects.edit', $scope))->allowed())->toBeTrue();
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->delete();
            $mutation->touch('admin');
        });
        expect(app(Authorizer::class)->decide($panel, self::request('projects.edit', $scope))->allowed())->toBeTrue();
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('permission_grants')->update(['expires_at' => '2000-01-01 00:00:00']);
            $mutation->touch('admin');
        });
        expect(app(Authorizer::class)->decide($panel, self::request('projects.edit', $scope))->reason)->toBe(DecisionReason::NotGranted)
            ->and(app(Authorizer::class)->decide($panel, self::request('projects.edit', RelationWorld::scope(7)))->allowed())->toBeTrue()
            ->and(app(Authorizer::class)->decide($panel, self::request('projects.view', $scope))->allowed())->toBeTrue();
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
            $mutation->table('permission_grants')->update(['permission' => 'projects.view ']);
            $mutation->touch('admin');
        });
        expect(app(Authorizer::class)->decide($panel, self::request('projects.view', $scope))->reason)->toBe(DecisionReason::SourceError);
    }
}
