<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Permissions\RequiresGrant;

/** The permission of the widget that `WidgetOrderResource` returns from `getWidgets()`. */
#[RequiresGrant]
enum ResourceWidgetPermission: string
{
    case ProtectedWidget = 'widgets.protected-widget';
}
