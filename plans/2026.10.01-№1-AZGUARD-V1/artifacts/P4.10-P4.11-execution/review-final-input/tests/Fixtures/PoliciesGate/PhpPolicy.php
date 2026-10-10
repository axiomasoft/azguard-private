<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

use AzGuard\Policies\Decides;

final class PhpPolicy
{
    #[Decides(GatePermission::Php)]
    #[Decides(GatePermission::Access)]
    public function decision(): bool
    {
        return true;
    }
}
