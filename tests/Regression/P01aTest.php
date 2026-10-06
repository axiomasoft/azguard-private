<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;

it('P01a never promotes a role from another panel to superadmin authority', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), $scope, 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, 'none', fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class]), tenant: false);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SuperAdmin)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeTrue();

    $source->roles = [RoleContribution::of(RoleKey::of('seller', 'root'), $scope, 'generated')];
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeFalse();
});
