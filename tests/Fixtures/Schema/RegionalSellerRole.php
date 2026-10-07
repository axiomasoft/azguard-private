<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\InjectedSellerFilter;

/** A code role whose context binding carries several typed filters, combined with AND. */
#[Role('regional-seller', label: 'Региональный продавец')]
final class RegionalSellerRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::View];
    }

    public function scopes(): array
    {
        return [ProjectScope::make()->filter(new SellerProjects)->filter(InjectedSellerFilter::class)->filter(new RegionProjects('R1'))->label('Проект региона')];
    }

    public function scopeRequired(): bool
    {
        return true;
    }
}
