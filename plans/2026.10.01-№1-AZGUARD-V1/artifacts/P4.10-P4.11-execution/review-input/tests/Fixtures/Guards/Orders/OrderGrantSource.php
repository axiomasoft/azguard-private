<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Identity\SubjectRef;

final class OrderGrantSource implements ProvidesGrants, ProvidesRoleGrants
{
    public int $grantReads = 0;

    public int $roleReads = 0;

    public function __construct(public array $direct = [], public array $roles = [], public ?SubjectRef $onlySubject = null) {}

    public function id(): string
    {
        return 'orders-grants';
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantReads++;

        if ($this->onlySubject === null || $this->onlySubject->equals($subject)) {
            yield from $this->direct;
        }
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->roleReads++;

        if ($this->onlySubject === null || $this->onlySubject->equals($subject)) {
            yield from $this->roles;
        }
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }
}
