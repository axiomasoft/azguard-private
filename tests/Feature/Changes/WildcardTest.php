<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

it('V22 rejects a bare wildcard as a direct permission grant at the write boundary', function (string $wildcard): void {
    $panel = W::panel();
    $before = W::rows('permission');

    expect(fn () => W::pipeline()->permission($panel, $wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(W::rows('permission'))->toBe($before);
})->with(['*', '**', 'crm:*', 'crm:**']);

it('V22 makes a super admin only through a role with the super admin flag', function (): void {
    $panel = W::panel();
    $authorizer = app(Authorizer::class);
    $scope = AccessScope::in(W::tenant(), W::project(1));
    W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('clients.*'), W::project(1));

    expect($authorizer->isSuperAdmin($panel, W::user(2), $scope))->toBeFalse()
        ->and(fn () => W::grant($panel, '*', 2, 1))->toThrow(InvalidRoleKeyException::class)
        ->and(fn () => W::pipeline()->grant($panel, W::tenant(), W::user(2), W::pipeline()->role($panel, 'superadmin'), W::project(1)))->toThrow(UnknownRoleException::class);

    W::grant($panel, 'tenant-admin', 2, 2);
    app()->forgetScopedInstances();

    expect($authorizer->isSuperAdmin($panel, W::user(2), AccessScope::in(W::tenant(), W::project(2))))->toBeTrue();
});
