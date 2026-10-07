<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\InjectedSellerFilter;
use AzGuard\Tests\Fixtures\Crm\Models\User;

it('R47 R67 V109 V111 V113 DI receives live Access target and actor without global model bindings', function (): void {
    InjectedSellerFilter::$constructed = 0;
    InjectedSellerFilter::$observed = [];
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(InjectedSellerFilter::class))));
    $constructed = InjectedSellerFilter::$constructed;
    World::assertDecision(World::decide($panel, actor: ActorRef::of('crm.user', 2)), true, DecisionReason::Granted);
    $first = InjectedSellerFilter::$observed[0];
    expect($first->user->getKey())->toBe(1)->and($first->actorModel->getKey())->toBe(2)->and($first->role)->toBeNull()
        ->and($first->grant)->toBeNull()->and($first->phase)->toBe(AssignmentScopePhase::Access)
        ->and($first->now->format('c'))->toBe('2026-10-06T12:00:00+00:00');
    World::assertDecision(World::decide($panel, actor: ActorRef::system('import')), true, DecisionReason::Granted);
    expect(InjectedSellerFilter::$observed[1])->not->toBe($first)->and(InjectedSellerFilter::$observed[1]->actorModel)->toBeNull()
        ->and(InjectedSellerFilter::$constructed)->toBeGreaterThan($constructed);
    foreach ([User::class, BaseRole::class, AssignmentScopeRuntime::class] as $class) {
        expect(app()->bound($class))->toBeFalse();
    }
});

it('R47 direct and PolicyOnly callbacks carry explicit null role and grant', function (): void {
    World::clear();
    World::assign('clients.view', 1, 1, kind: 'permission');
    $panel = World::compile();
    foreach ([Action::View, Action::ViewOwnProfile] as $action) {
        ActiveProjects::$observed = [];
        World::assertDecision(World::decide($panel, action: $action), true, $action === Action::View ? DecisionReason::Granted : DecisionReason::Policy);
        expect(ActiveProjects::$observed[0]->role)->toBeNull()->and(ActiveProjects::$observed[0]->grant)->toBeNull();
    }
});
