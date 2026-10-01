<?php

declare(strict_types=1);

use AzGuard\Contracts\RoleInterface;
use AzGuard\Exceptions\InvalidRoleClassException;
use AzGuard\Models\Role;
use AzGuard\Roles\BaseRole;

class NotARole
{
    public function permissions(): array
    {
        return ['*'];
    }
}

class ProperRole extends BaseRole
{
    public function permissions(): array
    {
        return ['app.posts.view'];
    }
}

describe('Role::getRoleLogic', function () {

    it('returns null when class_name is not set', function () {
        $role = new Role(['name' => 'x']);

        expect($role->getRoleLogic())->toBeNull();
    });

    it('throws when class_name does not exist', function () {
        $role = new Role(['name' => 'x']);
        $role->class_name = 'AzGuard\\Nope\\Missing';

        expect(fn () => $role->getRoleLogic())->toThrow(InvalidRoleClassException::class);
    });

    it('throws when class_name is non-null but empty', function () {
        $role = new Role(['name' => 'x']);
        $role->class_name = '';

        expect(fn () => $role->getRoleLogic())->toThrow(InvalidRoleClassException::class);
    });

    it('throws when class_name does not implement RoleInterface', function () {
        $role = new Role(['name' => 'x']);
        $role->class_name = NotARole::class;

        expect(fn () => $role->getRoleLogic())->toThrow(InvalidRoleClassException::class);
    });

    it('instantiates a RoleInterface implementation', function () {
        $role = new Role(['name' => 'x']);
        $role->class_name = ProperRole::class;

        expect($role->getRoleLogic())
            ->toBeInstanceOf(RoleInterface::class)
            ->and($role->getRoleLogic()->permissions())->toBe(['app.posts.view']);
    });
});
