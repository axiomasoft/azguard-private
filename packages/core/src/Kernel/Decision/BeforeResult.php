<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

/**
 * A before hook may only let the check continue or deny it; it never grants.
 */
enum BeforeResult
{
    case Continue;
    case Deny;
}
