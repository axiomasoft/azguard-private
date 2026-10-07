<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\FailingSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use Illuminate\Support\Carbon;

it('PolicyOnly reads neither grants nor roles and uses code state', function (): void {
    $source = new FailingSource;
    [$engine,$panel,$request] = AuthorizationWorld::compile($source);
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeTrue()->and($decision->reason)->toBe(DecisionReason::Policy)
        ->and($decision->state)->toBeInstanceOf(CodeStateToken::class)->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
});
it('RequiresGrant cannot be authorized by policy true without a grant', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource, fn (PanelBuilder $panel) => $panel->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)->and(RuntimePolicy::$calls)->toBe(1);
});
it('source errors after a granting source deny in both source orders', function (bool $reverse): void {
    $good = new GeneratedSource(direct: [AuthorizationWorld::grant()]);
    $bad = new FailingSource(name: 'broken');
    [$engine,$panel,$request] = AuthorizationWorld::compile($reverse ? $bad : $good, extra: [$reverse ? $good : $bad]);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
})->with([false, true]);
it('policy null denies PolicyOnly and passes the attached grants veto', function (): void {
    RuntimePolicy::$result = null;
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)]));
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $policy = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    expect($engine->decide($panel, $policy)->reason)->toBe(DecisionReason::Policy)->and($engine->decide($panel, $policy)->allowed())->toBeFalse();
});
it('policy false vetoes a qualified superadmin', function (): void {
    RuntimePolicy::$result = false;
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::Policy)->and($engine->decide($panel, $request)->allowed())->toBeFalse();
});
it('applies restrictions to policy candidates even when they exempt superadmins', function (): void {
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource, fn (PanelBuilder $panel) => $panel->restrictions([$restriction]));
    $decision = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')));
    expect($decision->reason)->toBe(DecisionReason::Restricted)->and($decision->component)->toBe('recording');
});
it('rejects explicit tenant and context in none none before hooks and sources', function (string $scope, DecisionReason $reason): void {
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant()]);
    $hooks = 0;
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, function (PanelBuilder $panel) use (&$hooks): void {
        $panel->before(function () use (&$hooks): BeforeResult {
            $hooks++;

            return BeforeResult::Continue;
        });
    });
    $request = $scope === 'tenant' ? $request->inTenant(TenantRef::of('org', 3)) : $request->on(AssignmentScopeRef::of('project', 3));
    expect($engine->decide($panel, $request)->reason)->toBe($reason)->and($hooks)->toBe(0)->and($source->grantReads)->toBe(0);
})->with([['tenant', DecisionReason::TenantMismatch], ['context', DecisionReason::AssignmentScopeNotAccepted]]);
it('traced decision contains only qualified covering direct grants', function (): void {
    $valid = AuthorizationWorld::grant();
    $expired = AuthorizationWorld::grant(expires: Carbon::now('UTC')->toDateTimeImmutable());
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [$expired, $valid]));
    expect($engine->decide($panel, $request->traced())->grants)->toBe([$valid])->and($engine->decide($panel, $request)->grants)->toBe([]);
});
