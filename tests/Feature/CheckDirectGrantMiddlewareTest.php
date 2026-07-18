<?php

declare(strict_types=1);

use AzGuard\Http\Middleware\CheckDirectGrant;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('using() builds the same middleware string as the string alias DSL', function (): void {
    expect(CheckDirectGrant::using('app.reports.export', 'app'))
        ->toBe(CheckDirectGrant::class.':app.reports.export,app');
});

it('using() unwraps a BackedEnum permission on the class,not-instance boundary', function (): void {
    expect(CheckDirectGrant::using(TestPermission::PostView, 'app'))
        ->toBe(CheckDirectGrant::class.':post.view,app');
});

it('using() omits the panel argument when none is given', function (): void {
    expect(CheckDirectGrant::using('app.reports.export'))
        ->toBe(CheckDirectGrant::class.':app.reports.export');
});

it('grants access via a route built with ::using() and an enum permission', function (): void {
    $user = UserWithDirectGrants::factory()->create();
    $user->directGrants()->create([
        'panel_id' => 'app',
        'permission_key' => 'post.view',
        'expires_at' => null,
    ]);

    Route::middleware([CheckDirectGrant::using(TestPermission::PostView, 'app')])
        ->get('/direct-grant-using-test', fn (): string => 'ok');

    $this->actingAs($user)
        ->get('/direct-grant-using-test')
        ->assertOk()
        ->assertSee('ok');
});

it('denies access with 403 when the grant is absent', function (): void {
    $user = UserWithDirectGrants::factory()->create();

    Route::middleware([CheckDirectGrant::using(TestPermission::PostView, 'app')])
        ->get('/direct-grant-using-deny-test', fn (): string => 'ok');

    $this->actingAs($user)
        ->get('/direct-grant-using-deny-test')
        ->assertForbidden();
});
