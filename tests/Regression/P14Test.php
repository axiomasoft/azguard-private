<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;

it('rejects bare wildcards as a permission key and as a grant pattern', function (string $wildcard): void {
    expect(PermissionGrammar::isLocalKey($wildcard))->toBeFalse()
        ->and(PermissionGrammar::isPattern($wildcard))->toBeFalse()
        ->and(PermissionGrammar::isFullKey('admin:'.$wildcard))->toBeFalse()
        ->and(fn () => PermissionGrammar::assertLocalKey($wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PermissionGrammar::assertPattern($wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PermissionGrammar::splitFull('admin:'.$wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PatternMatcher::covers($wildcard, 'orders.view'))->toThrow(InvalidPermissionKeyException::class);
})->with(['*', '**']);

it('P14 refuses a direct grant of a bare wildcard through $user->guard(\'admin\'); a super-admin role is the only way', function (): void {
    SubjectWorld::seed();
    SubjectWorld::compile();
    $user = SubjectWorld::member();

    expect(fn () => $user->guard('admin')->grantPermission('*'))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => $user->guard('admin')->grantPermission('**'))->toThrow(InvalidPermissionKeyException::class)
        ->and($user->guard('admin')->permissionGrants())->toBeEmpty()
        ->and($user->guard('admin')->isSuperAdmin())->toBeFalse();

    $user->guard('admin')->grantRole('root');

    expect($user->guard('admin')->isSuperAdmin())->toBeTrue()
        ->and($user->guard('admin')->hasPermission('users.delete'))->toBeTrue();
});
