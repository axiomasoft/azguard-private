<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld as D;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

#[Role('viewer')]
class UnmappedViewerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }
}

function unmappedSelectionWorld(VisibilitySource $source, array $extra = [], bool $mapped = false): array
{
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $p) use ($source, $extra, $mapped): void {
        $p->for(User::class)->permissions([$source, ...$extra])->roles([UnmappedViewerRole::class])
            ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)]);

        if ($mapped) {
            $p->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new UnmappedGlobalScope], evaluator: ScopeEvaluator::class));
        }
    }]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(Visibility::class);

    return [app(Visibility::class), $registry->get('admin'), app(Authorizer::class)];
}

class UnmappedGlobalProject extends VisibilityProject
{
    public function azguardContextType(): string
    {
        return 'global';
    }
}

class UnmappedGlobalScope extends VisibilityProjectScope
{
    public function type(): string
    {
        return 'global';
    }

    public function query(): Builder
    {
        return UnmappedGlobalProject::query();
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('ignores concrete assignments on unmapped resources without losing tenant-wide authority', function (string $lane, string $kind, bool $global): void {
    $scopes = [AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', 1)),
        AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('global', 1))];

    if ($global) {
        $scopes[] = AccessScope::in(TenantRef::global());
    }
    $source = new VisibilitySource;

    if ($lane === 'database') {
        app(StorageSchema::class)->create('default');
        D::insert($kind, array_map(static fn (AccessScope $scope): array => D::row($kind, $scope, [$kind => $kind === 'role' ? 'viewer' : 'orders.view']), $scopes));
    } else {
        $items = array_map(static fn (AccessScope $scope): Grant|RoleContribution => $kind === 'role'
            ? RoleContribution::of(RoleKey::of('admin', 'viewer'), $scope, 'generated')
            : Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $scope), $scopes);

        if ($kind === 'role') {
            $source->roles = $items;
        } else {
            $source->direct = $items;
        }
    }
    [$visibility, $panel, $engine] = unmappedSelectionWorld($source, $lane === 'database' ? [DatabaseSource::make()] : []);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, User::findOrFail(1));
    $scalar = $engine->decide($panel, $request);
    $batch = $engine->decideMany([$request])->get(0);
    expect($scalar->failure())->toBeNull()->and($scalar->allowed())->toBe($global)
        ->and($batch->failure())->toBeNull()->and($batch->allowed())->toBe($global)
        ->and($visibility->visibleTo($panel, User::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe($global ? [1] : []);
})->with(['database', 'custom'])->with(['permission', 'role'])->with([true, false]);

it('retains concrete database assignments when global is the mapped alias', function (): void {
    app(StorageSchema::class)->create('default');
    D::insert('permission', [D::row('permission', AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('global', 1)), ['permission' => 'orders.view'])]);
    [$visibility, $panel, $engine] = unmappedSelectionWorld(new VisibilitySource, [DatabaseSource::make()], mapped: true);
    $expected = [];
    foreach (UnmappedGlobalProject::query()->orderBy('id')->get() as $record) {
        $decision = $engine->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record));
        expect($decision->failure())->toBeNull();

        if ($decision->allowed()) {
            $expected[] = $record->id;
        }
    }
    expect($expected)->toBe([1])->and($visibility->visibleTo($panel, UnmappedGlobalProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe($expected);
});
