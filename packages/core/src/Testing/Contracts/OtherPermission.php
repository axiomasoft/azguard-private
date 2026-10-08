<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Permissions\RequiresGrant;

/**
 * @internal The permission of the second panel the contract suites build.
 */
#[RequiresGrant]
enum OtherPermission: string
{
    case Read = 'docs.read';
}
