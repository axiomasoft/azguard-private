<?php

declare(strict_types=1);

namespace App\Guards\Shop\Roles;

use App\Guards\Shop\Permissions\OrderPermission;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use Illuminate\Database\Eloquent\Model;

/** Managers, recognised by their e-mail address, also refund orders. */
#[Role('manager')]
#[NotGrantable]
final class ManagerRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [OrderPermission::View, OrderPermission::Refund];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return str_starts_with((string) $subject->getAttribute('email'), 'manager@');
    }
}
