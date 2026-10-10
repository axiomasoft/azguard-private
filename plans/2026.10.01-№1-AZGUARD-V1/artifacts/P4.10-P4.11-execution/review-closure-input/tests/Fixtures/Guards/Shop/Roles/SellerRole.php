<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Roles;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Guards\Shop\Permissions\ShopPermission;
use AzGuard\Tests\Fixtures\Guards\Shop\Store;
use Illuminate\Database\Eloquent\Model;

#[Role('seller', label: 'Seller')]
#[FormerKeys('old-seller')]
final class SellerRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [ShopPermission::Sell];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return Store::query()->where('user_id', $subject->getKey())->exists();
    }
}
