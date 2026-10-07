<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

/**
 * Why a decision has its effect; the value is the stable machine code of the reason.
 */
enum DecisionReason: string
{
    case Granted = 'granted';
    case SuperAdmin = 'super_admin';
    case Hook = 'hook';
    case Policy = 'policy';
    case NotGranted = 'not_granted';
    case NotApplicable = 'not_applicable';
    case AssignmentScopeRequired = 'context_required';
    case AssignmentScopeNotAccepted = 'context_not_accepted';
    case AssignmentScopeIneligible = 'context_ineligible';
    case AssignmentScopeFilterError = 'context_filter_error';
    case Restricted = 'restricted';
    case SourceError = 'source_error';
    case PolicyError = 'policy_error';
    case RestrictionError = 'restriction_error';
    case HookError = 'hook_error';
    case TenantRequired = 'tenant_required';
    case TenantMismatch = 'tenant_mismatch';
    case AssignmentScopeMismatch = 'context_mismatch';
    case ResourceScopeMissing = 'resource_scope_missing';
    case ConditionError = 'condition_error';
    case ConsistencyError = 'consistency_error';
}
