<?php

declare(strict_types=1);

use AzGuard\Concerns\Subjects\HasAzGuard;
use AzGuard\Concerns\Subjects\SubjectAccess;
use AzGuard\Concerns\Subjects\SubjectPanels;
use AzGuard\Contracts\Panels\PanelAccess;
use AzGuard\Contracts\Sources\EvaluatesAutomaticRoles;
use AzGuard\Contracts\Subjects\AzGuardSubject;
use AzGuard\Events\Access\AccessDecided;
use AzGuard\Events\Access\PanelStateTouched;
use AzGuard\Events\Changes\GrantExpired;
use AzGuard\Events\Changes\PermissionGranted;
use AzGuard\Events\Changes\PermissionGrantUpdated;
use AzGuard\Events\Changes\PermissionRevoked;
use AzGuard\Events\Changes\RoleGranted;
use AzGuard\Events\Changes\RoleGrantUpdated;
use AzGuard\Events\Changes\RoleRevoked;
use AzGuard\Events\Permissions\PermissionCreated;
use AzGuard\Events\Permissions\PermissionDeleted;
use AzGuard\Events\Permissions\PermissionUpdated;
use AzGuard\Exceptions\Authorization\AuthorizationEngineException;
use AzGuard\Exceptions\Authorization\ConsistencyException;
use AzGuard\Exceptions\Authorization\DecisionSetTooLargeException;
use AzGuard\Exceptions\Authorization\InvalidSourceContributionException;
use AzGuard\Exceptions\Authorization\RecursionDetectedException;
use AzGuard\Exceptions\Authorization\VisibilityNotSupportedException;
use AzGuard\Exceptions\Changes\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\Changes\AssignmentScopeRequiredException;
use AzGuard\Exceptions\Changes\ChangeCancelledException;
use AzGuard\Exceptions\Changes\ChangeException;
use AzGuard\Exceptions\Changes\InvalidChangeFieldsException;
use AzGuard\Exceptions\Changes\PanelNotWritableException;
use AzGuard\Exceptions\Changes\PermissionNotGrantableException;
use AzGuard\Exceptions\Changes\RoleNotGrantableException;
use AzGuard\Exceptions\Changes\StaleSelectionException;
use AzGuard\Exceptions\Changes\TenantMismatchException;
use AzGuard\Exceptions\Changes\TenantRequiredException;
use AzGuard\Exceptions\Changes\UnknownPermissionException;
use AzGuard\Exceptions\Changes\UnknownRoleException;
use AzGuard\Exceptions\Configuration\ConfigurationException;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use AzGuard\Exceptions\Configuration\MissingPermissionCheckException;
use AzGuard\Exceptions\Definitions\AmbiguousPanelException;
use AzGuard\Exceptions\Definitions\ConflictingPanelException;
use AzGuard\Exceptions\Definitions\DefaultPanelConflictException;
use AzGuard\Exceptions\Definitions\DefinitionException;
use AzGuard\Exceptions\Definitions\DirectoryScanLimitException;
use AzGuard\Exceptions\Definitions\DuplicatePanelException;
use AzGuard\Exceptions\Definitions\DuplicatePermissionException;
use AzGuard\Exceptions\Definitions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\Definitions\DuplicateRoleException;
use AzGuard\Exceptions\Definitions\InvalidPolicyStructureException;
use AzGuard\Exceptions\Definitions\PanelNotResolvedException;
use AzGuard\Exceptions\Definitions\PrefixConflictException;
use AzGuard\Exceptions\Definitions\RegistryFrozenException;
use AzGuard\Exceptions\Definitions\SubjectNotAcceptedException;
use AzGuard\Exceptions\Definitions\UnknownPanelException;
use AzGuard\Exceptions\Definitions\UnknownSourceException;
use AzGuard\Exceptions\Definitions\WriterConflictException;
use AzGuard\Exceptions\Identity\InvalidAssignmentScopeException;
use AzGuard\Exceptions\Identity\InvalidIdentityException;
use AzGuard\Exceptions\Identity\InvalidPanelIdException;
use AzGuard\Exceptions\Identity\InvalidPermissionKeyException;
use AzGuard\Exceptions\Identity\InvalidRoleKeyException;
use AzGuard\Exceptions\Plugins\PluginConflictException;
use AzGuard\Exceptions\Plugins\PluginException;
use AzGuard\Exceptions\Storage\StorageException;
use AzGuard\Exceptions\Storage\StorageMismatchException;
use AzGuard\Exceptions\Storage\UnsupportedDirectWriteException;
use Packman\Attributes\Api;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

// Backwards-compatible aliases for moved/replaced core symbols used by un-migrated modules and downstream callers during Slice A.

foreach ([
    Api::class => 'AzGuard\Attributes\Api',
    Spi::class => 'AzGuard\Attributes\Spi',
    SpiKind::class => 'AzGuard\Attributes\SpiKind',
    AuthorizationEngineException::class => 'AzGuard\Exceptions\AuthorizationEngineException',
    ConsistencyException::class => 'AzGuard\Exceptions\ConsistencyException',
    DecisionSetTooLargeException::class => 'AzGuard\Exceptions\DecisionSetTooLargeException',
    InvalidSourceContributionException::class => 'AzGuard\Exceptions\InvalidSourceContributionException',
    RecursionDetectedException::class => 'AzGuard\Exceptions\RecursionDetectedException',
    VisibilityNotSupportedException::class => 'AzGuard\Exceptions\VisibilityNotSupportedException',
    ChangeException::class => 'AzGuard\Exceptions\ChangeException',
    AssignmentScopeNotAcceptedException::class => 'AzGuard\Exceptions\AssignmentScopeNotAcceptedException',
    AssignmentScopeRequiredException::class => 'AzGuard\Exceptions\AssignmentScopeRequiredException',
    ChangeCancelledException::class => 'AzGuard\Exceptions\ChangeCancelledException',
    InvalidChangeFieldsException::class => 'AzGuard\Exceptions\InvalidChangeFieldsException',
    PanelNotWritableException::class => 'AzGuard\Exceptions\PanelNotWritableException',
    PermissionNotGrantableException::class => 'AzGuard\Exceptions\PermissionNotGrantableException',
    RoleNotGrantableException::class => 'AzGuard\Exceptions\RoleNotGrantableException',
    StaleSelectionException::class => 'AzGuard\Exceptions\StaleSelectionException',
    TenantMismatchException::class => 'AzGuard\Exceptions\TenantMismatchException',
    TenantRequiredException::class => 'AzGuard\Exceptions\TenantRequiredException',
    UnknownPermissionException::class => 'AzGuard\Exceptions\UnknownPermissionException',
    UnknownRoleException::class => 'AzGuard\Exceptions\UnknownRoleException',
    ConfigurationException::class => 'AzGuard\Exceptions\ConfigurationException',
    InvalidConfigurationException::class => 'AzGuard\Exceptions\InvalidConfigurationException',
    MissingPermissionCheckException::class => 'AzGuard\Exceptions\MissingPermissionCheckException',
    DefinitionException::class => 'AzGuard\Exceptions\DefinitionException',
    AmbiguousPanelException::class => 'AzGuard\Exceptions\AmbiguousPanelException',
    ConflictingPanelException::class => 'AzGuard\Exceptions\ConflictingPanelException',
    DefaultPanelConflictException::class => 'AzGuard\Exceptions\DefaultPanelConflictException',
    DirectoryScanLimitException::class => 'AzGuard\Exceptions\DirectoryScanLimitException',
    DuplicatePanelException::class => 'AzGuard\Exceptions\DuplicatePanelException',
    DuplicatePermissionException::class => 'AzGuard\Exceptions\DuplicatePermissionException',
    DuplicatePolicyBindingException::class => 'AzGuard\Exceptions\DuplicatePolicyBindingException',
    DuplicateRoleException::class => 'AzGuard\Exceptions\DuplicateRoleException',
    InvalidPolicyStructureException::class => 'AzGuard\Exceptions\InvalidPolicyStructureException',
    PanelNotResolvedException::class => 'AzGuard\Exceptions\PanelNotResolvedException',
    PrefixConflictException::class => 'AzGuard\Exceptions\PrefixConflictException',
    RegistryFrozenException::class => 'AzGuard\Exceptions\RegistryFrozenException',
    SubjectNotAcceptedException::class => 'AzGuard\Exceptions\SubjectNotAcceptedException',
    UnknownPanelException::class => 'AzGuard\Exceptions\UnknownPanelException',
    UnknownSourceException::class => 'AzGuard\Exceptions\UnknownSourceException',
    WriterConflictException::class => 'AzGuard\Exceptions\WriterConflictException',
    InvalidIdentityException::class => 'AzGuard\Exceptions\InvalidIdentityException',
    InvalidAssignmentScopeException::class => 'AzGuard\Exceptions\InvalidAssignmentScopeException',
    InvalidPanelIdException::class => 'AzGuard\Exceptions\InvalidPanelIdException',
    InvalidPermissionKeyException::class => 'AzGuard\Exceptions\InvalidPermissionKeyException',
    InvalidRoleKeyException::class => 'AzGuard\Exceptions\InvalidRoleKeyException',
    PluginException::class => 'AzGuard\Exceptions\PluginException',
    PluginConflictException::class => 'AzGuard\Exceptions\PluginConflictException',
    StorageException::class => 'AzGuard\Exceptions\StorageException',
    StorageMismatchException::class => 'AzGuard\Exceptions\StorageMismatchException',
    UnsupportedDirectWriteException::class => 'AzGuard\Exceptions\UnsupportedDirectWriteException',
    AccessDecided::class => 'AzGuard\Events\AccessDecided',
    PanelStateTouched::class => 'AzGuard\Events\PanelStateTouched',
    GrantExpired::class => 'AzGuard\Events\GrantExpired',
    PermissionGranted::class => 'AzGuard\Events\PermissionGranted',
    PermissionGrantUpdated::class => 'AzGuard\Events\PermissionGrantUpdated',
    PermissionRevoked::class => 'AzGuard\Events\PermissionRevoked',
    RoleGranted::class => 'AzGuard\Events\RoleGranted',
    RoleGrantUpdated::class => 'AzGuard\Events\RoleGrantUpdated',
    RoleRevoked::class => 'AzGuard\Events\RoleRevoked',
    PermissionCreated::class => 'AzGuard\Events\PermissionCreated',
    PermissionDeleted::class => 'AzGuard\Events\PermissionDeleted',
    PermissionUpdated::class => 'AzGuard\Events\PermissionUpdated',
    AzGuardSubject::class => 'AzGuard\Contracts\AzGuardSubject',
    PanelAccess::class => 'AzGuard\Contracts\PanelAccess',
    HasAzGuard::class => 'AzGuard\Concerns\HasAzGuard',
    SubjectAccess::class => 'AzGuard\Concerns\SubjectAccess',
    SubjectPanels::class => 'AzGuard\Concerns\SubjectPanels',
    EvaluatesAutomaticRoles::class => 'AzGuard\Sources\Internal\EvaluatesAutomaticRoles',
] as $target => $alias) {
    if (! class_exists($alias, false) && ! interface_exists($alias, false) && ! enum_exists($alias, false) && ! trait_exists($alias, false)) {
        class_alias($target, $alias);
    }
}
