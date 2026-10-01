<?php

declare(strict_types=1);

use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hasGrant() returns false when no grants exist', function () {
    $user = UserWithDirectGrants::factory()->create();

    expect($user->hasGrant('app.reports.view', 'app'))->toBeFalse();
});

it('hasGrant() returns true after creating an active grant', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    expect($user->hasGrant('app.reports.view', 'app'))->toBeTrue();
});

it('hasGrant() returns false for expired grant', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => now()->subSecond(),
    ]);

    expect($user->hasGrant('app.reports.view', 'app'))->toBeFalse();
});

it('hasGrant() returns true for non-expired grant', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => now()->addHour(),
    ]);

    expect($user->hasGrant('app.reports.view', 'app'))->toBeTrue();
});

it('hasGrant() is isolated per panel', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.view',
        'expires_at' => null,
    ]);

    expect($user->hasGrant('admin.users.view', 'admin'))->toBeTrue()
        ->and($user->hasGrant('admin.users.view', 'app'))->toBeFalse();
});

it('grants() returns only non-expired grants for panel', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.active', 'expires_at' => null]);
    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.expired', 'expires_at' => now()->subSecond()]);
    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.future', 'expires_at' => now()->addHour()]);
    $user->directGrants()->create(['panel_id' => 'admin', 'permission_key' => 'admin.other', 'expires_at' => null]);

    $active = $user->grants('app');

    expect($active)->toHaveCount(2)
        ->and($active->pluck('permission_key')->all())
        ->toContain('app.active')
        ->toContain('app.future');
});

it('DirectGrant::isExpired() and isActive() reflect expires_at correctly', function () {
    $expired = new DirectGrant(['expires_at' => now()->subSecond()]);
    $active = new DirectGrant(['expires_at' => null]);
    $future = new DirectGrant(['expires_at' => now()->addHour()]);

    expect($expired->isExpired())->toBeTrue()
        ->and($expired->isActive())->toBeFalse()
        ->and($active->isExpired())->toBeFalse()
        ->and($active->isActive())->toBeTrue()
        ->and($future->isExpired())->toBeFalse()
        ->and($future->isActive())->toBeTrue();
});

it('hasPermission() via HasDirectGrants trait returns true for active grant', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.special',
        'expires_at' => null,
    ]);

    $user->flushPermissions('app');

    expect($user->hasPermission('app.special', 'app'))->toBeTrue();
});
