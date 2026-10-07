<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityRestriction;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

// Direct grant of one pattern with role provenance "root" (a super-admin role).
function visibilityRoleGrant(string $pattern): Grant
{
    return Grant::of(PermissionPattern::of('admin', $pattern), 'generated', W::scope(), role: RoleKey::of('admin', 'root'));
}

it('A02 does not treat a direct grant with a super-admin role key as whole-panel authority in lists', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [visibilityRoleGrant('orders.edit')]));
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([]);
});

it('A02 keeps restrictions on lists for a direct grant with a super-admin role key', function (): void {
    VisibilityRestriction::$exempt = true;
    [$visibility, $panel] = W::compile(
        new VisibilitySource(direct: [visibilityRoleGrant('orders.view')]),
        fn (PanelBuilder $panel) => $panel->restrictions([new VisibilityRestriction]),
    );
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([101, 104]);
});
