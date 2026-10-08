<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;

/**
 * The member role of the admin panel as a stored grant, for the tests of exact visibility: a panel with a role granted
 * automatically cannot filter a query exactly.
 */
#[Role('member')]
final class GrantedMemberRole extends BaseRole
{
    public function permissions(): array
    {
        return [EntryPermission::Enter, ...FilamentFixture::$memberPermissions];
    }
}
