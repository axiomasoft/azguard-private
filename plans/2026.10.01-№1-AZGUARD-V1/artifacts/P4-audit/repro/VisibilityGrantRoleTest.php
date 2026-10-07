<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('A02b exact visibility treats a direct Grant with a super-admin role key as whole-panel authority', function (): void {
    // Direct grant for orders.edit only, with role provenance "root" (#[SuperAdmin]); the list asks for orders.view.
    $grant = Grant::of(PermissionPattern::of('admin', 'orders.edit'), 'generated', W::scope(), role: RoleKey::of('admin', 'root'));
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [$grant]));
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();
    dump($ids);
    expect($ids)->toBe([]);
});
