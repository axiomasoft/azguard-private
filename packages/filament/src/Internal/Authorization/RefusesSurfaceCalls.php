<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Internal\Reflection\ClassTraits;
use Filament\Pages\Page;
use Filament\Widgets\Widget;

/** @internal */
final class RefusesSurfaceCalls
{
    public function __invoke(object $component): void
    {
        if ((! $component instanceof Widget && ! $component instanceof Page) || FilamentContext::serving()?->enforced() !== true) {
            return;
        }

        if ($component instanceof Widget && ClassTraits::has($component, AuthorizesWidget::class)) {
            abort_unless(FilamentGate::widget($component::class)->allowed(), 403);
        } elseif ($component instanceof Page && ClassTraits::has($component, AuthorizesPage::class)) {
            abort_unless(FilamentGate::page($component::class)->allowed(), 403);
        }
    }
}
