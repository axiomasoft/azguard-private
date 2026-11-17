<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use Filament\Widgets\Widget;
use Packman\Attributes\Api;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget as PackmanAuthorizesWidget;

/**
 * Shows a widget with its permission `widgets.{name}` in the guard panel.
 *
 * @phpstan-require-extends Widget
 */
#[Api(since: '1.0')]
trait AuthorizesWidget
{
    use PackmanAuthorizesWidget;
}
