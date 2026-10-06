<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers\ClientScopeResolver;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;

it('R18 V110 scalar supports native local scope whereHas nested OR and whereExists', function (): void {
    Project::query()->findOrFail(1)->members()->attach(1, ['role' => 'seller']);
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        function (Builder $query): void {
            $query->active()->where(fn (Builder $nested) => $nested->where('region', 'missing')->orWhere('region', 'R1'))
                ->whereHas('members', fn (Builder $members) => $members->whereKey(1))
                ->whereExists(fn (Query $sub) => $sub->selectRaw('1')->from('project_members')->whereColumn('project_members.project_id', 'projects.id')->where('user_id', 1));
        },
    ))));
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::AssignmentScopeIneligible);
    World::assertDecision(World::decide($panel, 4), false, DecisionReason::AssignmentScopeIneligible);
});

it('R18 root OR remains grouped under common active and immutable owner predicates', function (): void {
    World::assign('analyst', 1, 3);
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(
        fn (Builder $query) => $query->orWhere('id', 3)->orWhere('id', 1),
    ))));
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 4), false, DecisionReason::AssignmentScopeIneligible);
    World::assertDecision(World::decide($panel, 5), false, DecisionReason::TenantMismatch);
});

it('R18 unsafe builder changes fail closed instead of widening the scalar check', function (Closure $filter): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter($filter))));
    World::assertDecision(World::decide($panel), false, DecisionReason::AssignmentScopeFilterError);
    $panel = World::compile();
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
})->with([
    'from' => [fn (Builder $q) => $q->from('clients')],
    'connection' => [function (Builder $q): void {
        $q->getQuery()->connection = DB::connection('secondary');
    }],
    'new builder' => [fn (Builder $q) => Project::query()],
    'write' => [fn (Builder $q) => $q->delete()],
]);

it('R20 resolver query and owner exceptions refuse without a global fallback', function (string $stage): void {
    $panel = World::compile();
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);

    if ($stage === 'resource') {
        ClientScopeResolver::$throws = true;
    } else {
        ProjectScope::$failure = $stage;
    }
    World::assertDecision(World::decide($panel), false, DecisionReason::AssignmentScopeFilterError);
})->with(['resource', 'resolve', 'query', 'owner']);
