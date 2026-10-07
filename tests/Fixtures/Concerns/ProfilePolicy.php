<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Policies\Decides;

/** Every member may view a profile; the PolicyOnly permission never enters a permission set. */
final class ProfilePolicy
{
    #[Decides('profile.view')]
    public function check(Member $member): bool
    {
        return true;
    }
}
