<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;

/**
 * Declares everything by attributes; `AnalystRole` declares the same kind of values by methods.
 */
#[Role('seller', label: 'Seller', level: 10)]
#[FormerKeys('shop-seller', 'vendor')]
final class SellerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['clients.view', 'clients.update'];
    }

    public function scopes(): array
    {
        return [new ProjectScope];
    }

    public function scopeRequired(): bool
    {
        return true;
    }
}
