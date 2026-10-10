<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

final readonly class TokenAbilitiesRestriction implements Restriction
{
    public function __construct(private array $abilities) {}

    public function key(): string
    {
        return 'token-cap';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return true;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return in_array($request->permission()->local(), $this->abilities, true) ? RestrictionResult::pass() : RestrictionResult::deny('token_cap');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
