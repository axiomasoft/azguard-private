<?php

declare(strict_types=1);

use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\AssignmentScopeRequiredException;
use AzGuard\Exceptions\AuthorizationEngineException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\ChangeException;
use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePanelException;
use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\PermissionNotGrantableException;
use AzGuard\Exceptions\PluginException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\RoleNotGrantableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\StorageException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnknownRoleException;

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
    ChangeException::class,
    AuthorizationEngineException::class,
    StorageException::class,
    PluginException::class,
]);

it('gives a concrete exception its parent and stable code', function (string $class, string $parent, string $code): void {
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
    [ConsistencyException::class, AuthorizationEngineException::class, 'consistency'],
    [InvalidSourceContributionException::class, AuthorizationEngineException::class, 'invalid_source_contribution'],
    [DefinitionException::class, AzGuardException::class, 'definition'],
    [DuplicatePanelException::class, DefinitionException::class, 'duplicate_panel'],
    [UnknownPanelException::class, DefinitionException::class, 'unknown_panel'],
    [RegistryFrozenException::class, DefinitionException::class, 'registry_frozen'],
    [DefaultPanelConflictException::class, DefinitionException::class, 'default_panel_conflict'],
    [SubjectNotAcceptedException::class, DefinitionException::class, 'subject_not_accepted'],
    [InvalidConfigurationException::class, ConfigurationException::class, 'invalid_configuration'],
    [UnknownRoleException::class, ChangeException::class, 'unknown_role'],
    [RoleNotGrantableException::class, ChangeException::class, 'role_not_grantable'],
    [PermissionNotGrantableException::class, ChangeException::class, 'permission_not_grantable'],
    [PanelNotWritableException::class, ChangeException::class, 'panel_not_writable'],
    [AssignmentScopeNotAcceptedException::class, ChangeException::class, 'context_not_accepted'],
    [AssignmentScopeRequiredException::class, ChangeException::class, 'context_required'],
    [TenantRequiredException::class, ChangeException::class, 'tenant_required'],
    [TenantMismatchException::class, ChangeException::class, 'tenant_mismatch'],
    [ChangeCancelledException::class, ChangeException::class, 'change_cancelled'],
    [StaleSelectionException::class, ChangeException::class, 'stale_selection'],
]);

it('throws the definition branch itself where no narrower class exists', function (): void {
    $reflection = new ReflectionClass(DefinitionException::class);

    expect($reflection->isAbstract())->toBeFalse()
        ->and($reflection->isFinal())->toBeFalse();
});

it('keeps leaf exceptions final', function (string $class): void {
    expect((new ReflectionClass($class))->isFinal())->toBeTrue();
})->with([
    ConsistencyException::class, InvalidSourceContributionException::class, DuplicatePanelException::class,
    UnknownPanelException::class, RegistryFrozenException::class, DefaultPanelConflictException::class,
    SubjectNotAcceptedException::class, InvalidConfigurationException::class,
    UnknownRoleException::class, RoleNotGrantableException::class, PermissionNotGrantableException::class, PanelNotWritableException::class, AssignmentScopeNotAcceptedException::class, AssignmentScopeRequiredException::class, TenantRequiredException::class, TenantMismatchException::class, ChangeCancelledException::class, StaleSelectionException::class,
]);

it('keeps the reason a changing pipe gave for a cancellation', function (): void {
    $error = ChangeCancelledException::because('Укажите причину');

    expect($error->reason())->toBe('Укажите причину')->and($error->getMessage())->toBe('Change cancelled: Укажите причину')
        ->and((new ChangeCancelledException('message'))->reason())->toBeNull();
});
