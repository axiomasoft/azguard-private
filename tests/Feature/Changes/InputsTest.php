<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Sources\WriterSource;

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

it('normalizes a role given by key, full key or registered class before the lock', function (): void {
    $panel = W::panel();
    $pipeline = W::pipeline();

    expect($pipeline->role($panel, 'seller'))->toEqual(RoleKey::of('crm', 'seller'))
        ->and($pipeline->role($panel, 'crm:seller'))->toEqual(RoleKey::of('crm', 'seller'))
        ->and($pipeline->role($panel, SellerRole::class))->toEqual(RoleKey::of('crm', 'seller'))
        ->and($pipeline->role($panel, '\\'.RootRole::class))->toEqual(RoleKey::of('crm', 'root'))
        ->and($pipeline->role($panel, 'edtor'))->toEqual(RoleKey::of('crm', 'edtor'))
        ->and(fn () => $pipeline->role($panel, User::class))->toThrow(UnknownRoleException::class)
        ->and(fn () => $pipeline->role($panel, 'backoffice:seller'))->toThrow(UnknownRoleException::class)
        ->and(fn () => $pipeline->role($panel, 'a:b:c'))->toThrow(InvalidRoleKeyException::class);
});

it('normalizes a permission given by local or full name, key or enum case', function (): void {
    $panel = W::panel();
    $pipeline = W::pipeline();

    expect($pipeline->permission($panel, 'clients.view')->full())->toBe('crm:clients.view')
        ->and($pipeline->permission($panel, 'crm:clients.*')->local())->toBe('clients.*')
        ->and($pipeline->permission($panel, PermissionKey::of('crm', 'clients.update'))->local())->toBe('clients.update')
        ->and($pipeline->permission($panel, ClientPermission::ViewAny)->local())->toBe('clients.view_any')
        ->and(fn () => $pipeline->permission($panel, 'backoffice:clients.view'))->toThrow(UnknownPermissionException::class);
});

it('refuses a panel without a writer the change pipeline supports', function (array $sources): void {
    $panel = CrmWorld::compile(sources: $sources);

    expect(fn () => W::grant($panel, 'analyst', 2, 1))->toThrow(PanelNotWritableException::class);
})->with([
    'foreign writer' => [[new WriterSource('ledger')]],
    'no writer' => [[]],
]);
