<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

trait AuthorizesPermission
{
    abstract protected function panelId(): string;

    protected function allows(BackedEnum $permission, Authenticatable $user): bool
    {
        if (! method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission(
            permission: $this->resolvePermission(permission: $permission),
            panelId: $this->panelId(),
        );
    }

    protected function resolvePermission(BackedEnum $permission): string
    {
        return $this->panel()->resolvePermission(permission: $permission);
    }

    protected function panel(): Panel
    {
        return AzGuard::panel(id: $this->panelId())
            ?? throw new RuntimeException("AzGuard panel [{$this->panelId()}] is not registered.");
    }
}
