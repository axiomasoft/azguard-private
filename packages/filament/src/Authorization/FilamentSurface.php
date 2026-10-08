<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

/**
 * The kind of Filament class that a permission key belongs to.
 *
 * @internal
 */
enum FilamentSurface: string
{
    case Resource = 'resource';
    case Page = 'page';
    case Widget = 'widget';
}
