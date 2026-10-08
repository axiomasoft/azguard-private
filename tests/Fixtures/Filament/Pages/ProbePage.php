<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Pages;

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/** Records which panels are current while the page is served. */
final class ProbePage extends Page
{
    protected static ?string $slug = 'probe';

    protected string $view = 'azguard-fixtures::probe';

    public function mount(): void
    {
        FilamentFixture::$seen[] = ['guard' => AzGuard::currentPanel()?->id(), 'filament' => Filament::getCurrentPanel()?->getId()];
    }
}
