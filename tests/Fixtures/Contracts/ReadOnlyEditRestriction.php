<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/** Denies every permission that edits, whatever is granted; passes the rest. */
class ReadOnlyEditRestriction implements Restriction
{
    public function key(): string
    {
        return 'read_only_edit';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return str_ends_with($request->permission()->local(), '.edit');
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return RestrictionResult::deny('read_only');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
