<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Permissions\RequiresGrant;

/**
 * @internal The permissions of the panel the contract suites build.
 */
#[RequiresGrant]
enum ContractPermission: string
{
    case View = 'items.view';
    case Edit = 'items.edit';
}
