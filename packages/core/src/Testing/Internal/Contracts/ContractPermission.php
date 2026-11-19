<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Permissions\RequiresGrant;
use Packman\Attributes\Internal;

/**
 * @internal The permissions of the panel the contract suites build.
 */
#[RequiresGrant]
#[Internal]
enum ContractPermission: string
{
    case View = 'items.view';
    case Edit = 'items.edit';
}
