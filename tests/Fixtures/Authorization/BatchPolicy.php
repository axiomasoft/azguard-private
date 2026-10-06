<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Panels\User;

final class BatchPolicy
{
    public static int $calls = 0;

    #[Decides('documents.view')]
    public function view(?User $user, ?object $resource, EvaluationContext $context): bool
    {
        self::$calls++;

        return true;
    }
}
