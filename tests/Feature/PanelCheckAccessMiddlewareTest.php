<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\PanelCheckAccess;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Route;

enum TestPanelCheckPanelId: string
{
    case Test = 'test';
}

it('handle() takes permission before panelId — the flipped what,where order', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'panel-check-manager', 'level' => 0], ManagerRole::class);
    $user->assignRole($role);
    $user->load('roles');

    // Old (pre-P2.4) order was `panel,permission` — this route only passes
    // the pipeline if handle()'s first parameter is really the permission.
    Route::middleware(['azguard.panel_check:test.post.view,test'])
        ->get('/panel-check-order-test', fn (): string => 'ok');

    $this->actingAs($user)
        ->get('/panel-check-order-test')
        ->assertOk()
        ->assertSee('ok');

    expect(AzGuard::currentPanel())->toBeNull();
});

it('using() builds the same middleware string as the string alias DSL', function (): void {
    expect(PanelCheckAccess::using('test.post.view', 'test'))
        ->toBe(PanelCheckAccess::class.':test.post.view,test');
});

it('using() unwraps BackedEnum permission and panelId', function (): void {
    expect(PanelCheckAccess::using(TestPermission::PostView, TestPanelCheckPanelId::Test))
        ->toBe(PanelCheckAccess::class.':post.view,test');
});

it('grants access via a route built with ::using()', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'panel-check-using-manager', 'level' => 0], ManagerRole::class);
    $user->assignRole($role);
    $user->load('roles');

    Route::middleware([PanelCheckAccess::using('test.post.view', 'test')])
        ->get('/panel-check-using-test', fn (): string => 'ok');

    $this->actingAs($user)
        ->get('/panel-check-using-test')
        ->assertOk()
        ->assertSee('ok');
});

it('denies access with 403 when the permission is missing', function (): void {
    $user = User::factory()->create();

    Route::middleware([PanelCheckAccess::using('test.post.view', 'test')])
        ->get('/panel-check-using-deny-test', fn (): string => 'ok');

    $this->actingAs($user)
        ->get('/panel-check-using-deny-test')
        ->assertForbidden();
});

it('fails with 500 when the panel is not registered', function (): void {
    // Unlike SetCurrentPanel (which aborts with a raw Response, bypassing the
    // exception handler and its own error-view rendering), PanelCheckAccess
    // uses abort(500, message) — Laravel's default 500 view does not surface
    // the message, so only the status is asserted here.
    Route::middleware([PanelCheckAccess::using('test.post.view', 'unknown-panel')])
        ->get('/panel-check-unknown-test', fn (): string => 'ok');

    $this->get('/panel-check-unknown-test')
        ->assertStatus(500);
});
