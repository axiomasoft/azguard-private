<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Internal\Reflection\ClassTraits;
use Filament\Pages\Page;
use Filament\Widgets\Widget;
use Livewire\Livewire;

/** @internal */
final class RefusesSurfaceCalls
{
    /** Registered for the process, not for the first Filament panel that happens to boot. */
    public static function guardWidgets(): void
    {
        if (app()->bound('azguard.filament.widget-guard')) {
            return;
        }
        app()->instance('azguard.filament.widget-guard', true);
        $guard = static function (mixed $component): void {
            $context = $component instanceof Widget ? FilamentContext::serving() : null;

            if ($context?->enforced() !== true || $context->excludes('widgets', $component::class)) {
                return;
            }
            abort_unless(ClassTraits::has($component, AuthorizesWidget::class), 403);
        };
        Livewire::listen('mount', static fn (mixed $component) => $guard($component));
        Livewire::listen('hydrate', static fn (mixed $component) => $guard($component));
    }

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
