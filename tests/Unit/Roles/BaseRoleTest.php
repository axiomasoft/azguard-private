<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\PlatformRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;
use Illuminate\Foundation\Auth\User;

/**
 * @return array{key: string, label: string, level: int, formerKeys: list<string>, grantable: bool, superAdmin: bool}
 */
function roleShape(BaseRole $role): array
{
    return [
        'key' => $role->key(),
        'label' => $role->label(),
        'level' => $role->level(),
        'formerKeys' => $role->formerKeys(),
        'grantable' => $role->grantable(),
        'superAdmin' => $role->superAdmin(),
    ];
}

it('reads a role declared by attributes', function (): void {
    expect(roleShape(new SellerRole))->toBe([
        'key' => 'seller', 'label' => 'Seller', 'level' => 10, 'formerKeys' => ['shop-seller', 'vendor'],
        'grantable' => true, 'superAdmin' => false,
    ]);
});

it('treats a method override exactly like the attribute', function (): void {
    expect(roleShape(new AnalystRole))->toBe([
        'key' => 'analyst', 'label' => 'Analyst', 'level' => 10, 'formerKeys' => ['data-analyst'],
        'grantable' => true, 'superAdmin' => false,
    ])->and(array_diff_key(roleShape(new RootRole), ['key' => 0, 'label' => 0]))
        ->toBe(array_diff_key(roleShape(new PlatformRole), ['key' => 0, 'label' => 0]))
        ->and(roleShape(new RootRole))->toMatchArray(['superAdmin' => true, 'grantable' => false]);
});

it('falls back to the key when no label is declared', function (): void {
    expect((new PlatformRole)->label())->toBe('platform')
        ->and((new #[Role('auditor')] class extends BaseRole
        {
            public function permissions(): array
            {
                return [];
            }
        })->label())->toBe('auditor');
});

it('defaults to a grantable, tenant-wide, level zero role without former keys', function (): void {
    $role = new #[Role('viewer')] class extends BaseRole
    {
        public function permissions(): array
        {
            return ['orders.view'];
        }
    };

    expect($role->level())->toBe(0)
        ->and($role->formerKeys())->toBe([])
        ->and($role->grantable())->toBeTrue()
        ->and($role->superAdmin())->toBeFalse()
        ->and($role->scopes())->toBe([])
        ->and($role->scopeRequired())->toBeFalse();
});

it('keeps scopes and the scope requirement declared by the role', function (): void {
    expect((new SellerRole)->scopes())->toEqual([new ProjectScope])
        ->and((new SellerRole)->scopeRequired())->toBeTrue()
        ->and((new AnalystRole)->scopes())->toBe([ProjectScope::class])
        ->and((new AnalystRole)->scopeRequired())->toBeFalse();
});

it('rejects a role without a key', function (BaseRole $role): void {
    expect(fn () => $role->key())->toThrow(InvalidRoleKeyException::class, 'has no key');
})->with([
    'no attribute' => fn () => new class extends BaseRole
    {
        public function permissions(): array
        {
            return [];
        }
    },
    'attribute without key' => fn () => new #[Role(label: 'Nameless')] class extends BaseRole
    {
        public function permissions(): array
        {
            return [];
        }
    },
]);

it('rejects a key outside the role grammar', function (): void {
    $role = new #[Role('Shop Manager')] class extends BaseRole
    {
        public function permissions(): array
        {
            return [];
        }
    };

    expect(fn () => $role->key())->toThrow(InvalidRoleKeyException::class, 'Invalid role key "Shop Manager"')
        ->and(fn () => $role->label())->toThrow(InvalidRoleKeyException::class);
});

it('checks former keys by the role grammar', function (): void {
    $role = new #[Role('manager')] #[FormerKeys('Old Manager')] class extends BaseRole
    {
        public function permissions(): array
        {
            return [];
        }
    };

    expect(fn () => $role->formerKeys())->toThrow(InvalidRoleKeyException::class, 'Invalid role key "Old Manager"');
});

it('rejects the current key among former keys', function (): void {
    $role = new #[Role('manager')] #[FormerKeys('shop-manager', 'manager')] class extends BaseRole
    {
        public function permissions(): array
        {
            return [];
        }
    };

    expect(fn () => $role->formerKeys())->toThrow(InvalidRoleKeyException::class, 'current key "manager"');
});

it('applies an automatic role by its code rule', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    $root = new RootRole;

    expect($root)->toBeInstanceOf(GrantedAutomatically::class)
        ->and($root->appliesTo((new User)->forceFill(['is_root' => true]), $scope))->toBeTrue()
        ->and($root->appliesTo((new User)->forceFill(['is_root' => false]), $scope))->toBeFalse();
});

it('defines a role only by its class: no model, field or factory methods', function (): void {
    $methods = array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(BaseRole::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($methods);

    expect($methods)->toBe([
        'formerKeys', 'grantable', 'key', 'label', 'level', 'permissions', 'scopeRequired', 'scopes', 'superAdmin',
    ])->and((new ReflectionClass(BaseRole::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionMethod(BaseRole::class, 'permissions'))->isAbstract())->toBeTrue();
});
