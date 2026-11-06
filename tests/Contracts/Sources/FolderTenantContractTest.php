<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Scoped\EveryonePermission;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('keeps folder granted-to-all within the tenant and membership boundary in each context mode', function (AssignmentScopeMode $mode): void {
    W::$tenanted = true;
    $membership = new Membership;
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel) => $panel
        ->for(User::class)->permissions([EveryonePermission::class])
        ->assignmentScopes(new AssignmentScopeDeclaration($mode, [new VisibilityProjectScope], evaluator: ScopeEvaluator::class))
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership))]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(Visibility::class);
    $panel = $registry->get('admin');
    $authorizer = app(Authorizer::class);
    $resources = VisibilityClient::query()->orderBy('id')->get();
    $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))
        ->on(null, $resource)->inTenant(W::tenant('A')))->all();
    $batch = $authorizer->decideMany($requests);
    foreach ($requests as $index => $request) {
        $allowed = $index < 3;
        expect($authorizer->decide($panel, $request)->allowed())->toBe($allowed)
            ->and($batch->get($index)->allowed())->toBe($allowed);
    }
    expect(app(Visibility::class)->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all())->toBe([101, 102, 103]);
    $membership->members = [];
    expect($authorizer->decide($panel, $requests[0])->allowed())->toBeFalse()
        ->and($authorizer->decideMany([$requests[0]])->get(0)->allowed())->toBeFalse()
        ->and(app(Visibility::class)->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->pluck('id')->all())->toBe([]);
})->with([AssignmentScopeMode::Inherit, AssignmentScopeMode::Isolated]);
