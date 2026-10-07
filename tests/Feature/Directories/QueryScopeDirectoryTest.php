<?php

declare(strict_types=1);

use AzGuard\Directories\DirectoryResolver;
use AzGuard\Directories\QueryScopeDirectory;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DirectoryScanLimitException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Tests\Feature\Directories\Support\AllowedProjectsAdapter;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Feature\Directories\Support\UnboundRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\ExternalProjectScope;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    World::seed();
    AllowedProjectsAdapter::$observed = [];
    AllowedProjectsAdapter::$allowed = ['1', '2'];
});

afterEach(function (): void {
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function ids(array $options): array
{
    return array_map(fn ($option) => $option->scope->id(), $options);
}

function projects(Panel $panel, int $tenant = 1, ?int $target = 1, ?string $role = null, string $term = '', int $limit = 10, AssignmentScopePhase $phase = AssignmentScopePhase::Assignment, array $proposed = [], ?int $actor = 2): array
{
    return DirectoryResolver::for($panel, app())->scopes('crm.project')
        ->search('crm.project', $term, Lookups::make($panel, $tenant, $target, $role, $actor, $phase, $proposed), $limit);
}

/** Project scope whose owner is an arbitrary callback: no SQL tenant column is available to the directory. */
function callbackScope(): ModelAssignmentScopeDefinition
{
    return ModelAssignmentScopeDefinition::make(Project::class, fn (Model $project): TenantRef => TenantRef::of('crm.organization', (string) $project->getAttribute('organization_id')), 'crm.cproject');
}

function callbackPanel(?Closure $configure = null): Panel
{
    return World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make(), $configure === null ? callbackScope() : $configure(callbackScope()))));
}

it('offers the contexts of the target and not of the acting admin', function (): void {
    $panel = World::compile();

    // Project 3 is inactive (common filter), 4 is another tenant, 2 is another city (seller binding).
    expect(ids(projects($panel, target: 1, role: SellerRole::class)))->toBe(['1', '5'])
        ->and(ids(projects($panel, target: 2, role: SellerRole::class)))->toBe(['2'])
        ->and(ids(projects($panel, target: 2, role: SellerRole::class, actor: 1)))->toBe(['2'])
        ->and(ids(projects($panel, target: 1, role: AnalystRole::class)))->toBe(['1', '2', '5'])
        ->and(ids(projects($panel, target: 1, role: null)))->toBe(['1', '2', '5'])
        ->and(ids(projects($panel, tenant: 2, target: 1, role: AnalystRole::class)))->toBe(['4']);
});

it('applies the limit after eligibility', function (): void {
    $panel = World::compile();

    // Project 1 is the first by key but belongs to another city than the target.
    expect(ids(projects($panel, target: 2, role: SellerRole::class, limit: 1)))->toBe(['2'])
        ->and(ids(projects($panel, target: 1, role: AnalystRole::class, limit: 2)))->toBe(['1', '2'])
        ->and(projects($panel, target: 1, role: AnalystRole::class, limit: 0))->toBe([]);
});

it('gives the filters the target, the role, the proposed fields, the actor and the phase of the lookup', function (): void {
    $observed = new ArrayObject;
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        function (Builder $query, AssignmentScopeRuntime $runtime) use ($observed): void {
            $observed[] = $runtime;
            $query->where('is_active', true);
        },
    ))));

    projects($panel, target: 2, role: SellerRole::class, proposed: ['until' => null, 'fields' => ['region' => 'R1']], actor: 3);

    expect(count($observed))->toBeGreaterThan(0);

    foreach ($observed->getArrayCopy() as $runtime) {
        expect($runtime->subject->key())->toBe('crm.user:2')
            ->and($runtime->user?->getKey())->toBe(2)
            ->and($runtime->actor?->id)->toBe('3')
            ->and($runtime->actorModel?->getKey())->toBe(3)
            ->and($runtime->proposed)->toBe(['until' => null, 'fields' => ['region' => 'R1']])
            ->and($runtime->phase)->toBe(AssignmentScopePhase::Assignment)
            ->and($runtime->scope->tenant->key())->toBe('crm.organization:1');
    }
    expect(array_map(fn ($r) => $r->role, $observed->getArrayCopy()))->each->toBeNull()
        ->and(SellerProjects::$observed)->toHaveCount(1)
        ->and(SellerProjects::$observed[0]->role?->key())->toBe('seller')
        ->and(SellerProjects::$observed[0]->user?->getKey())->toBe(2)
        ->and(SellerProjects::$observed[0]->actor?->id)->toBe('3')
        ->and(SellerProjects::$observed[0]->proposed)->toBe(['until' => null, 'fields' => ['region' => 'R1']]);
});

it('returns nothing before any filter for an Assignment search without a selected target', function (): void {
    $observed = new ArrayObject;
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        function (Builder $query, AssignmentScopeRuntime $runtime) use ($observed): void {
            $observed[] = $runtime;
        },
    ))));

    expect(projects($panel, target: null, role: SellerRole::class))->toBe([])
        ->and(count($observed))->toBe(0);
});

it('returns nothing for a role that is not granted in the context type', function (): void {
    $panel = World::compile();

    expect(projects($panel, role: UnboundRole::class))->toBe([]);
});

it('skips eligibility but never the tenant for Inspection and Revocation', function (AssignmentScopePhase $phase): void {
    $panel = World::compile();

    // Inactive project 3 and the other city stay visible to the admin; tenant 2 project 4 does not.
    expect(ids(projects($panel, target: 2, role: SellerRole::class, phase: $phase)))->toBe(['1', '2', '3', '5'])
        ->and(ids(projects($panel, target: null, role: null, phase: $phase)))->toBe(['1', '2', '3', '5'])
        ->and(ids(projects($panel, tenant: 2, target: null, phase: $phase)))->toBe(['4'])
        ->and(ids(projects($panel, term: '3', target: null, phase: $phase)))->toBe(['3']);
})->with([[AssignmentScopePhase::Inspection], [AssignmentScopePhase::Revocation]]);

it('keeps the tenant and structural boundary when a filter ORs conditions', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        fn (Builder $query) => $query->orWhere('id', 3)->orWhere('id', 4)->orWhere('id', 1),
    ))));

    // Project 4 passes the OR but belongs to tenant 2, inactive 3 passes it but fails the common active filter.
    expect(ids(projects($panel, target: 1)))->toBe(['1']);
});

it('matches the key and the model search columns of a context', function (): void {
    $panel = World::compile();

    expect(ids(projects($panel, term: '5', target: 1, role: AnalystRole::class)))->toBe(['5'])
        ->and(ids(projects($panel, term: '', target: 1, role: AnalystRole::class)))->toBe(['1', '2', '5'])
        ->and(projects($panel, term: 'no-such', target: 1, role: AnalystRole::class))->toBe([])
        ->and(projects($panel, term: '5%', target: 1, role: AnalystRole::class))->toBe([]);
});

it('does not let a foreign or filtered chunk use up the limit of an arbitrary owner callback', function (): void {
    $panel = callbackPanel();
    Project::query()->insert([
        ['id' => 6, 'organization_id' => 2, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
        ['id' => 7, 'organization_id' => 2, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
        ['id' => 8, 'organization_id' => 1, 'city_id' => 1, 'region' => 'R1', 'is_active' => true],
    ]);
    $lookup = Lookups::make($panel, tenant: 1, target: 1, phase: AssignmentScopePhase::Inspection);
    $chunked = new QueryScopeDirectory(app(), chunk: 2, maxScan: 100);

    // Tenant 2 owns 4, 6 and 7: the first chunks hold other tenants only, valid rows come later.
    $second = Lookups::make($panel, tenant: 2, target: 1, phase: AssignmentScopePhase::Inspection);

    expect(ids($chunked->search('crm.cproject', '', $second, 2)))->toBe(['4', '6'])
        ->and(ids($chunked->search('crm.cproject', '', $second, 1)))->toBe(['4'])
        ->and(ids($chunked->search('crm.cproject', '', $second, 10)))->toBe(['4', '6', '7'])
        ->and(ids($chunked->search('crm.cproject', '', $lookup, 10)))->toBe(['1', '2', '3', '5', '8'])
        ->and(ids((new QueryScopeDirectory(app(), chunk: 1, maxScan: 100))->search('crm.cproject', '', $second, 3)))->toBe(['4', '6', '7']);
});

it('keeps scanning after chunks that every filter rejects', function (): void {
    $panel = callbackPanel(fn ($scope) => $scope->filter(fn (Builder $query) => $query->where('id', '>=', 5)));
    $chunked = new QueryScopeDirectory(app(), chunk: 1, maxScan: 100);

    expect(ids($chunked->search('crm.cproject', '', Lookups::make($panel, 1, 1), 5)))->toBe(['5']);
});

it('refuses a partial list when the scan budget ends before the limit or the end of the candidates', function (): void {
    $panel = callbackPanel();
    $lookup = Lookups::make($panel, tenant: 2, target: 1, phase: AssignmentScopePhase::Inspection);

    expect(fn () => (new QueryScopeDirectory(app(), chunk: 1, maxScan: 2))->search('crm.cproject', '', $lookup, 1))->toThrow(DirectoryScanLimitException::class)
        ->and(ids((new QueryScopeDirectory(app(), chunk: 1, maxScan: 4))->search('crm.cproject', '', $lookup, 1)))->toBe(['4'])
        ->and(fn () => new QueryScopeDirectory(app(), chunk: 0))->toThrow(DefinitionException::class)
        ->and(fn () => new QueryScopeDirectory(app(), chunk: 5, maxScan: 4))->toThrow(DefinitionException::class);
});

it('lets the access adapter of the type narrow the candidates before the limit', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make())
        ->accessAdapter('crm.project', AllowedProjectsAdapter::class)));
    AllowedProjectsAdapter::$allowed = ['2', '5'];

    expect(ids(projects($panel, target: 1, role: AnalystRole::class)))->toBe(['2', '5'])
        ->and(ids(projects($panel, target: 1, role: AnalystRole::class, limit: 1)))->toBe(['2'])
        ->and(ids(projects($panel, target: 1, role: SellerRole::class)))->toBe(['5'])
        ->and(ids(projects($panel, target: 1, phase: AssignmentScopePhase::Inspection)))->toBe(['1', '2', '3', '5']);
    expect(AllowedProjectsAdapter::$observed[0]->subject->key())->toBe('crm.user:1');
});

it('describes a scope of the selected tenant and refuses a foreign, missing or unaccepted one without a label', function (AssignmentScopePhase $phase): void {
    $panel = World::compile();
    $lookup = Lookups::make($panel, tenant: 1, target: 1, role: SellerRole::class, phase: $phase);
    $directory = DirectoryResolver::for($panel, app())->scopes('crm.project');

    expect($directory->describe(AssignmentScopeRef::of('crm.project', 1), $lookup)?->label)->toBe('1')
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 4), $lookup))->toBeNull()
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 99), $lookup))->toBeNull()
        ->and($directory->describe(AssignmentScopeRef::of('unknown.type', 1), $lookup))->toBeNull()
        ->and($directory->describe(AssignmentScopeRef::global(), $lookup))->toBeNull();
})->with([[AssignmentScopePhase::Assignment], [AssignmentScopePhase::Inspection]]);

it('describes an inactive context to Inspection and to the owner callback tenant only', function (): void {
    $panel = callbackPanel();
    $directory = DirectoryResolver::for($panel, app())->scopes('crm.cproject');
    $inspection = Lookups::make($panel, tenant: 1, target: null, phase: AssignmentScopePhase::Inspection);

    expect($directory->describe(AssignmentScopeRef::of('crm.cproject', 3), $inspection)?->scope->id())->toBe('3')
        ->and($directory->describe(AssignmentScopeRef::of('crm.cproject', 4), $inspection))->toBeNull()
        ->and($directory->describe(AssignmentScopeRef::of('crm.cproject', 4), Lookups::make($panel, tenant: 2, target: null, phase: AssignmentScopePhase::Inspection))?->label)->toBe('4');
});

it('searches nothing for an external definition without a query', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(new ExternalProjectScope)));
    $directory = DirectoryResolver::for($panel, app())->scopes('external.project');
    $lookup = Lookups::make($panel, tenant: 1, target: 1);

    expect($directory->search('external.project', '', $lookup, 5))->toBe([])
        ->and($directory->describe(AssignmentScopeRef::of('external.project', 1), $lookup)?->label)->toBe('1')
        ->and($directory->describe(AssignmentScopeRef::of('external.project', 2), $lookup))->toBeNull();
});
