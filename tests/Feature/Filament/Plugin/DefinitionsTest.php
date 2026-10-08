<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Guards\DuplicateOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPolicy;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/*
 * V75, definitions part: where the permissions of a Filament panel are described is separate from who decides them.
 * Enums: the permission enums of the guard panel, FilamentSource is not needed. Resources: FilamentSource reads the
 * Filament classes, with an explicit authority; nothing is stored.
 */

/** @return array<string, PermissionSchema> permissions of the admin guard panel by local name */
function adminPermissions(): array
{
    $permissions = [];

    foreach (AzGuard::panel('admin')->schema()->permissions() as $permission) {
        $permissions[$permission->key->local()] = $permission;
    }

    return $permissions;
}

function resourcesMode(?string $authority = null): void
{
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    FilamentFixture::$authority = $authority;
    FilamentFixture::$abilities = ['view_any', 'update'];
    FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
}

it('does not need FilamentSource with Enums definitions: the plugin boots and the guard panel holds the enums only', function (): void {
    Filament::getPanel('admin')->boot();

    expect(adminPermissions())->toHaveKey('entry.enter')
        ->and(adminPermissions())->not->toHaveKey('orders.view_any')
        ->and(AzGuardPlugin::get('admin')->getDefinitions())->toBe(FilamentDefinitions::Enums);
});

it('defines the permissions of the Filament classes with the explicit authority, Grants by default', function (): void {
    resourcesMode();
    $this->bootFilament();
    $permissions = adminPermissions();

    expect(array_keys($permissions))->toContain('orders.view_any', 'orders.update', 'archived-orders.view_any', 'pages.probe', 'entry.enter')
        ->and($permissions['orders.view_any']->authority)->toBe(PermissionAuthority::Grants)
        ->and($permissions['orders.view_any']->grantable())->toBeTrue()
        ->and($permissions['orders.view_any']->label)->toBe('View any: Orders')
        ->and($permissions['orders.view_any']->resourceGroup)->toBe('Sales')
        ->and($permissions['pages.probe']->authority)->toBe(PermissionAuthority::Grants)
        ->and($permissions['orders.view_any']->owner)->toBe('filament');
});

it('keeps two resources of one model apart', function (): void {
    resourcesMode();
    $this->bootFilament();
    $permissions = adminPermissions();

    expect($permissions)->toHaveKeys(['orders.view_any', 'archived-orders.view_any']);
});

it('does not choose the authority by the presence of a policy: only the explicit setting does', function (): void {
    resourcesMode();
    FilamentFixture::$guardPolicies = [PolicyBinding::for('orders.view_any', OrderPolicy::class, 'viewAny')];
    $this->bootFilament();

    expect(adminPermissions()['orders.view_any']->authority)->toBe(PermissionAuthority::Grants);
});

it('lets the plugin say Policy for the definitions, and requires the exact policy binding', function (): void {
    resourcesMode('policy');
    FilamentFixture::$exclude = ['resources' => [ArchivedOrderResource::class], 'pages' => [ProbePage::class]];
    FilamentFixture::$abilities = ['view_any'];
    FilamentFixture::$guardPolicies = [PolicyBinding::for('orders.view_any', OrderPolicy::class, 'viewAny')];
    $this->bootFilament();
    $permission = adminPermissions()['orders.view_any'];

    expect($permission->authority)->toBe(PermissionAuthority::Policy)
        ->and($permission->grantable())->toBeFalse();
});

it('fails the catalog of the guard panel when a Policy definition has no policy', function (): void {
    resourcesMode('policy');
    FilamentFixture::$guardPolicies = [];

    expect(fn () => $this->bootFilament())->toThrow(InvalidPolicyStructureException::class, 'orders.view_any');
});

it('is a compile conflict, not a merge, when an enum and FilamentSource own one key', function (): void {
    resourcesMode();
    FilamentFixture::$guardPermissions[] = DuplicateOrderPermission::class;

    expect(fn () => $this->bootFilament())->toThrow(DuplicatePermissionException::class, 'orders.view_any');
});

it('stores the assignment of a Resources definition without dynamic permissions and stores no definition', function (): void {
    resourcesMode();
    $this->bootFilament();
    $user = User::query()->findOrFail(2);

    $result = $user->guard('admin')->grantPermission('orders.view_any');

    expect($result->committed)->toBeTrue()
        ->and($user->guard('admin')->hasPermission('orders.view_any'))->toBeTrue()
        ->and($user->guard('admin')->hasPermission('orders.update'))->toBeFalse()
        ->and(DB::table('azg_permission_grants')->count())->toBe(1)
        ->and(DB::table('azg_permissions')->count())->toBe(0);
});

it('refuses FilamentSource when the plugin of its Filament panel does not use Resources definitions', function (): void {
    FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];

    expect(fn () => $this->bootFilament())->toThrow(ConfigurationException::class, 'FilamentDefinitions::Resources');
});

it('refuses FilamentSource of a Filament panel whose guard panel is another one', function (): void {
    resourcesMode();
    FilamentFixture::$guardPermissions = [FilamentSource::make('backoffice')];

    expect(fn () => $this->bootFilament())->toThrow(ConfigurationException::class, 'whose guard panel is "backoffice"');
});

it('refuses FilamentSource of a Filament panel that is not registered', function (): void {
    resourcesMode();
    FilamentFixture::$guardPermissions = [FilamentSource::make('ghost')];

    expect(fn () => $this->bootFilament())->toThrow(ConfigurationException::class, 'Filament panel "ghost", which is not registered');
});

it('says what is missing when Resources definitions have no FilamentSource in the guard panel', function (): void {
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())
        ->toThrow(ConfigurationException::class, "FilamentSource::make('admin')");
});

it('refuses an authority on the plugin when the definitions are Enums', function (): void {
    FilamentFixture::$authority = 'policy';
    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())
        ->toThrow(ConfigurationException::class, 'authority() applies to FilamentDefinitions::Resources only');
});

it('puts the definitions of FilamentSource into azguard:catalog:cache', function (): void {
    $path = sys_get_temp_dir().'/azguard-filament-'.bin2hex(random_bytes(6)).'/azguard.php';
    FilamentFixture::$catalogCachePath = $path;
    resourcesMode();
    $this->bootFilament();

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require $path;
    @unlink($path);
    @rmdir(dirname($path));
    $locals = array_column($file['panels']['admin']['catalog']['permissions'], 'local');

    expect($locals)->toContain('orders.view_any', 'archived-orders.update', 'pages.probe');
});
