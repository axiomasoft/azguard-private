<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders\Policies;

use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use AzGuard\Tests\Fixtures\Guards\Orders\Clock;
use AzGuard\Tests\Fixtures\Guards\Orders\Order;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;

#[PolicyFor(OrderPermission::class)]
final class OrderPolicy
{
    public static mixed $result = true;

    public static mixed $beforeResult = null;

    public static ?Closure $callback = null;

    public static int $calls = 0;

    public static array $arguments = [];

    public static array $beforeArguments = [];

    public static function reset(): void
    {
        self::$result = true;
        self::$beforeResult = null;
        self::$callback = null;
        self::$calls = 0;
        self::$arguments = [];
        self::$beforeArguments = [];
    }

    public function before(User $user, string $ability, Clock $clock): mixed
    {
        self::$beforeArguments = [$user, $ability, $clock];

        return self::$beforeResult;
    }

    #[Decides(OrderPermission::ViewOwn)]
    public function belongsTo(User $user, Order $order, Clock $clock): mixed
    {
        return $this->answer([$user, $order, $clock], (int) $order->getAttribute('user_id') === (int) $user->getKey());
    }

    #[Decides(OrderPermission::ViewAny)]
    #[Decides(OrderPermission::Create)]
    #[Decides(OrderPermission::UserOnly)]
    #[Decides(OrderPermission::NativeBefore)]
    public function userOnly(User $user): mixed
    {
        return $this->answer(func_get_args());
    }

    #[Decides(OrderPermission::SameClass)]
    public function compare(User $user, User $resource): mixed
    {
        return $this->answer([$user, $resource]);
    }

    #[Decides(OrderPermission::Missing)]
    public function needsOrder(User $user, Order $order): mixed
    {
        return $this->answer([$user, $order]);
    }

    #[Decides(OrderPermission::ResourceClass)]
    public function classOrInstance(User $user, Order|string $resource): mixed
    {
        return $this->answer([$user, $resource]);
    }

    #[Decides(OrderPermission::StringClass)]
    public function classOnly(User $user, string $resource): mixed
    {
        return $this->answer([$user, $resource]);
    }

    #[Decides(OrderPermission::Defaults)]
    public function withDefaults(User $user, Clock $clock, ?string $optional = null, string $label = 'default', string ...$tail): mixed
    {
        return $this->answer([$user, $clock, $optional, $label, $tail]);
    }

    private function answer(array $args, mixed $fallback = null): mixed
    {
        self::$calls++;
        self::$arguments = $args;

        return self::$callback !== null ? (self::$callback)(...$args) : ($fallback ?? self::$result);
    }
}
