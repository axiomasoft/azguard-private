<?php

declare(strict_types=1);

use AzGuard\Contracts\RoleInterface;
use AzGuard\Roles\BaseRole;

// Конкретная реализация для тестирования абстрактного класса
class TestAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return ['admin.users.view', 'admin.users.create'];
    }
}

class TestSuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return ['*'];
    }
}

class TestEditorRole extends BaseRole
{
    public function getLevel(): int
    {
        return 5;
    }

    public function permissions(): array
    {
        return ['crm.posts.edit', 'crm.posts.view'];
    }
}

describe('BaseRole', function () {
    it('generates name from class name by removing Role suffix and converting to slug', function () {
        $role = new TestAdminRole;
        expect($role->getName())->toBe('test-admin');
    });

    it('generates correct slug for SuperAdmin role', function () {
        $role = new TestSuperAdminRole;
        expect($role->getName())->toBe('test-super-admin');
    });

    it('returns default level 0', function () {
        $role = new TestAdminRole;
        expect($role->getLevel())->toBe(0);
    });

    it('returns custom level when overridden', function () {
        $role = new TestEditorRole;
        expect($role->getLevel())->toBe(5);
    });

    it('returns permissions array', function () {
        $role = new TestAdminRole;
        expect($role->permissions())->toBe(['admin.users.view', 'admin.users.create']);
    });

    it('returns wildcard permission for superadmin', function () {
        $role = new TestSuperAdminRole;
        expect($role->permissions())->toContain('*');
    });

    it('implements RoleInterface', function () {
        $role = new TestAdminRole;
        expect($role)->toBeInstanceOf(RoleInterface::class);
    });
});
