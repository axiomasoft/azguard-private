<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Resources\PermissionResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Filament\Resources\RoleResource\Pages\ListRoles;
use AzGuard\Filament\Resources\RoleResource\Pages\ViewRole;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\GrantedMemberRole;
use AzGuard\Tests\Fixtures\Filament\Guards\SellerMemberRole;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * V61, V23, R38 UI, R25 UI: the role catalogue shows the code roles of a managed panel and changes nothing; a payload
 * that names a class, a definition or permissions of a role finds nothing to write.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    EditorWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    EditorWorld::seed();
});

it('registers the role and permission editors that resources() keeps', function (): void {
    expect(Filament::getPanel('admin')->getResources())->toContain(RoleResource::class, PermissionResource::class);

    FilamentFixture::$editors = ['roles' => true, 'permissions' => false];
    $this->bootFilament();

    expect(Filament::getPanel('admin')->getResources())->toContain(RoleResource::class)
        ->not->toContain(PermissionResource::class);
});

it('opens the catalogue only with azguard-roles.view_any in the guard panel', function (): void {
    $this->actingAs(GateWorld::grant(['orders.view_any']));
    $this->get('/admin/azguard-roles')->assertForbidden();

    GateWorld::grant(['azguard-roles.view_any']);
    $this->get('/admin/azguard-roles')->assertOk()->assertSee('member');
});

it('V61 lists the code roles of the chosen managed panel with how each one is given', function (): void {
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view']);

    $list = Livewire::test(ListRoles::class)->assertSee('member');

    expect(array_column($list->instance()->getTableRecords()->all(), 'class', 'key'))->toBe(['member' => GrantedMemberRole::class]);

    $list->set('tableFilters.target.panel', 'seller');

    expect(array_column($list->instance()->getTableRecords()->all(), 'class'))->toBe([SellerMemberRole::class]);

    $admin = RoleResource::row(AzGuard::panel('admin')->roles()->find('member'));
    $seller = RoleResource::row(AzGuard::panel('seller')->roles()->find('member'));

    expect($admin)->toMatchArray(['key' => 'member', 'grantable' => true, 'automatic' => false, 'super_admin' => false])
        ->and($seller)->toMatchArray(['key' => 'member', 'grantable' => false, 'automatic' => true]);
});

it('V61 shows a role read-only with the authority of each of its permissions and no checkbox', function (): void {
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view']);

    Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'member'])
        ->assertOk()
        ->assertSee(GrantedMemberRole::class)
        ->assertSee('entry.enter')
        ->assertSee('granted')
        ->assertSee('pages.*')
        ->assertSee('pattern')
        ->assertDontSeeHtml('type="checkbox"');
});

it('V61 refuses the page of a role without azguard-roles.view, and an unknown role', function (): void {
    EditorWorld::editor(['azguard-roles.view_any']);

    Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'member'])->assertForbidden();

    GateWorld::grant(['azguard-roles.view']);
    Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'nobody'])->assertNotFound();
});

it('V23 finds no way to change a role: no page, action, property or method writes, and nothing is stored', function (string $field): void {
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view']);
    $state = AzGuard::panel('admin')->state();
    $rows = DB::table('azg_role_grants')->count() + DB::table('azg_permission_grants')->count();
    $page = Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'member']);

    expect(fn () => $page->set($field, 'App\\Roles\\Forged'))->toThrow(PublicPropertyNotFoundException::class)
        ->and(fn () => $page->set('data.'.$field, ['forged']))->toThrow(PublicPropertyNotFoundException::class)
        ->and(fn () => $page->call('save'))->toThrow(MethodNotFoundException::class)
        ->and(fn () => $page->set('role', 'admin'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $page->set('panel', 'seller'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(AzGuard::panel('admin')->state())->toEqual($state)
        ->and(DB::table('azg_role_grants')->count() + DB::table('azg_permission_grants')->count())->toBe($rows)
        ->and(array_keys(RoleResource::getPages()))->toBe(['index', 'view'])
        ->and(RoleResource::hasPage('create'))->toBeFalse()
        ->and(RoleResource::hasPage('edit'))->toBeFalse();
})->with(['class_name', 'definition', 'permissions', 'superAdmin']);

it('V23 offers no action in the catalogue table', function (): void {
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view']);

    $table = Livewire::test(ListRoles::class)->instance()->getTable();

    expect($table->getActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([]);
});
