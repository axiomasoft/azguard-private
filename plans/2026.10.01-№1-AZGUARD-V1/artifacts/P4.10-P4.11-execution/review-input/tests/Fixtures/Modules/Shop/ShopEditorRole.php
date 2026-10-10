<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Shop;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/**
 * The editor of the Shop module, named in the module's own namespace.
 */
#[Role('shop-editor', label: 'Shop editor')]
final class ShopEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return ['shop.posts.edit'];
    }
}
