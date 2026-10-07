<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

// Expected-behaviour guard: a context-specific contribution of a role with empty scopes() never authorizes (P4.19).
// Passes on HEAD; fails when the Visibility role-scope check (Visibility.php:171-173) is removed (mutant M12).
it('A09b exact visibility ignores a project contribution of a role without a project binding', function (): void {
    $source = new VisibilitySource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), W::scope(1), 'generated')]);
    [$visibility, $panel, $authorizer] = W::compile($source);
    $scalar = $authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, VisibilityClient::query()->findOrFail(101)));
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();
    expect($scalar->allowed())->toBeFalse()->and($ids)->toBe([]);
});
