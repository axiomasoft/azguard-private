<?php

declare(strict_types=1);

namespace AzGuard\Errors;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;

/**
 * Problem definitions for every {@see AzGuardErrorCode} wire failure.
 */
#[Api(since: '1.0')]
final readonly class AzGuardProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    /** @return iterable<ProblemDefinition> */
    public function definitions(): iterable
    {
        foreach (AzGuardErrorCode::cases() as $code) {
            yield new ProblemDefinition(
                code: $code,
                type: $this->urnPrefix.$code->value,
                defaultStatus: self::statusFor($code),
                title: self::titleFor($code),
                retryable: $code->isRetryable(),
                category: $code->category(),
            );
        }
    }

    private static function statusFor(AzGuardErrorCode $code): int
    {
        return match ($code) {
            AzGuardErrorCode::Consistency,
            AzGuardErrorCode::RecursionDetected => 503,
            AzGuardErrorCode::DecisionSetTooLarge => 429,
            AzGuardErrorCode::MissingPermissionCheck,
            AzGuardErrorCode::PanelNotWritable,
            AzGuardErrorCode::PermissionNotGrantable,
            AzGuardErrorCode::RoleNotGrantable => 403,
            AzGuardErrorCode::PanelNotResolved,
            AzGuardErrorCode::UnknownPanel,
            AzGuardErrorCode::UnknownPermission,
            AzGuardErrorCode::UnknownRole,
            AzGuardErrorCode::UnknownSource => 404,
            AzGuardErrorCode::AmbiguousPanel,
            AzGuardErrorCode::ChangeCancelled,
            AzGuardErrorCode::ConflictingPanel,
            AzGuardErrorCode::DefaultPanelConflict,
            AzGuardErrorCode::DuplicatePanel,
            AzGuardErrorCode::DuplicatePermission,
            AzGuardErrorCode::DuplicatePolicyBinding,
            AzGuardErrorCode::DuplicateRole,
            AzGuardErrorCode::PluginConflict,
            AzGuardErrorCode::PrefixConflict,
            AzGuardErrorCode::RegistryFrozen,
            AzGuardErrorCode::StaleSelection,
            AzGuardErrorCode::StorageMismatch,
            AzGuardErrorCode::WriterConflict => 409,
            AzGuardErrorCode::UnsupportedDirectWrite,
            AzGuardErrorCode::VisibilityNotSupported => 422,
            default => 422,
        };
    }

    private static function titleFor(AzGuardErrorCode $code): string
    {
        return match ($code) {
            AzGuardErrorCode::AmbiguousPanel => 'Ambiguous panel resolution',
            AzGuardErrorCode::AssignmentScopeNotAccepted => 'Assignment scope not accepted',
            AzGuardErrorCode::AssignmentScopeRequired => 'Assignment scope required',
            AzGuardErrorCode::ChangeCancelled => 'Authority change cancelled',
            AzGuardErrorCode::ConflictingPanel => 'Conflicting panel reference',
            AzGuardErrorCode::Consistency => 'Authority read consistency unavailable',
            AzGuardErrorCode::DecisionSetTooLarge => 'Decision set subject limit exceeded',
            AzGuardErrorCode::DefaultPanelConflict => 'Conflicting default panels for subject model',
            AzGuardErrorCode::Definition => 'Invalid AzGuard definition',
            AzGuardErrorCode::DirectoryScanLimit => 'Directory scan limit exceeded',
            AzGuardErrorCode::DuplicatePanel => 'Duplicate panel identifier',
            AzGuardErrorCode::DuplicatePermission => 'Duplicate permission key',
            AzGuardErrorCode::DuplicatePolicyBinding => 'Duplicate policy binding',
            AzGuardErrorCode::DuplicateRole => 'Duplicate role key',
            AzGuardErrorCode::InvalidAssignmentScope => 'Invalid assignment scope reference',
            AzGuardErrorCode::InvalidChangeFields => 'Invalid grant change fields',
            AzGuardErrorCode::InvalidConfiguration => 'Invalid AzGuard configuration',
            AzGuardErrorCode::InvalidIdentity => 'Invalid AzGuard identity reference',
            AzGuardErrorCode::InvalidPanelId => 'Invalid panel identifier',
            AzGuardErrorCode::InvalidPermissionKey => 'Invalid permission key',
            AzGuardErrorCode::InvalidPolicyStructure => 'Invalid policy structure',
            AzGuardErrorCode::InvalidRoleKey => 'Invalid role key',
            AzGuardErrorCode::InvalidSourceContribution => 'Invalid source contribution',
            AzGuardErrorCode::MissingPermissionCheck => 'Missing controller permission check',
            AzGuardErrorCode::PanelNotResolved => 'Panel could not be resolved',
            AzGuardErrorCode::PanelNotWritable => 'Panel is read-only',
            AzGuardErrorCode::PermissionNotGrantable => 'Permission is not grantable',
            AzGuardErrorCode::PluginConflict => 'Conflicting plugin configuration',
            AzGuardErrorCode::PrefixConflict => 'Conflicting permission prefix',
            AzGuardErrorCode::RecursionDetected => 'Authorization evaluation recursion detected',
            AzGuardErrorCode::RegistryFrozen => 'Panel registry is already frozen',
            AzGuardErrorCode::RoleNotGrantable => 'Role is not grantable',
            AzGuardErrorCode::StaleSelection => 'Stale panel catalog selection',
            AzGuardErrorCode::StorageMismatch => 'Storage schema or model mismatch',
            AzGuardErrorCode::SubjectNotAccepted => 'Subject model not accepted by panel',
            AzGuardErrorCode::TenantMismatch => 'Tenant mismatch for operation',
            AzGuardErrorCode::TenantRequired => 'Tenant is required for panel operation',
            AzGuardErrorCode::UnknownPanel => 'Unknown AzGuard panel',
            AzGuardErrorCode::UnknownPermission => 'Unknown permission key',
            AzGuardErrorCode::UnknownRole => 'Unknown role key',
            AzGuardErrorCode::UnknownSource => 'Unknown permission source',
            AzGuardErrorCode::UnsupportedDirectWrite => 'Direct write to AzGuard storage model forbidden',
            AzGuardErrorCode::VisibilityNotSupported => 'Exact visibility filtering unsupported',
            AzGuardErrorCode::WriterConflict => 'Conflicting panel writer sources',
        };
    }
}
