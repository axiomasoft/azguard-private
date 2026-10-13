<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Modules\Scopes\Fixtures\RecordingEvaluator;
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
    $filter = static function (Builder $query, AssignmentScopeRuntime $runtime) use (&$seen): void {
        $seen[] = [$runtime->proposed, $runtime->actor, $runtime->user?->getKey()];
    };
    CrmWorld::$projectScope = ProjectScope::make()->filter(new ActiveProjects)->filter($filter);
    $panel = CrmWorld::compile();
    $definition = $panel->scopeDefinition('crm.project');
    $evaluator = new ScopeEvaluator(app());
    $runtime = assignmentRuntime($panel, 1, null, ['until' => null, 'fields' => ['region' => 'R1']], new SellerRole);

    expect($evaluator->allows($runtime, $definition?->resolve(AssignmentScopeRef::of('crm.project', 1))))->toBeTrue()
        ->and($seen)->toBe([[['until' => null, 'fields' => ['region' => 'R1']], null, 1]])
        ->and(end(SellerProjects::$observed)->role)->toBeInstanceOf(SellerRole::class)
        ->and(end(SellerProjects::$observed)->actor)->toBeNull();
});

it('refuses explicitly a filter that requires an actor or actor model that is absent', function (Closure $filter): void {
    CrmWorld::$projectScope = ProjectScope::make()->filter(new ActiveProjects)->filter($filter);
    $panel = CrmWorld::compile();
    $resolved = $panel->scopeDefinition('crm.project')?->resolve(AssignmentScopeRef::of('crm.project', 1));

    expect(fn () => (new ScopeEvaluator(app()))->allows(assignmentRuntime($panel, 1, null), $resolved))->toThrow(RuntimeException::class);
})->with([
    'actor' => [static function (Builder $query, AssignmentScopeRuntime $runtime): void {
        if ($runtime->actor === null) {
            throw new RuntimeException('This filter requires an actor.');
        }
    }],
    'actor model' => [static function (Builder $query, AssignmentScopeRuntime $runtime): void {
        if (! $runtime->actorModel instanceof User) {
            throw new RuntimeException('This filter requires an actor model.');
        }
    }],
]);

it('answers for a verified scope only through its filters: an active project passes, an inactive one does not', function (): void {
    $panel = CrmWorld::compile();
    $definition = $panel->scopeDefinition('crm.project');
    $evaluator = new ScopeEvaluator(app());

    expect($evaluator->allows(assignmentRuntime($panel, 3, null), $definition?->resolve(AssignmentScopeRef::of('crm.project', 3))))->toBeFalse()
        ->and($evaluator->allows(assignmentRuntime($panel, 1, null), $definition?->resolve(AssignmentScopeRef::of('crm.project', 1))))->toBeTrue();
});

it('refuses a missing or foreign scope structurally in the write before the evaluator is asked', function (): void {
    RecordingEvaluator::reset();
    app()->bind(ScopeEvaluator::class, static fn (): RecordingEvaluator => new RecordingEvaluator);
    $panel = W::panel();

    expect(fn () => W::grant($panel, 'analyst', 2, 99))->toThrow(AssignmentScopeNotAcceptedException::class, 'does not exist')
        ->and(fn () => W::grant($panel, 'analyst', 2, 4))->toThrow(TenantMismatchException::class, 'belongs to another tenant')
        ->and(RecordingEvaluator::$asked)->toBe([])
        ->and(W::grant($panel, 'analyst', 2, 1)->applied())->toBeTrue()
        ->and(RecordingEvaluator::$asked)->toBe(['crm.project:1']);
    RecordingEvaluator::$denied = ['2'];

    expect(fn () => W::grant($panel, 'analyst', 2, 2))->toThrow(AssignmentScopeNotAcceptedException::class, 'not accepted for this target and role');
});

it('accepts a lookup without an actor', function (): void {
    $panel = CrmWorld::compile();
    $lookup = new LookupContext(panel: $panel, scope: AccessScope::in(TenantRef::of('crm.organization', 1)), actor: null, actorModel: null,
        subject: null, user: null, role: null, proposed: [], phase: AssignmentScopePhase::Assignment, now: new DateTimeImmutable('2026-10-06T12:00:00Z'));

    expect($lookup->actor)->toBeNull()->and($lookup->subject)->toBeNull();
});
