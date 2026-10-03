<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Missing\Permissions;

enum MissingPermission: string
{
    case View = 'missing.view';
}
