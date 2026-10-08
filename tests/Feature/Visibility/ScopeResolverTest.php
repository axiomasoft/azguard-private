<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MissingRecordVisibilityScope extends BaseAssignmentScope
{
    public function type(): string
    {
        return 'project';
    }

    public function query(): Builder
    {
        return VisibilityProject::query();
    }

    public function tenantOf(Model $record): TenantRef
    {
        return TenantRef::global();
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        return new ResolvedAssignmentScope($ref, TenantRef::global());
    }
}

#[Role('missing-record')]
class MissingRecordVisibilityRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new MissingRecordVisibilityScope];
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('preserves a custom structural scope resolver when compiling visibility', function (bool $throws): void {
    $definition = new class($throws) extends BaseAssignmentScope
    {
        public function __construct(private readonly bool $throws) {}

        public function type(): string
        {
            return 'project';
        }

        public function query(): Builder
        {
            return VisibilityProject::query();
        }

        public function tenantOf(Model $record): TenantRef
        {
            return TenantRef::global();
        }

        public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
        {
            if ($this->throws) {
                throw new RuntimeException('Structural scope authority is unavailable.');
            }

            return $ref->id() === '1' ? null : parent::resolve($ref);
        }
    };
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel
        ->for(User::class)->permissions([new VisibilitySource(direct: [W::grant()])])
        ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
        ->scopes(AssignmentScopePolicy::inherit($definition))]);
    app()->instance(PanelRegistry::class, $registry);
    $panel = $registry->get('admin');
    $visibility = app(Visibility::class);

    if ($throws) {
        expect(fn () => $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view'))
            ->toThrow(VisibilityNotSupportedException::class);

        return;
    }
    $expected = [];
    foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
        $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource);

        if (app(Authorizer::class)->decide($panel, $request)->allowed()) {
            $expected[] = $resource->id;
        }
    }

    expect($expected)->toBe([102, 103, 104])
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())
        ->toBe($expected);
})->with([false, true]);

it('keeps scalar batch and visibility equivalent when a custom resolver omits a role-required record', function (string $contributions): void {
    $role = $contributions !== 'direct';
    $source = new VisibilitySource(direct: $contributions === 'role' ? [] : [W::grant()], roles: $role ? [W::role(key: 'missing-record')] : []);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel
        ->for(User::class)->permissions([$source])->roles([MissingRecordVisibilityRole::class])
        ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
        ->scopes(AssignmentScopePolicy::inherit(new MissingRecordVisibilityScope))]);
    app()->instance(PanelRegistry::class, $registry);
    $panel = $registry->get('admin');
    $engine = app(Authorizer::class);
    $requests = [];
    foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
        $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource);
        expect($engine->decide($panel, $request)->reason)->toBe($role ? DecisionReason::AssignmentScopeFilterError : DecisionReason::Granted);
        $requests[] = $request;
    }
    $batch = $engine->decideMany($requests);
    foreach (array_keys($requests) as $index) {
        expect($batch->get($index)->allowed())->toBe(! $role);
    }
    $query = VisibilityClient::query()->where('id', '>', 100);
    $original = [$query->toSql(), $query->getBindings()];

    if ($role) {
        expect(fn () => app(Visibility::class)->visibleTo($panel, $query, W::subject(), 'orders.view'))
            ->toThrow(VisibilityNotSupportedException::class)
            ->and([$query->toSql(), $query->getBindings()])->toBe($original);

        return;
    }
    $visible = app(Visibility::class)->visibleTo($panel, $query, W::subject(), 'orders.view');
    expect((clone $visible)->orderBy('id')->pluck('id')->all())->toBe([101, 102, 103, 104])
        ->and($visible->count())->toBe(4);
})->with(['role binding' => ['role'], 'direct grant without binding' => ['direct'], 'mixed direct and role grants' => ['mixed']]);
