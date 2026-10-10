<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\Store;

#[Role('store-owner')]
final class StoreOwnerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function scopes(): array
    {
        return [new ProjectDefinition(alias: 'store', recordClass: Store::class)];
    }
}
