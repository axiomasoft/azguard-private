<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidRoleIdentityException;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Support\RoleIdentity;

it('reserves the built-in super-admin persisted name', function (): void {
    expect(RoleIdentity::persistedName('app', 'ignored', SuperAdminRole::class))
        ->toBe(RoleIdentity::SUPER_ADMIN_NAME);
});

it('joins panel id and role name for ordinary code roles', function (): void {
    expect(RoleIdentity::persistedName('app', 'editor', 'App\\Roles\\EditorRole'))
        ->toBe('app:editor');
});

it('rejects an empty panel id or role name', function (): void {
    expect(fn () => RoleIdentity::persistedName('', 'editor', 'App\\Roles\\EditorRole'))
        ->toThrow(InvalidRoleIdentityException::class, 'Code-role panel id must be a non-empty string.')
        ->and(fn () => RoleIdentity::persistedName('app', '', 'App\\Roles\\EditorRole'))
        ->toThrow(InvalidRoleIdentityException::class, 'Code-role name must be a non-empty string.');
});

it('rejects a colon inside the panel id or role name', function (): void {
    expect(fn () => RoleIdentity::persistedName('app:extra', 'editor', 'App\\Roles\\EditorRole'))
        ->toThrow(InvalidRoleIdentityException::class)
        ->and(fn () => RoleIdentity::persistedName('app', 'ed:itor', 'App\\Roles\\EditorRole'))
        ->toThrow(InvalidRoleIdentityException::class);
});

it('rejects a persisted name longer than the roles.name column', function (): void {
    $panel = str_repeat('p', 200);
    $name = str_repeat('n', 60);

    expect(fn () => RoleIdentity::persistedName($panel, $name, 'App\\Roles\\EditorRole'))
        ->toThrow(InvalidRoleIdentityException::class, 'exceeds the 255-character');
});
