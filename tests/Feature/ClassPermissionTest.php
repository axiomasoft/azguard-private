<?php

declare(strict_types=1);

use AzGuard\Contracts\Permission;
use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;

/** A class-based permission — referenced by ::class, like an enum case. */
final class PostViewPermission implements Permission
{
    public static function ability(): string
    {
        return 'post.view';
    }
}

it('resolves a class-based permission to its panel-scoped key', function () {
    expect(AzGuard::permission('test', PostViewPermission::class))->toBe('test.post.view');
    expect(AzGuard::tryPermission('test', PostViewPermission::class))->toBe('test.post.view');
});

it('checks a class-based permission granted by a role', function () {
    // ManagerRole grants 'test.post.view'.
    $role = createRoleWithClass(['name' => 'manager'], ManagerRole::class);
    $user = User::create([
        'name' => 'Class Perm User',
        'email' => 'classperm@example.com',
        'password' => 'password',
    ]);
    $user->roles()->attach($role);

    expect($user->hasPermission(PostViewPermission::class, 'test'))->toBeTrue();
    // A plain dotted key is never misdetected as a class and still works.
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
    expect($user->hasPermission('test.post.delete', 'test'))->toBeFalse();
});
