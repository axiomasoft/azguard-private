<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament;

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Filament\Guards\ArchivedOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\GrantedMemberRole;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderRulesPolicy;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Pages\ReportsPage;
use AzGuard\Tests\Fixtures\Filament\Widgets\OrderCountWidget;
use Filament\Facades\Filament;

/**
 * The admin panel of the authorization tests: orders 1 and 2, the secret order 3 that nobody sees and the locked order 4
 * that nobody deletes; products 1 and 2 of order 1, the secret product 2, the products 3 and 4 to attach. User 1 holds the
 * stored member role and no other grant until a test gives it.
 */
final class GateWorld
{
    /** Sets the fixture up; the test boots the application with it. */
    public static function prepare(): void
    {
        FilamentFixture::$adminRoles = [GrantedMemberRole::class];
        FilamentFixture::$guardPermissions = [OrderPermission::class, ArchivedOrderPermission::class];
        FilamentFixture::$guardPolicies = [OrderRulesPolicy::class];
        FilamentFixture::$pages = [ProbePage::class, ReportsPage::class];
        FilamentFixture::$widgets = [OrderCountWidget::class];
    }

    /** The rows of the world, after the application has booted. */
    public static function seed(): void
    {
        Order::query()->insert([
            ['id' => 2, 'number' => 'A-2', 'secret' => false, 'locked' => false],
            ['id' => 3, 'number' => 'S-3', 'secret' => true, 'locked' => false],
            ['id' => 4, 'number' => 'L-4', 'secret' => false, 'locked' => true],
        ]);
        Product::query()->insert([
            ['id' => 1, 'name' => 'Pen', 'secret' => false],
            ['id' => 2, 'name' => 'Safe', 'secret' => true],
            ['id' => 3, 'name' => 'Ink', 'secret' => false],
            ['id' => 4, 'name' => 'Paper', 'secret' => false],
        ]);
        Order::query()->findOrFail(1)->products()->attach([1, 2]);
        AzGuard::panel('admin')->for(User::query()->findOrFail(1))->grantRole('member');
    }

    /**
     * Gives member 1 the permissions in the admin panel.
     *
     * @param  list<string>  $permissions
     */
    public static function grant(array $permissions, int $user = 1): User
    {
        $member = User::query()->findOrFail($user);
        AzGuard::panel('admin')->for($member)->grantPermission($permissions);

        return $member;
    }

    public static function revoke(string $permission, int $user = 1): void
    {
        AzGuard::panel('admin')->for(User::query()->findOrFail($user))->revokePermission($permission);
    }

    /**
     * What a request to a Filament panel sets up before a Livewire component runs: the user, the Filament panel and,
     * as `azguard.panel` does, the guard panel of its plugin.
     */
    public static function serve(User $user, string $panel = 'admin'): void
    {
        auth()->guard('web')->setUser($user);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
        $plugin = Filament::getPanel($panel)->hasPlugin('azguard') ? AzGuardPlugin::get($panel) : null;
        app(CurrentPanel::class)->set($plugin === null ? null : app(PanelRegistry::class)->get($plugin->getGuardPanel()));
    }
}
