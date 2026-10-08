<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use Illuminate\Database\Eloquent\Model;

/** A code role every listed user holds in tenant A; nothing is stored. */
#[Role('member')]
#[NotGrantable]
final class HttpMemberRole extends BaseRole implements GrantedAutomatically
{
    /** @var list<int> */
    public static array $users = [];

    public function permissions(): array
    {
        return [ClientPermission::ViewAny];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return in_array($subject->getKey(), self::$users, true) && $scope->tenant->id() === '1';
    }
}
