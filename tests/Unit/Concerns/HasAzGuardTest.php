<?php

declare(strict_types=1);

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('HasAzGuard trait', function () {
    beforeEach(function () {
        config(['az-guard.cache.store' => 'array']);
    });

    it('hasRole returns true for assigned role', function () {
        $user = User::create([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'manager',
            'level' => 0,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        expect($user->hasRole('manager'))->toBeTrue();
    });

    it('hasRole returns false for non-assigned role', function () {
        $user = User::create([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'secret',
        ]);

        expect($user->hasRole('admin'))->toBeFalse();
    });

    it('hasPermission returns true for permission granted via role', function () {
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'manager2',
            'level' => 0,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
    });

    it('hasPermission returns false for ungranted permission', function () {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'manager3',
            'level' => 0,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        expect($user->hasPermission('admin.delete'))->toBeFalse();
    });

    it('permissions caches result in-memory', function () {
        $user = User::create([
            'name' => 'Cache User',
            'email' => 'cache@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'manager4',
            'level' => 0,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        $first = $user->permissions('test');
        $second = $user->permissions('test');

        // Должен вернуть те же данные (кэш — один объект PermissionSet)
        expect($first->toArray())->toBe($second->toArray());
    });

    it('flushPermissions resets in-memory cache', function () {
        $user = User::create([
            'name' => 'Clear Cache User',
            'email' => 'clear@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'manager5',
            'level' => 0,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');

        $before = $user->permissions('test');
        $user->flushPermissions('test');

        // После сброса — новая загрузка с теми же данными
        $after = $user->permissions('test');

        expect($after->toArray())->toBe($before->toArray());
    });

    it('wildcard * grants all permissions when present', function () {
        // Создаём роль с wildcard через анонимный класс
        $wildcardRole = new class extends BaseRole
        {
            public function permissions(): array
            {
                return ['*'];
            }
        };

        $user = User::create([
            'name' => 'Super',
            'email' => 'super@example.com',
            'password' => 'secret',
        ]);

        $role = createRoleWithClass(['name' => 'superadmin',
            'level' => 100,
        ], get_class($wildcardRole));

        $user->roles()->attach($role);
        $user->load('roles');

        expect($user->hasPermission('any.random.permission'))->toBeTrue();
        expect($user->hasPermission('admin.delete.everything'))->toBeTrue();
    });
});
