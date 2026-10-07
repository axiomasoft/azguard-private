<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;

/** A source of role grants only, configured with a secret that must stay out of every description. */
final readonly class HrRoleSource implements ProvidesRoleGrants
{
    public const PASSWORD = 'hr-bind-password-77aa';

    public function __construct(private string $password) {}

    public function id(): string
    {
        return 'hr';
    }

    public function roleGrants(mixed $subject, array $scopes, mixed $context): iterable
    {
        return $this->password === '' ? [] : [];
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }
}
