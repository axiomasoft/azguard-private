<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A `decideMany()` names more distinct subjects than `decision_sets.max_subjects`. The set is refused before any hook
 * runs or any source is read; it is never split silently, because separate chunks are separate snapshots and would
 * lose the one-state guarantee of the set.
 */
final class DecisionSetTooLargeException extends AuthorizationEngineException
{
    public function __construct(public readonly int $subjects, public readonly int $limit)
    {
        parent::__construct("A DecisionSet of {$subjects} distinct subjects exceeds decision_sets.max_subjects ({$limit}); split it explicitly and accept one snapshot per chunk, or raise the limit.");
    }

    public function code(): string
    {
        return 'decision_set_too_large';
    }
}
