<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\ExternalProjectScope;
use Illuminate\Support\Facades\DB;

it('R19 R50 scalar external definition resolves model null without a project query and denies timeout', function (): void {
    ExternalProjectScope::$timeout = false;
    ExternalProjectScope::$resolutions = 0;
    $scope = AccessScope::in(TenantRef::of('crm.organization', 1), AssignmentScopeRef::of('external.project', 1));
    World::assign('clients.view_any', 1, 1, kind: 'permission', scope: $scope);
    $panel = World::compile(fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(new ExternalProjectScope)));
    $request = AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of('crm', 'clients.view_any'))->inScope($scope);
    DB::flushQueryLog();
    DB::enableQueryLog();
    World::assertDecision(app(Authorizer::class)->decide($panel, $request), true, DecisionReason::Granted);
    expect(ExternalProjectScope::$resolutions)->toBeGreaterThan(0);
    expect(array_filter(DB::getQueryLog(), fn (array $row) => str_contains($row['query'], '"projects"')))->toBe([]);
    $foreign = $request->inScope(AccessScope::in(TenantRef::of('crm.organization', 1), AssignmentScopeRef::of('external.project', 2)));
    World::assertDecision(app(Authorizer::class)->decide($panel, $foreign), false, DecisionReason::AssignmentScopeMismatch);
    $unknown = $request->inScope(AccessScope::in(TenantRef::of('crm.organization', 1), AssignmentScopeRef::of('external.project', 99)));
    World::assertDecision(app(Authorizer::class)->decide($panel, $unknown), false, DecisionReason::AssignmentScopeNotAccepted);
    ExternalProjectScope::$timeout = true;

    try {
        World::assertDecision(app(Authorizer::class)->decide($panel, $request), false, DecisionReason::AssignmentScopeFilterError);
    } finally {
        ExternalProjectScope::$timeout = false;
    }
});
