<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Tests\Fixtures\Http\EntryPermission;

/** The strict-mode controller check on Laravel versions without native Authorize attributes. */
final class FallbackGatedController
{
    #[CheckPermission(EntryPermission::Enter)]
    public function gated(): string
    {
        return 'gated';
    }
}
