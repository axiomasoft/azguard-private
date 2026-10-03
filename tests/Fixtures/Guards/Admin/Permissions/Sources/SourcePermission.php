<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Sources;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum SourcePermission: string
{
    case View = 'folder.sources.view';
}
