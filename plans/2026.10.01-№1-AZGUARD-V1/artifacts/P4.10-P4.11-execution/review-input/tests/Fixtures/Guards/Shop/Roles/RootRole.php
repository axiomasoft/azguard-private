<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Roles;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use Illuminate\Database\Eloquent\Model;

#[Role('root')]
#[SuperAdmin]
#[NotGrantable]
final class RootRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return (bool) $subject->getAttribute('is_root');
    }
}
