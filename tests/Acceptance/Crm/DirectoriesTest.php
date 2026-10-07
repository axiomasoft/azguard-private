<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Directories\DirectoryResolver;
use AzGuard\Directories\QueryScopeDirectory;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Feature\Directories\Support\Lookups;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\ProjectDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * @return list<string>
 */
function crmProjects(Panel $panel, int $user, string $role, int $tenant = 1, int $limit = 10, int $actor = 3, AssignmentScopePhase $phase = AssignmentScopePhase::Assignment): array
{
    $lookup = Lookups::make($panel, tenant: $tenant, target: $user, role: $role, actor: $actor, phase: $phase);

    return array_map(
        fn ($option) => $option->scope->id(),
        DirectoryResolver::for($panel, app())->scopes('crm.project')->search('crm.project', '', $lookup, $limit),
    );
}

it('R36 autocomplete offers the contexts of the target seller and analyst, not of the acting admin', function (): void {
    $panel = World::compile();

    // Анна (город 1) и Борис (город 2); actor — Дарья (город 1, tenant-admin) и Борис для обратного контроля.
    expect(crmProjects($panel, user: 1, role: SellerRole::class))->toBe(['1', '5'])
        ->and(crmProjects($panel, user: 2, role: SellerRole::class))->toBe(['2'])
        ->and(crmProjects($panel, user: 2, role: SellerRole::class, actor: 2))->toBe(['2'])
        ->and(crmProjects($panel, user: 1, role: SellerRole::class, actor: 2))->toBe(['1', '5'])
        ->and(crmProjects($panel, user: 1, role: AnalystRole::class))->toBe(['1', '2', '5'])
        ->and(crmProjects($panel, user: 1, role: AnalystRole::class, tenant: 2))->toBe(['4'])
        ->and(crmProjects($panel, user: 1, role: SellerRole::class, tenant: 2))->toBe(['4']);
});

it('R36 applies LIMIT after eligibility', function (): void {
    $panel = World::compile();

    // Project 1 is the first by key but is another city for Борис; project 3 is inactive for everyone.
    expect(crmProjects($panel, user: 2, role: SellerRole::class, limit: 1))->toBe(['2'])
        ->and(crmProjects($panel, user: 1, role: AnalystRole::class, limit: 2))->toBe(['1', '2'])
        ->and(crmProjects($panel, user: 1, role: AnalystRole::class, limit: 3))->toBe(['1', '2', '5']);
});

it('R36 does not offer a context to a target of another tenant by widening to the admin tenant', function (): void {
    $panel = World::compile();

    // Организация 2 выбрана: Анна видит только P4; проекты организации 1 не попадают в чужой tenant.
    expect(crmProjects($panel, user: 1, role: AnalystRole::class, tenant: 2))->toBe(['4'])
        ->and(crmProjects($panel, user: 2, role: SellerRole::class, tenant: 2))->toBe([]);
});

it('V114 gives the filters the target, the role, the proposed fields and the actor before the limit', function (): void {
    $panel = World::compile();

    crmProjects($panel, user: 2, role: SellerRole::class, actor: 3);

    expect(SellerProjects::$observed)->not->toBeEmpty();

    foreach (SellerProjects::$observed as $runtime) {
        expect($runtime)->toBeInstanceOf(AssignmentScopeRuntime::class)
            ->and($runtime->phase)->toBe(AssignmentScopePhase::Assignment)
            ->and($runtime->subject->key())->toBe('crm.user:2')
            ->and($runtime->user?->getAttribute('city_id'))->toBe(2)
            ->and($runtime->role?->key())->toBe('seller')
            ->and($runtime->actor?->id)->toBe('3')
            ->and($runtime->actorModel?->getAttribute('city_id'))->toBe(1);
    }

    $lookup = Lookups::make($panel, target: 1, role: SellerRole::class, actor: 3, proposed: ['until' => null, 'fields' => ['region' => 'R1']]);
    SellerProjects::$observed = [];
    DirectoryResolver::for($panel, app())->scopes('crm.project')->search('crm.project', '', $lookup, 5);

    expect(SellerProjects::$observed[0]->proposed)->toBe(['until' => null, 'fields' => ['region' => 'R1']]);
});

it('V114 Inspection describes an inactive context that Assignment never offers', function (): void {
    $panel = World::compile();
    $directory = DirectoryResolver::for($panel, app())->scopes('crm.project');
    $assignment = Lookups::make($panel, target: 1, role: SellerRole::class);
    $inspection = Lookups::make($panel, target: null, phase: AssignmentScopePhase::Inspection);

    expect(array_map(fn ($o) => $o->scope->id(), $directory->search('crm.project', '', $assignment, 10)))->not->toContain('3')
        ->and(array_map(fn ($o) => $o->scope->id(), $directory->search('crm.project', '', $inspection, 10)))->toBe(['1', '2', '3', '5'])
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 3), $inspection)?->label)->toBe('3')
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 4), $inspection))->toBeNull()
        ->and($directory->describe(AssignmentScopeRef::of('crm.project', 4), $assignment))->toBeNull();
});

it('R50 an external consumer directory declared in code replaces the default through the public SPI', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->directory(ProjectDirectory::class))));
    $resolver = DirectoryResolver::for($panel, app());
    $lookup = Lookups::make($panel, target: 1, role: SellerRole::class);

    expect($resolver->scopes('crm.project'))->toBeInstanceOf(ProjectDirectory::class)
        ->and($resolver->scopes('crm.project'))->toBeInstanceOf(AssignmentScopeDirectory::class)
        ->and(array_map(fn ($o) => $o->label, $resolver->scopes('crm.project')->search('crm.project', '', $lookup, 10)))->toBe(['P1', 'P2', 'P3', 'P5'])
        ->and($panel->scopeDefinition('crm.project')?->settings()->directory)->toBe(ProjectDirectory::class);
});

it('R50 without a configured directory the default is the query directory over the definition', function (): void {
    $panel = World::compile();

    expect(DirectoryResolver::for($panel, app())->scopes('crm.project'))->toBeInstanceOf(QueryScopeDirectory::class)
        ->and(DirectoryResolver::for($panel, app())->scopes('unknown.type'))->toBeInstanceOf(QueryScopeDirectory::class);
});

it('R50 a filter written against the structural query does not change which tenant is offered', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        fn (Builder $query) => $query->where('region', 'R1')->orWhere('organization_id', 2),
    ))));

    // The OR stays inside its own group: project 5 (region R2) is out, and a tenant-2 row never reaches tenant 1.
    expect(crmProjects($panel, user: 1, role: AnalystRole::class))->toBe(['1', '2'])
        ->and(crmProjects($panel, user: 1, role: AnalystRole::class, tenant: 2))->toBe(['4']);
});
