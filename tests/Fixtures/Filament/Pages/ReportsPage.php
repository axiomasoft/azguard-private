<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Pages;

use AzGuard\Filament\Concerns\AuthorizesPage;
use Filament\Pages\Page;

/** A page opened by its permission `pages.reports`. */
final class ReportsPage extends Page
{
    use AuthorizesPage;

    protected static ?string $slug = 'reports';

    protected string $view = 'azguard-fixtures::probe';
}
