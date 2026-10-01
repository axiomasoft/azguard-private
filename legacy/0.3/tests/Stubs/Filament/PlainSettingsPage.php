<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use Filament\Pages\Page;

/**
 * Custom Filament page WITHOUT the AzGuard trait — its `canAccess()` keeps
 * Filament's `return true` default, so hiding the nav link would NOT stop a
 * direct URL hit. Used to contrast with {@see GuardedSettingsPage}.
 */
final class PlainSettingsPage extends Page {}
