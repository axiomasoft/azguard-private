<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Registry\Sources\DirectGrantSource;
use AzGuard\Tests\Stubs\CustomDirectGrant;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\DB;

function makeGrant(UserWithDirectGrants $user, string $key, string $panel = 'app'): void
{
    DB::table(config('az-guard.table_names.direct_grants'))->insert([
        'grantable_type' => $user::class,
        'grantable_id' => $user->getAuthIdentifier(),
        'panel_id' => $panel,
        'permission_key' => $key,
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('yields an active direct grant when the feature is enabled (baseline)', function () {
    config(['az-guard.features.direct_grants' => true]);

    $user = UserWithDirectGrants::factory()->create();
    makeGrant($user, 'app.reports.view');

    $result = app(DirectGrantSource::class)->permissionsFor($user, 'app');

    expect($result->grants('app.reports.view'))->toBeTrue()
        ->and($result->grants('app.reports.delete'))->toBeFalse();
});

it('yields NO grants when features.direct_grants is disabled (reads stopped, not just writes)', function () {
    // Seed while enabled so the row physically exists in the table.
    config(['az-guard.features.direct_grants' => true]);
    $user = UserWithDirectGrants::factory()->create();
    makeGrant($user, 'app.reports.view');

    // Now disable the feature: the read path must yield nothing.
    config(['az-guard.features.direct_grants' => false]);

    expect(Config::directGrantsEnabled())->toBeFalse();

    $result = app(DirectGrantSource::class)->permissionsFor($user, 'app');

    expect($result->isEmpty())->toBeTrue()
        ->and($result->grants('app.reports.view'))->toBeFalse();
});

it('honours a custom config(models.direct_grant) model on the read path', function () {
    config(['az-guard.features.direct_grants' => true]);
    config(['az-guard.models.direct_grant' => CustomDirectGrant::class]);

    expect(Config::directGrantModel())->toBe(CustomDirectGrant::class);

    $user = UserWithDirectGrants::factory()->create();
    // Two rows in the same table: one the custom model's global scope keeps,
    // one it filters out. If the source used the hardcoded DirectGrant model
    // the "app.*" key would leak through.
    makeGrant($user, 'custom.reports.view');
    makeGrant($user, 'app.reports.view');

    $result = app(DirectGrantSource::class)->permissionsFor($user, 'app');

    expect($result->grants('custom.reports.view'))->toBeTrue()
        ->and($result->grants('app.reports.view'))->toBeFalse();
});

it('does not return expired grants and isolates by panel (enabled behaviour unchanged)', function () {
    config(['az-guard.features.direct_grants' => true]);

    $user = UserWithDirectGrants::factory()->create();

    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.active', 'expires_at' => null]);
    $user->directGrants()->create(['panel_id' => 'app', 'permission_key' => 'app.expired', 'expires_at' => now()->subSecond()]);
    $user->directGrants()->create(['panel_id' => 'admin', 'permission_key' => 'admin.only', 'expires_at' => null]);

    $result = app(DirectGrantSource::class)->permissionsFor($user, 'app');

    expect($result->grants('app.active'))->toBeTrue()
        ->and($result->grants('app.expired'))->toBeFalse()
        ->and($result->grants('admin.only'))->toBeFalse();
});

it('has priority 80', function () {
    expect(app(DirectGrantSource::class)->priority())->toBe(80);
});
