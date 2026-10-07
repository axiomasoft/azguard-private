<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('P06 keeps a none panel independent of another panels isolated ambient context', function (): void {
    ScopeWorld::compile(new GeneratedSource);
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', AccessScope::in(TenantRef::global()));
    [, , $registry] = PanelWorld::compile([
        CabinetPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::isolated(User::class)),
        AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->scopes(AssignmentScopePolicy::none())->permissions([new GeneratedSource(direct: [$grant])])->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]),
    ]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $admin = $registry->get('admin');
    $cabinet = $registry->get('cabinet');
    $ambient = AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('user', 1));
    app(CurrentContext::class)->set($cabinet, $ambient);
    // A none policy ignores ambient context even if the host set one for this panel.
    app(CurrentContext::class)->set($admin, $ambient);
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view'));
    expect(app(Authorizer::class)->decide($admin, $request)->allowed())->toBeTrue()
        ->and(app(Authorizer::class)->decide($admin, $request->on($ambient->context))->reason)->toBe(DecisionReason::AssignmentScopeNotAccepted);
});
