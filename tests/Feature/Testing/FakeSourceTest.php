<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Testing\FakeSource;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Testing\KitStore;
use AzGuard\Tests\Fixtures\Testing\KitWorld;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;

uses(DatabaseMigrations::class);

/*
 * The in-memory source feeds the real engine: scope, expiry and roles behave as for any source, and a permission the
 * test did not grant stays denied.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-08T12:00:00Z');
    $this->source = new FakeSource;
    KitWorld::panel(fn ($panel) => $panel->permissions([$this->source]));
});
afterEach(function (): void {
    KitWorld::reset();
    Carbon::setTestNow();
});

it('gives exactly what the test granted and nothing else', function (): void {
    $user = FakeSubject::of(1);
    $this->source->grantPermission($user, PermissionPattern::of('seller', 'orders.view'));

    expect(AzGuard::check($user, 'seller:orders.view'))->toBeTrue()
        ->and(AzGuard::check($user, 'seller:orders.cancel'))->toBeFalse()
        ->and(AzGuard::check(FakeSubject::of(2), 'seller:orders.view'))->toBeFalse()
        ->and(AzGuard::panel('seller')->for($user)->decide('seller:orders.cancel')->reason)->toBe(DecisionReason::NotGranted);
});

it('keeps a scoped grant in its scope and a tenant-wide grant everywhere it applies', function (): void {
    $user = FakeSubject::of(1);
    $this->source->grantPermission($user, PermissionPattern::of('seller', 'orders.view'), on: AssignmentScopeRef::of('store', 5));

    expect(AzGuard::check($user, 'seller:orders.view', KitStore::make(5)))->toBeTrue()
        ->and(AzGuard::check($user, 'seller:orders.view', KitStore::make(6)))->toBeFalse()
        ->and(AzGuard::check($user, 'seller:orders.view'))->toBeFalse();
});

it('stops giving an expired grant at the time the engine reads', function (): void {
    $user = FakeSubject::of(1);
    $this->source->grantPermission($user, PermissionPattern::of('seller', 'orders.view'), until: Carbon::now('UTC')->addHour()->toDateTimeImmutable());

    expect(AzGuard::check($user, 'seller:orders.view'))->toBeTrue();
    Carbon::setTestNow('2026-10-08T13:00:01Z');
    expect(AzGuard::check($user, 'seller:orders.view'))->toBeFalse();
});

it('gives the permissions of a granted role and the superadmin role everything', function (): void {
    $user = FakeSubject::of(1);
    $this->source->grantRole($user, RoleKey::of('seller', 'superadmin'));

    expect(AzGuard::check($user, 'seller:orders.cancel'))->toBeTrue()
        ->and(AzGuard::panel('seller')->for($user)->isSuperAdmin())->toBeTrue();
});

it('forgets its grants on clear and answers by its id', function (): void {
    $user = FakeSubject::of(1);
    $this->source->grantPermission($user, PermissionPattern::of('seller', 'orders.view'))->clear();

    expect(AzGuard::check($user, 'seller:orders.view'))->toBeFalse()->and($this->source->id())->toBe('fake');
});
