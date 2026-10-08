<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament;

use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Filament\Guards\ArchivedOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\ArchivedOrderPolicy;
use AzGuard\Tests\Fixtures\Filament\Guards\EditorPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\GrantedMemberRole;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderRulesPolicy;
use AzGuard\Tests\Fixtures\Filament\Guards\PolicyArchivedOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Facades\Filament;

/**
 * The admin panel with the role and permission editors: user 1 holds the stored member role; the admin manages `admin`
 * and `seller`, `seller` and `teams` keep dynamic permissions, and `seller` has the policy-only `archived-orders.*`.
 * Teams 7 and 8 exist; user 1 is the editor and user 2 the one who receives grants.
 */
final class EditorWorld
{
    public static function prepare(): void
    {
        FilamentFixture::$adminRoles = [GrantedMemberRole::class];
        FilamentFixture::$guardPermissions = [OrderPermission::class, ArchivedOrderPermission::class, EditorPermission::class];
        FilamentFixture::$guardPolicies = [OrderRulesPolicy::class];
        FilamentFixture::$sellerPermissions = [PolicyArchivedOrderPermission::class];
        FilamentFixture::$sellerPolicies = [ArchivedOrderPolicy::class];
        FilamentFixture::$editors = ['roles' => true, 'permissions' => true];
    }

    public static function seed(): void
    {
        Team::query()->insert(['id' => 8, 'name' => 'Eight']);
        AzGuard::panel('admin')->for(User::query()->findOrFail(1))->grantRole('member');
    }

    /**
     * Signs user 1 in to the `admin` Filament panel with the permissions in the admin panel.
     *
     * @param  list<string>  $permissions
     */
    public static function editor(array $permissions): User
    {
        $editor = GateWorld::grant($permissions);
        GateWorld::serve($editor);

        return $editor;
    }

    /**
     * Signs user 1 in to the `tenanted` Filament panel in team 7, as the admission middleware would, with the
     * permissions in that team of the teams panel.
     *
     * @param  list<string>  $permissions
     */
    public static function teamEditor(array $permissions): User
    {
        $editor = User::query()->findOrFail(1);
        $team = TenantRef::of('team', 7);
        AzGuard::panel('teams')->inTenant($team)->for($editor)->grantRole('member');
        AzGuard::panel('teams')->inTenant($team)->for($editor)->grantPermission($permissions);
        GateWorld::serve($editor, 'tenanted');
        Filament::setTenant(Team::query()->findOrFail(7), isQuiet: true);
        app(CurrentContext::class)->set(app(PanelRegistry::class)->get('teams'), AccessScope::in($team));

        return $editor;
    }
}
