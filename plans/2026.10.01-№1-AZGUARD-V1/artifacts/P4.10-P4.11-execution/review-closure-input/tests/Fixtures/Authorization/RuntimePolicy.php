<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;

final class RuntimePolicy
{
    public static mixed $result = true;

    public static ?Closure $callback = null;

    public static int $calls = 0;

    #[Decides('orders.policy')]
    public function business(?User $person, ?object $record, EvaluationContext $context): mixed
    {
        self::$calls++;

        return self::$callback === null ? self::$result : (self::$callback)($person, $record, $context);
    }

    #[Decides('orders.view')]
    public function veto(?User $person, ?object $record): mixed
    {
        self::$calls++;

        return self::$result;
    }
}
