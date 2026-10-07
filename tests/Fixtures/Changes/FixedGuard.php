<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;

/** An auth guard whose current user is a fixed model, for actor resolution through the panel's subject guard. */
final class FixedGuard implements Guard
{
    public static ?Model $user = null;

    public function check(): bool
    {
        return self::$user !== null;
    }

    public function guest(): bool
    {
        return self::$user === null;
    }

    public function user(): ?Model
    {
        return self::$user;
    }

    public function id(): mixed
    {
        return self::$user?->getKey();
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return self::$user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }
}
