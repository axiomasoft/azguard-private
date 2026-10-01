<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use AzGuard\Filament\Concerns\HasAzGuardPage;
use Filament\Pages\Page;

/**
 * Custom Filament page opting into AzGuard enforcement via {@see HasAzGuardPage}.
 * Its catalogued key is `admin.guarded_settings_page.view`.
 */
final class GuardedSettingsPage extends Page
{
    use HasAzGuardPage;
}
