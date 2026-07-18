<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Exceptions\InvalidMorphTypeException;
use AzGuard\Facades\AzGuard;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Testing\FakeAzGuardUser;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\Log;

// ─── isSuperAdmin (A4) ───────────────────────────────────────────────────────

it('isSuperAdmin() is true only when the user holds the wildcard', function () {
    $superRole = createRoleWithClass(['name' => 'super'], SuperAdminRole::class);
    $super = User::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => 'password']);
    $super->assignRole($superRole);

    $plain = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => 'password']);

    expect($super->isSuperAdmin())->toBeTrue()
        ->and($plain->isSuperAdmin())->toBeFalse()
        ->and(AzGuard::isSuperAdmin($super))->toBeTrue()
        ->and(AzGuard::isSuperAdmin($plain))->toBeFalse();
});

// ─── hasGrant panel resolution (B1) ──────────────────────────────────────────

it('hasGrant() with no panel resolves the default panel instead of an empty string', function () {
    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    // No panel argument — must resolve to the default panel ('app'), matching hasPermission().
    expect($user->hasGrant('app.reports.view'))->toBeTrue()
        ->and($user->hasGrant('app.reports.view', 'app'))->toBeTrue();
});

// ─── morphType fail-loud (B2) ────────────────────────────────────────────────

it('morphType() throws on an unknown value instead of silently using int', function () {
    config()->set('az-guard.column_names.morph_type', 'uuid_v7');

    expect(fn () => Config::morphType())->toThrow(InvalidMorphTypeException::class);
});

it('morphType() accepts the supported values', function () {
    foreach (['int', 'ulid', 'uuid'] as $type) {
        config()->set('az-guard.column_names.morph_type', $type);
        expect(Config::morphType())->toBe($type);
    }
});

// ─── enum panel resolution (B6) ──────────────────────────────────────────────

it('panelIdForPermission() finds the panel that owns an enum', function () {
    // TestPermission is registered on the 'test' panel by TestGuardPanelProvider.
    expect(AzGuard::panelIdForPermission(TestPermission::PostView))->toBe('test');
});

// ─── hasContextGuard + warning (A5) ──────────────────────────────────────────

it('hasContextGuard() is false without the context package and warns once', function () {
    $user = User::create(['name' => 'Ctx', 'email' => 'ctx@example.com', 'password' => 'password']);

    expect($user->hasContextGuard())->toBeFalse()
        ->and(AzGuard::hasContextGuard())->toBeFalse();

    Log::spy();

    // Silent false fallback, but observable: warns once per request (scoped dedup).
    expect($user->hasPermissionIn('workspace', 1, 'app.posts.edit'))->toBeFalse()
        ->and($user->hasPermissionIn('workspace', 2, 'app.posts.edit'))->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
});

// ─── FakeAzGuardUser test double (C1) ────────────────────────────────────────

it('FakeAzGuardUser answers permission checks from in-memory state', function () {
    $user = (new FakeAzGuardUser)->grant('app', 'app.docs.view');

    expect($user->hasPermission('app.docs.view'))->toBeTrue()
        ->and($user->hasPermission('app.docs.delete'))->toBeFalse()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->permissions()->all())->toBe(['app.docs.view']);
});

it('FakeAzGuardUser wildcard is a super-admin', function () {
    $user = (new FakeAzGuardUser)->wildcard();

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasPermission('anything.at.all'))->toBeTrue();
});

// ─── GrantBuilder TTL parity (C5) ────────────────────────────────────────────

it('GrantBuilder::expiresAt() sets an absolute expiry', function () {
    $user = UserWithDirectGrants::factory()->create();
    $at = now()->addDays(3);

    $grant = AzGuard::forUser($user)->on('app')->expiresAt($at)->grant('app.x.view');

    expect($grant->expires_at->timestamp)->toBe($at->timestamp);
});

// ─── PermissionKey constant (A1/item 11) ─────────────────────────────────────

it('exposes the wildcard as a public constant', function () {
    expect(PermissionKey::WILDCARD)->toBe('*')
        ->and(PermissionSet::WILDCARD)->toBe('*');
});
