<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

// Expected-behaviour guard for P13 in the exact path with allowGlobalRoles configured: passes on HEAD,
// fails when the Visibility global-role check (Visibility.php:156-159) is removed (mutant M11).
it('A09 exact visibility ignores ordinary global grants and non-allow-listed global roles in tenant A', function (): void {
    W::$tenanted = true;
    $global = AccessScope::in(TenantRef::global());
    $source = new VisibilitySource(direct: [Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $global)],
        roles: [RoleContribution::of(RoleKey::of('admin', 'viewer'), $global, 'generated')]);
    [$visibility, $panel] = W::compile($source, fn (PanelBuilder $p) => $p->tenants(TenantPolicy::required(Organization::class)
        ->requireMembership(new Membership)->allowGlobalRoles([GrantableRootRole::class])), tenant: true);
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all();
    expect($ids)->toBe([]);
});
