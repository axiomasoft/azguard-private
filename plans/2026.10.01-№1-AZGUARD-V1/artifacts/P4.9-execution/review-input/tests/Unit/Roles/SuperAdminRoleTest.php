<?php

declare(strict_types=1);

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\SuperAdminRole;

it('ships a grantable super-admin role without permissions', function (): void {
    $role = new SuperAdminRole;

    expect($role->key())->toBe('superadmin')
        ->and($role->superAdmin())->toBeTrue()
        ->and($role->grantable())->toBeTrue()
        ->and($role->permissions())->toBe([])
        ->and($role->scopes())->toBe([])
        ->and((new ReflectionClass(SuperAdminRole::class))->isFinal())->toBeTrue();
});

it('translates the super-admin label', function (string $locale, string $label): void {
    app()->setLocale($locale);

    expect((new SuperAdminRole)->label())->toBe($label);
})->with([
    'en' => ['en', 'Super admin'],
    'ru' => ['ru', 'Суперадмин'],
]);

it('declares role attributes for classes only', function (string $attribute): void {
    $reflection = new ReflectionClass($attribute);
    $declared = $reflection->getAttributes(Attribute::class)[0]->newInstance();

    expect($declared->flags)->toBe(Attribute::TARGET_CLASS)
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
})->with([Role::class, SuperAdmin::class, NotGrantable::class, FormerKeys::class]);

it('keeps the role attribute defaults', function (): void {
    $role = new Role;

    expect($role->key)->toBeNull()
        ->and($role->label)->toBeNull()
        ->and($role->level)->toBe(0)
        ->and((new FormerKeys('a', 'b'))->keys)->toBe(['a', 'b'])
        ->and((new FormerKeys)->keys)->toBe([]);
});
