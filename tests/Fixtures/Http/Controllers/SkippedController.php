<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\SkipPermissionCheck;

/** Every action is deliberately checked by nothing. */
#[SkipPermissionCheck]
final class SkippedController
{
    public function health(): string
    {
        return 'ok';
    }
}
