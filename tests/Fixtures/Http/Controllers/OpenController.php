<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\SkipPermissionCheck;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** Actions that opt out of a check or are checked by Laravel, and one checked by nothing. */
final class OpenController
{
    #[SkipPermissionCheck]
    public function ping(): string
    {
        return 'pong';
    }

    #[Authorize('crm-open')]
    public function gated(): string
    {
        return 'gated';
    }

    public function unchecked(): string
    {
        return 'unchecked';
    }
}
