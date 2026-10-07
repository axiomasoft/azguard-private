<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;

#[Role('batch-native')]
final class BatchNativeRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [ConfiguredProjectScope::make()->filter(new BatchNativeFilter)];
    }
}
