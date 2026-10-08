<?php

declare(strict_types=1);

use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\PermissionResource\Pages\ListPermissions;
use AzGuard\Filament\Resources\RoleResource\Pages\ListRoles;
use AzGuard\Filament\Resources\RoleResource\Pages\ViewRole;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * V96, editor targets: a panel is offered when the plugin manages it and it stores grants; a panel or a tenant from the
 * payload is checked again on every call, so another panel or tenant is refused, not read.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    EditorWorld::prepare();
    FilamentFixture::$manages = ['admin', 'seller', 'teams', 'readonly'];
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
});

it('offers the managed panels that store grants, never a panel outside manages or a read-only one', function (): void {
    EditorWorld::editor(['azguard-roles.view_any']);

    expect(array_keys(TargetSelector::current()->panels()))->toBe(['admin', 'seller', 'teams']);
});

it('refuses a panel outside manages or a read-only one in the payload of a list and of a page', function (string $panel): void {
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view', 'azguard-permissions.view_any']);

    Livewire::test(ListRoles::class)->set('tableFilters.target.panel', $panel)->assertForbidden();
    Livewire::test(ListPermissions::class)->set('tableFilters.target.panel', $panel)->assertForbidden();
    Livewire::test(ViewRole::class, ['panel' => $panel, 'role' => 'member'])->assertForbidden();
})->with(['backoffice', 'readonly', 'unknown']);

it('chooses the tenant only where it is not fixed: the guard panel and a panel without tenants never take one', function (): void {
    EditorWorld::editor(['azguard-roles.view_any']);
    $selector = TargetSelector::current();

    expect($selector->choosesTenant('teams'))->toBeTrue()
        ->and($selector->choosesTenant('admin'))->toBeFalse()
        ->and($selector->choosesTenant('seller'))->toBeFalse()
        ->and($selector->searchTenants('teams', ''))->toBe(['7' => '7', '8' => '8'])
        ->and($selector->searchTenants('teams', '8'))->toBe(['8' => '8'])
        ->and($selector->searchTenants('seller', ''))->toBe([])
        ->and($selector->tenantLabel('teams', '8'))->toBe('8')
        ->and($selector->tenantLabel('teams', '99'))->toBeNull()
        ->and($selector->access('teams', '8')->scope()->tenant)->toEqual(TenantRef::of('team', 8))
        ->and($selector->access('seller')->scope()->tenant)->toEqual(TenantRef::global())
        ->and(fn () => $selector->access('teams', '99'))->toThrow(HttpException::class)
        ->and(fn () => $selector->access('teams'))->toThrow(HttpException::class)
        ->and(fn () => $selector->access('seller', '7'))->toThrow(HttpException::class)
        ->and(fn () => $selector->access('backoffice'))->toThrow(HttpException::class);
});
