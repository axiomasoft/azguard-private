<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

/**
 * Why a decision could not be computed: the closed taxonomy of failures (audits/2026-10-09-consistency-design.md,
 * step 6). A failure is never a policy outcome: it denies, but it does not say the subject lacks the permission.
 *
 * - `Transient`: authority could not be read now (`source_error`, `consistency_error`). The same check may succeed
 *   later; HTTP adapters answer 503.
 * - `Contract`: host code broke its contract (`policy_error`, `hook_error`, `restriction_error`, `condition_error`,
 *   `context_filter_error`). Retrying does not help; HTTP adapters answer 500.
 */
enum FailureKind: string
{
    case Transient = 'transient';
    case Contract = 'contract';

    public static function of(DecisionReason $reason): ?self
    {
        return match ($reason) {
            DecisionReason::SourceError, DecisionReason::ConsistencyError => self::Transient,
            DecisionReason::PolicyError, DecisionReason::HookError, DecisionReason::RestrictionError,
            DecisionReason::ConditionError, DecisionReason::AssignmentScopeFilterError => self::Contract,
            default => null,
        };
    }
}
