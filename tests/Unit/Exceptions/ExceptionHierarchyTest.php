<?php

declare(strict_types=1);

use AzGuard\Exceptions\AuthorizationEngineException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\ChangeException;
use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\PluginException;
use AzGuard\Exceptions\StorageException;

it('roots every error in an abstract runtime exception with a machine code', function (): void {
    $base = new ReflectionClass(AzGuardException::class);

    expect($base->isAbstract())->toBeTrue()
        ->and($base->isSubclassOf(RuntimeException::class))->toBeTrue()
        ->and($base->getMethod('code')->isAbstract())->toBeTrue();
});

it('keeps a branch abstract under the base', function (string $branch): void {
    $reflection = new ReflectionClass($branch);

    expect($reflection->isAbstract())->toBeTrue()
        ->and($reflection->getParentClass()->getName())->toBe(AzGuardException::class);
})->with([
    ConfigurationException::class,
    DefinitionException::class,
    ChangeException::class,
    AuthorizationEngineException::class,
    StorageException::class,
    PluginException::class,
]);

it('gives an identity exception its parent and stable code', function (string $class, string $parent, string $code): void {
    $exception = new $class('message');

    expect(get_parent_class($exception))->toBe($parent)
        ->and($exception)->toBeInstanceOf(AzGuardException::class)
        ->and($exception->code())->toBe($code)
        ->and($exception->getMessage())->toBe('message');
})->with([
    [InvalidIdentityException::class, AzGuardException::class, 'invalid_identity'],
    [InvalidPanelIdException::class, InvalidIdentityException::class, 'invalid_panel_id'],
    [InvalidPermissionKeyException::class, InvalidIdentityException::class, 'invalid_permission_key'],
    [InvalidRoleKeyException::class, InvalidIdentityException::class, 'invalid_role_key'],
    [InvalidAssignmentScopeException::class, InvalidIdentityException::class, 'invalid_context'],
]);
