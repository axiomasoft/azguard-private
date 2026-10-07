<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use Illuminate\Database\Eloquent\Model;

#[Role('external-editor')]
final class ExternalAutomaticRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['shop.edit'];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getKey() === 1;
    }
}
