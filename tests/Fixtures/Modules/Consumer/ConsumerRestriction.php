<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/** The consumer's restriction: forbids every permission that edits, whatever is granted. */
final class ConsumerRestriction implements Restriction
{
    public function key(): string
    {
        return 'acme_read_only';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return str_ends_with($request->permission()->local(), '.edit');
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return RestrictionResult::deny('acme_read_only');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
