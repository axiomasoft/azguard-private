<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use Packman\Attributes\Internal;

/**
 * The kind of Filament class that a permission key belongs to.
 *
 * @internal
 */
#[Internal]
enum FilamentSurface: string
{
    case Resource = 'resource';
    case Page = 'page';
    case Widget = 'widget';
}
