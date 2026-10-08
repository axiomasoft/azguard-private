<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use Illuminate\Database\Eloquent\Model;

/** Admits the fixture members into the admin panel; nothing is stored. */
#[Role('member')]
#[NotGrantable]
final class AdminMemberRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [EntryPermission::Enter];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return in_array($subject->getKey(), FilamentFixture::$members, true);
    }
}
