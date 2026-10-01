<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Contracts\RoleInterface;
use Illuminate\Support\Str;
use Override;
use UnitEnum;

abstract class BaseRole implements RoleInterface
{
    #[Override]
    public function getName(): string
    {
        return (string) Str::of(class_basename(static::class))
            ->replaceLast('Role', '')
            ->snake()
            ->slug();
    }

    #[Override]
    public function getLevel(): int
    {
        return 0;
    }

    /**
     * Permissions granted by this role.
     *
     * Prefer enum cases — they are refactor-safe and scoped to their panel
     * automatically (no "{panel}." prefix needed). String keys (the full
     * panel-prefixed form) and `[\AzGuard\Permissions\PermissionKey::WILDCARD]` for
     * super-admin are also accepted.
     *
     * @return list<UnitEnum|string>
     */
    #[Override]
    abstract public function permissions(): array;
}
