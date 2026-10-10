<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders\Policies;

use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use AzGuard\Tests\Fixtures\Guards\Orders\Clock;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use Illuminate\Database\Eloquent\Model;

#[PolicyFor(OrderPermission::class)]
final class WorkingHoursPolicy
{
    public static mixed $result = true;

    public static mixed $beforeResult = null;

    public static int $calls = 0;

    public function before(Model $user, string $ability): mixed
    {
        return self::$beforeResult;
    }

    #[Decides(OrderPermission::Refund)]
    public function available(Model $user, Clock $clock): mixed
    {
        self::$calls++;

        return self::$result;
    }
}
