<?php

declare(strict_types=1);

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

describe('Authorizer — Gate integration', function (): void {

    it('denies access for user without any role', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user);

        expect(Gate::allows('test.post.view'))->toBeFalse();
    });

    it('denies access for user with wrong role', function (): void {
        $user = User::factory()->create();

        $role = createRoleWithClass(['name' => 'viewer',
            'level' => 1,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $this->actingAs($user);

        // ManagerRole grants test.post.view, but NOT admin.delete.users
        expect(Gate::allows('admin.delete.users'))->toBeFalse();
    });

    it('grants wildcard * superadmin all permissions via Gate::before', function (): void {
        $superAdminRole = new class extends BaseRole
        {
            public function permissions(): array
            {
                return ['*'];
            }
        };

        $user = User::factory()->create();

        $role = createRoleWithClass(['name' => 'superadmin',
            'level' => 1000,
        ], get_class($superAdminRole));

        $user->roles()->attach($role);
        $user->load('roles');
        $this->actingAs($user);

        expect(Gate::allows('any.permission.whatsoever'))->toBeTrue();
        expect(Gate::allows('admin.delete.users'))->toBeTrue();
    });

    it('panel prefix is respected — cross-panel permission is denied', function (): void {
        $user = User::factory()->create();

        $role = createRoleWithClass(['name' => 'manager',
            'level' => 10,
        ], ManagerRole::class);

        $user->roles()->attach($role);
        $user->load('roles');
        $this->actingAs($user);

        // test.post.view is in test panel — should be allowed
        expect(Gate::allows('test.post.view'))->toBeTrue();

        // admin.something is not in ManagerRole permissions — should be denied
        expect(Gate::allows('admin.something'))->toBeFalse();
    });
});
