<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Auth\Access\Response;

final class NativePolicy
{
    public static bool|Response|null $before = null;

    public static bool|Response|null $result = true;

    public static int $calls = 0;

    public static array $seen = [];

    public function before(User $user, string $ability): bool|Response|null
    {
        self::$seen = [$user, $ability];

        return self::$before;
    }

    public function native(User $user, GateRecord $record): bool|Response|null
    {
        self::$calls++;
        self::$seen = [$user, $record];

        return self::$result;
    }
}
