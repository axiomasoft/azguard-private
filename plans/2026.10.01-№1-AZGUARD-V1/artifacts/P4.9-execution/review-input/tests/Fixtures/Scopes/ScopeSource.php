<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

final class ScopeSource extends GeneratedSource
{
    public array $directScopes = [];

    public array $roleScopes = [];

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantReads++;
        $this->directScopes = $scopes;

        foreach ($this->direct as $grant) {
            if (array_any($scopes, fn (AccessScope $scope): bool => $scope->equals($grant->scope))) {
                yield $grant;
            }
        }
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->roleReads++;
        $this->roleScopes = $scopes;

        foreach ($this->roles as $grant) {
            if (array_any($scopes, fn (AccessScope $scope): bool => $scope->equals($grant->scope))) {
                yield $grant;
            }
        }
    }
}
