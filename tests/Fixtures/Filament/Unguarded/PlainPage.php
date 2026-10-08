<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Unguarded;

use Filament\Pages\Page;

final class PlainPage extends Page
{
    protected static ?string $slug = 'plain';

    protected string $view = 'azguard-fixtures::probe';
}
