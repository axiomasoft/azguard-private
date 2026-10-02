<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

/**
 * A permission enum the tests attach to two panels.
 */
enum SharedPermission: string
{
    case Export = 'reports.export';
}
