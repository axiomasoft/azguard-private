<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use AzGuard\Filament\Concerns\HasAzGuardPage;
use Filament\Pages\Page;

final class OverriddenSettingsPage extends Page
{
    use HasAzGuardPage;

    public static function canAccess(): bool
    {
        return true;
    }
}
