<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use Illuminate\Database\Eloquent\Model;

#[Role('viewer', label: 'Наблюдатель')]
#[NotGrantable]
final class AutomaticViewerRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [ClientPermission::ViewAny];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return false;
    }
}
