<?php

declare(strict_types=1);

use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function assignmentRuntime(Panel $panel, int $project, ?ActorRef $actor, array $proposed = [], ?BaseRole $role = null, int $tenant = 1): AssignmentScopeRuntime
{
    return new AssignmentScopeRuntime(panel: $panel, scope: AccessScope::in(TenantRef::of('crm.organization', $tenant), AssignmentScopeRef::of('crm.project', $project)),
        subject: SubjectRef::of('crm.user', 1), user: User::query()->find(1), role: $role, grant: null, actor: $actor, actorModel: null,
        now: new DateTimeImmutable('2026-10-06T12:00:00Z'), phase: AssignmentScopePhase::Assignment, proposed: $proposed);
}

it('keeps Access frames on an empty proposal and a present actor', function (): void {
    $panel = CrmWorld::compile();
    CrmWorld::decide($panel, actor: ActorRef::of('crm.user', 3));
    $runtime = end(SellerProjects::$observed);

    expect($runtime->phase)->toBe(AssignmentScopePhase::Access)
        ->and($runtime->proposed)->toBe([])
        ->and($runtime->actor)->toEqual(ActorRef::of('crm.user', 3));
});

it('gives Assignment filters the validated proposal, the target user, the code role and a nullable actor', function (): void {
    $seen = [];
    $filter = static function (Builder $query, array $proposed, ?ActorRef $actor, ?User $user) use (&$seen): void {
        $seen[] = [$proposed, $actor, $user?->getKey()];
    };
    $panel = CrmWorld::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter($filter))));
    $definition = $panel->scopeDefinition('crm.project');
    $eligibility = new ScopeEligibility(app());
    $runtime = assignmentRuntime($panel, 1, null, ['until' => null, 'fields' => ['region' => 'R1']], new SellerRole);

    expect($eligibility->assignment($runtime, $definition?->resolve(AssignmentScopeRef::of('crm.project', 1))))->toBeTrue()
        ->and($seen)->toBe([[['until' => null, 'fields' => ['region' => 'R1']], null, 1]])
        ->and(end(SellerProjects::$observed)->role)->toBeInstanceOf(SellerRole::class)
        ->and(end(SellerProjects::$observed)->actor)->toBeNull();
});

it('refuses explicitly a filter that requires an actor or actor model that is absent', function (Closure $filter): void {
    $panel = CrmWorld::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter($filter))));
    $resolved = $panel->scopeDefinition('crm.project')?->resolve(AssignmentScopeRef::of('crm.project', 1));

    expect(fn () => (new ScopeEligibility(app()))->assignment(assignmentRuntime($panel, 1, null), $resolved))->toThrow(RuntimeException::class);
})->with([
    'actor' => [static fn (Builder $query, ActorRef $actor) => null],
    'actor model' => [static fn (Builder $query, User $actorModel) => null],
]);

it('passes a global context and refuses a missing, foreign or mismatched resolution', function (): void {
    $panel = CrmWorld::compile();
    $definition = $panel->scopeDefinition('crm.project');
    $eligibility = new ScopeEligibility(app());
    $global = new AssignmentScopeRuntime(panel: $panel, scope: AccessScope::in(TenantRef::of('crm.organization', 1)), subject: SubjectRef::of('crm.user', 1),
        user: null, role: null, grant: null, actor: null, actorModel: null, now: new DateTimeImmutable('2026-10-06T12:00:00Z'), phase: AssignmentScopePhase::Assignment);

    expect($eligibility->assignment($global, null))->toBeTrue()
        ->and($eligibility->assignment(assignmentRuntime($panel, 1, null), null))->toBeFalse()
        ->and($eligibility->assignment(assignmentRuntime($panel, 1, null), $definition?->resolve(AssignmentScopeRef::of('crm.project', 2))))->toBeFalse()
        ->and($eligibility->assignment(assignmentRuntime($panel, 4, null, tenant: 1), $definition?->resolve(AssignmentScopeRef::of('crm.project', 4))))->toBeFalse()
        ->and($eligibility->assignment(assignmentRuntime($panel, 3, null), $definition?->resolve(AssignmentScopeRef::of('crm.project', 3))))->toBeFalse()
        ->and($eligibility->assignment(assignmentRuntime($panel, 1, null), $definition?->resolve(AssignmentScopeRef::of('crm.project', 1))))->toBeTrue();
});

it('accepts a lookup without an actor', function (): void {
    $panel = CrmWorld::compile();
    $lookup = new LookupContext(panel: $panel, scope: AccessScope::in(TenantRef::of('crm.organization', 1)), actor: null, actorModel: null,
        subject: null, user: null, role: null, proposed: [], phase: AssignmentScopePhase::Assignment, now: new DateTimeImmutable('2026-10-06T12:00:00Z'));

    expect($lookup->actor)->toBeNull()->and($lookup->subject)->toBeNull();
});
