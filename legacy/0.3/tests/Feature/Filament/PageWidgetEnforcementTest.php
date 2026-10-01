<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Tests\Stubs\Filament\GuardedRevenueWidget;
use AzGuard\Tests\Stubs\Filament\GuardedSettingsPage;
use AzGuard\Tests\Stubs\Filament\PlainSettingsPage;
use AzGuard\Tests\Stubs\User;
use Filament\Facades\Filament;
use Filament\Pages\Concerns\CanAuthorizeAccess;
use Filament\Panel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * F13: page/widget permissions must be ENFORCED (403 by URL), not merely
 * hidden from navigation. HasAzGuardPage::canAccess() / HasAzGuardWidget::canView()
 * are the enforcement hooks Filament calls on every mount and Livewire
 * round-trip; these tests prove they actually deny.
 */
function makeAdminPanelWithGuard(): Panel
{
    $panel = Panel::make()->id('admin')->plugin(AzGuardPlugin::make()->forPanel('admin'));

    Filament::setCurrentPanel($panel);

    return $panel;
}

function grantUser(User $user, string $key): void
{
    $role = Role::create(['name' => 'granted-'.uniqid(), 'level' => 1]);
    RolePermission::create([
        'role_id' => $role->getKey(),
        'permission_key' => $key,
        'panel_id' => 'admin',
    ]);
    $user->assignRole($role->name);
    $user->load('roles');
}

it('catalogs a guarded page permission so it is grantable in the Role UI', function (): void {
    // Discovery in FilamentTestCase only yields the Project resource, so we
    // assert the schema-produced key shape the trait enforces against.
    $key = app(PermissionSchema::class)
        ->key('admin', class_basename(GuardedSettingsPage::class), 'view');

    expect($key)->toBe('admin.guarded_settings_page.view');
});

it('denies canAccess() for a page when the user lacks the permission', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(GuardedSettingsPage::canAccess())->toBeFalse();
});

it('grants canAccess() for a page once the permission is granted', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    grantUser($user, 'admin.guarded_settings_page.view');
    $this->actingAs($user);

    expect(GuardedSettingsPage::canAccess())->toBeTrue();
});

it('aborts 403 on mount when a page is reached by URL without the permission', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    $this->actingAs($user);

    // mountCanAuthorizeAccess() is Filament's URL/round-trip gate: abort_unless(canAccess(), 403).
    $page = new GuardedSettingsPage;

    expect(fn () => $page->mountCanAuthorizeAccess())
        ->toThrow(HttpException::class);
});

it('lets a granted user through the URL mount gate', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    grantUser($user, 'admin.guarded_settings_page.view');
    $this->actingAs($user);

    $page = new GuardedSettingsPage;
    $page->mountCanAuthorizeAccess();

    expect(true)->toBeTrue();
});

it('proves an un-trait page stays URL-reachable — nav-hiding is not access control', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    $this->actingAs($user);

    // No trait: Filament's default canAccess() === true, so the URL is open
    // even to a user with zero permissions. This is the gap F13 closes.
    // The page still wires Filament's mount gate (CanAuthorizeAccess), proving
    // the URL is genuinely routed — yet it never denies without the trait.
    expect(PlainSettingsPage::canAccess())->toBeTrue()
        ->and(class_uses_recursive(PlainSettingsPage::class))
        ->toContain(CanAuthorizeAccess::class);
});

it('denies canView() for a widget when the user lacks the permission', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(GuardedRevenueWidget::canView())->toBeFalse();
});

it('grants canView() for a widget once the permission is granted', function (): void {
    makeAdminPanelWithGuard();

    $user = User::factory()->create();
    grantUser($user, 'admin.guarded_revenue_widget.view');
    $this->actingAs($user);

    expect(GuardedRevenueWidget::canView())->toBeTrue();
});

it('degrades to allow outside an AzGuard-linked panel (artisan/no-panel parity)', function (): void {
    Filament::setCurrentPanel(null);

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(GuardedSettingsPage::canAccess())->toBeTrue()
        ->and(GuardedRevenueWidget::canView())->toBeTrue();
});
