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

/** Every user of the shop views orders. */
#[Role('member')]
#[NotGrantable]
final class MemberRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [OrderPermission::View];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return true;
    }
}
