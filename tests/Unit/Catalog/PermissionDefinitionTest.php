<?php

declare(strict_types=1);

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Exceptions\WriterConflictException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Panels\NumericPermission;
use AzGuard\Tests\Fixtures\Panels\PlainMarker;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;
use AzGuard\Tests\Fixtures\Permissions\ClientPolicy;

it('checks the local name of a definition by the permission grammar', function (string $local): void {
    expect(fn () => new PermissionDefinition($local, PermissionAuthority::Grants))->toThrow(InvalidPermissionKeyException::class);
})->with(['view', 'orders.*', 'Orders.View', 'admin:orders.view']);

it('compares definitions by every field', function (): void {
    $base = new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'clients', 'Read', ClientPermission::View, User::class);

    expect($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'clients', 'Read', ClientPermission::View, User::class)))->toBeTrue()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Policy, 'View', 'clients', 'Read', ClientPermission::View, User::class)))->toBeFalse()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'Show', 'clients', 'Read', ClientPermission::View, User::class)))->toBeFalse()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'crm', 'Read', ClientPermission::View, User::class)))->toBeFalse()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'clients', null, ClientPermission::View, User::class)))->toBeFalse()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'clients', 'Read', ClientPermission::Update, User::class)))->toBeFalse()
        ->and($base->equals(new PermissionDefinition('clients.view', PermissionAuthority::Grants, 'View', 'clients', 'Read', ClientPermission::View)))->toBeFalse();
});

it('binds an enum case or a local name to an existing policy class', function (): void {
    $byCase = PolicyBinding::for(ClientPermission::Update, ClientPolicy::class);
    $byName = PolicyBinding::for('clients.update', '\\'.ClientPolicy::class);

    expect($byCase->permission)->toBe(ClientPermission::Update)
        ->and($byCase->policy)->toBe(ClientPolicy::class)
        ->and($byName->permission)->toBe('clients.update')
        ->and($byName->policy)->toBe(ClientPolicy::class);
});

it('rejects a binding that names no permission or no policy class', function (Closure $binding, string $message): void {
    expect($binding)->toThrow(DefinitionException::class, $message);
})->with([
    'a malformed name' => [fn () => PolicyBinding::for('update', ClientPolicy::class), 'local permission name'],
    'a pattern' => [fn () => PolicyBinding::for('clients.*', ClientPolicy::class), 'local permission name'],
    'an int-backed enum' => [fn () => PolicyBinding::for(NumericPermission::cases()[0], ClientPolicy::class), 'string-backed'],
    'a pure enum' => [fn () => PolicyBinding::for(PlainMarker::cases()[0], ClientPolicy::class), 'string-backed'],
    'a missing class' => [fn () => PolicyBinding::for(ClientPermission::Update, 'App\\Policies\\Missing'), 'not an existing class'],
]);

it('gives every catalog error its definition parent and stable code', function (string $class, string $code): void {
    $exception = new $class('message');

    expect(get_parent_class($exception))->toBe(DefinitionException::class)
        ->and($exception->code())->toBe($code)
        ->and((new ReflectionClass($class))->isFinal())->toBeTrue();
})->with([
    [DuplicatePermissionException::class, 'duplicate_permission'],
    [DuplicateRoleException::class, 'duplicate_role'],
    [DuplicatePolicyBindingException::class, 'duplicate_policy_binding'],
    [InvalidPolicyStructureException::class, 'invalid_policy_structure'],
    [UnknownSourceException::class, 'unknown_source'],
    [WriterConflictException::class, 'writer_conflict'],
]);
