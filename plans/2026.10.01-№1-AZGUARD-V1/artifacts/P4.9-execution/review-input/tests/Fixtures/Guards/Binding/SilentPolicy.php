<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Binding;

final class SilentPolicy
{
    public function allow(): bool
    {
        return true;
    }
}
