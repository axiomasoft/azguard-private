<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Permissions\RequiresGrant;
use Packman\Attributes\Internal;

/**
 * @internal The permission of the second panel the contract suites build.
 */
#[RequiresGrant]
#[Internal]
enum OtherPermission: string
{
    case Read = 'docs.read';
}
