<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\UserWithDirectGrants;

/**
 * F6: the Grants facade shorthands (grant/revoke/grants) must default
 * panelId=null and resolve through PanelResolver::resolveDefault, so that
 * az-guard.default_panel is honoured instead of a hardcoded 'app'.
 */
beforeEach(function () {
    $this->manager = app(AzGuardManagerInterface::class);
    $this->grantsTable = (new DirectGrant)->getTable();
});

it('grant() with no panel honours az-guard.default_panel', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();

    $grant = $this->manager->grant($user, 'admin.users.view');

    expect($grant)->toBeInstanceOf(DirectGrant::class)
        ->and($grant->panel_id)->toBe('admin');

    $this->assertDatabaseHas($this->grantsTable, [
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.view',
    ]);
    $this->assertDatabaseMissing($this->grantsTable, [
        'panel_id' => 'app',
        'permission_key' => 'admin.users.view',
    ]);
});

it('grant() falls back to app when default_panel is not configured', function () {
    config()->set('az-guard.default_panel', null);

    $user = UserWithDirectGrants::factory()->create();

    $grant = $this->manager->grant($user, 'app.reports.view');

    expect($grant->panel_id)->toBe('app');
});

it('grant() honours an explicit panelId over the configured default', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();

    $grant = $this->manager->grant($user, 'app.reports.view', 'app');

    expect($grant->panel_id)->toBe('app');
});

it('revoke() with no panel targets the configured default_panel', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.view',
        'expires_at' => null,
    ]);
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'admin.users.view',
        'expires_at' => null,
    ]);

    $deleted = $this->manager->revoke($user, 'admin.users.view');

    expect($deleted)->toBe(1);
    $this->assertDatabaseMissing($this->grantsTable, [
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.view',
    ]);
    // The app grant on the wrong panel must remain untouched.
    $this->assertDatabaseHas($this->grantsTable, [
        'panel_id' => 'app',
        'permission_key' => 'admin.users.view',
    ]);
});

it('revoke() honours an explicit panelId over the configured default', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    $deleted = $this->manager->revoke($user, 'app.reports.view', 'app');

    expect($deleted)->toBe(1);
});

it('grants() with no panel lists grants from the configured default_panel', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'admin',
        'permission_key' => 'admin.users.view',
        'expires_at' => null,
    ]);
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    $grants = $this->manager->grants($user);

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->permission_key)->toBe('admin.users.view')
        ->and($grants->pluck('panel_id')->unique()->all())->toBe(['admin']);
});

it('grants() honours an explicit panelId over the configured default', function () {
    config()->set('az-guard.default_panel', 'admin');

    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'app.reports.view',
        'expires_at' => null,
    ]);

    $grants = $this->manager->grants($user, 'app');

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->panel_id)->toBe('app');
});
