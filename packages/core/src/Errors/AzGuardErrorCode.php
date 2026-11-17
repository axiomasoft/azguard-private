<?php

declare(strict_types=1);

namespace AzGuard\Errors;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Stable wire error codes for every AzGuard domain failure, prefixed with `azguard.`.
 *
 * @open
 */
#[Api(since: '1.0')]
enum AzGuardErrorCode: string implements ErrorCode
{
    case AmbiguousPanel = 'azguard.ambiguous_panel';
    case AssignmentScopeNotAccepted = 'azguard.context_not_accepted';
    case AssignmentScopeRequired = 'azguard.context_required';
    case ChangeCancelled = 'azguard.change_cancelled';
    case ConflictingPanel = 'azguard.conflicting_panel';
    case Consistency = 'azguard.consistency';
    case DecisionSetTooLarge = 'azguard.decision_set_too_large';
    case DefaultPanelConflict = 'azguard.default_panel_conflict';
    case Definition = 'azguard.definition';
    case DirectoryScanLimit = 'azguard.directory_scan_limit';
    case DuplicatePanel = 'azguard.duplicate_panel';
    case DuplicatePermission = 'azguard.duplicate_permission';
    case DuplicatePolicyBinding = 'azguard.duplicate_policy_binding';
    case DuplicateRole = 'azguard.duplicate_role';
    case InvalidAssignmentScope = 'azguard.invalid_context';
    case InvalidChangeFields = 'azguard.invalid_change_fields';
    case InvalidConfiguration = 'azguard.invalid_configuration';
    case InvalidIdentity = 'azguard.invalid_identity';
    case InvalidPanelId = 'azguard.invalid_panel_id';
    case InvalidPermissionKey = 'azguard.invalid_permission_key';
    case InvalidPolicyStructure = 'azguard.invalid_policy_structure';
    case InvalidRoleKey = 'azguard.invalid_role_key';
    case InvalidSourceContribution = 'azguard.invalid_source_contribution';
    case MissingPermissionCheck = 'azguard.missing_permission_check';
    case PanelNotResolved = 'azguard.panel_not_resolved';
    case PanelNotWritable = 'azguard.panel_not_writable';
    case PermissionNotGrantable = 'azguard.permission_not_grantable';
    case PluginConflict = 'azguard.plugin_conflict';
    case PrefixConflict = 'azguard.prefix_conflict';
    case RecursionDetected = 'azguard.recursion_detected';
    case RegistryFrozen = 'azguard.registry_frozen';
    case RoleNotGrantable = 'azguard.role_not_grantable';
    case StaleSelection = 'azguard.stale_selection';
    case StorageMismatch = 'azguard.storage_mismatch';
    case SubjectNotAccepted = 'azguard.subject_not_accepted';
    case TenantMismatch = 'azguard.tenant_mismatch';
    case TenantRequired = 'azguard.tenant_required';
    case UnknownPanel = 'azguard.unknown_panel';
    case UnknownPermission = 'azguard.unknown_permission';
    case UnknownRole = 'azguard.unknown_role';
    case UnknownSource = 'azguard.unknown_source';
    case UnsupportedDirectWrite = 'azguard.unsupported_direct_write';
    case VisibilityNotSupported = 'azguard.visibility_not_supported';
    case WriterConflict = 'azguard.writer_conflict';

    public function value(): string
    {
        return $this->value;
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::Consistency,
            self::RecursionDetected => ErrorCategory::Unavailable,
            self::DecisionSetTooLarge => ErrorCategory::RateLimit,
            self::VisibilityNotSupported,
            self::UnsupportedDirectWrite => ErrorCategory::Unsupported,
            self::PanelNotResolved,
            self::UnknownPanel,
            self::UnknownPermission,
            self::UnknownRole,
            self::UnknownSource => ErrorCategory::NotFound,
            self::MissingPermissionCheck,
            self::PanelNotWritable,
            self::PermissionNotGrantable,
            self::RoleNotGrantable => ErrorCategory::Authorization,
            self::AmbiguousPanel,
            self::ChangeCancelled,
            self::ConflictingPanel,
            self::DefaultPanelConflict,
            self::DuplicatePanel,
            self::DuplicatePermission,
            self::DuplicatePolicyBinding,
            self::DuplicateRole,
            self::PluginConflict,
            self::PrefixConflict,
            self::RegistryFrozen,
            self::StaleSelection,
            self::StorageMismatch,
            self::WriterConflict => ErrorCategory::Conflict,
            self::AssignmentScopeNotAccepted,
            self::AssignmentScopeRequired,
            self::Definition,
            self::DirectoryScanLimit,
            self::InvalidAssignmentScope,
            self::InvalidChangeFields,
            self::InvalidConfiguration,
            self::InvalidIdentity,
            self::InvalidPanelId,
            self::InvalidPermissionKey,
            self::InvalidPolicyStructure,
            self::InvalidRoleKey,
            self::InvalidSourceContribution,
            self::SubjectNotAccepted,
            self::TenantMismatch,
            self::TenantRequired => ErrorCategory::Validation,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Consistency,
            self::RecursionDetected,
            self::StaleSelection => true,
            default => false,
        };
    }

    #[Internal('Maps legacy short exception codes (including dotted configuration checks) onto AzGuardErrorCode.')]
    public static function descriptorFor(string $machineCode): ErrorDescriptor
    {
        $dot = strpos($machineCode, '.');
        $base = $dot === false ? $machineCode : substr($machineCode, 0, $dot);
        $errorCode = self::tryFrom('azguard.'.$base) ?? self::InvalidConfiguration;
        $details = $dot === false ? [] : ['check' => substr($machineCode, $dot + 1)];

        return new ErrorDescriptor(
            code: $errorCode,
            category: $errorCode->category(),
            retryable: $errorCode->isRetryable(),
            details: $details,
        );
    }
}
