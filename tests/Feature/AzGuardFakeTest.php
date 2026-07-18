<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Testing\Recorded;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\User;
use PHPUnit\Framework\AssertionFailedError;

function makeAzGuardFakeUser(): User
{
    return User::factory()->create();
}

it('assertGranted passes after a grant through the fluent root', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    AzGuard::assertGranted($user, 'test.post.view', 'test');
});

it('assertGranted resolves an enum key against the given panel', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant(TestPermission::PostView);

    AzGuard::assertGranted($user, TestPermission::PostView, 'test');
});

it('assertGranted supports the closure predicate', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    AzGuard::assertGranted(fn (Recorded $recorded): bool => $recorded->key === 'test.post.view');
});

it('assertDenied passes after a revoke', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    AzGuard::forUser($user)->on('test')->revoke('test.post.view');

    AzGuard::assertDenied($user, 'test.post.view', 'test');
});

it('assertDenied supports the closure predicate', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    AzGuard::forUser($user)->on('test')->revoke('test.post.view');

    AzGuard::assertDenied(fn (Recorded $recorded): bool => $recorded->key === 'test.post.view');
});

it('assertChecked passes after can()', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    expect($user->can('test.post.view'))->toBeTrue();

    AzGuard::assertChecked('test.post.view');
});

it('assertChecked supports the closure predicate', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    $user->can('test.post.view');

    AzGuard::assertChecked(fn (Recorded $recorded): bool => $recorded->key === 'test.post.view' && $recorded->result === true);
});

// Ordering-sensitive by design: these run after tests above already granted
// and checked 'test.post.view'. If fake() state leaked between tests (e.g. a
// stale Gate::after/Event::listen hook from a previous test's fake()), these
// would incorrectly pass instead of failing.
it('assertGranted fails when nothing was recorded (isolation)', function () {
    AzGuard::fake();

    $user = makeAzGuardFakeUser();

    expect(fn () => AzGuard::assertGranted($user, 'test.post.view'))
        ->toThrow(AssertionFailedError::class);
});

it('assertChecked fails when nothing was recorded (isolation)', function () {
    AzGuard::fake();

    expect(fn () => AzGuard::assertChecked('test.post.view'))
        ->toThrow(AssertionFailedError::class);
});
