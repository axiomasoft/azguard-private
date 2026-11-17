<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use Filament\Pages\Page;
use Packman\Attributes\Api;
use Packman\Filament\Concerns\Authorization\AuthorizesPage as PackmanAuthorizesPage;

/**
 * Opens a page with its permission `pages.{slug}` in the guard panel.
 *
 * @phpstan-require-extends Page
 */
#[Api(since: '1.0')]
trait AuthorizesPage
{
    use PackmanAuthorizesPage;
}
