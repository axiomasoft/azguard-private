<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Internal\Reflection\ClassTraits;
use Filament\Widgets\Widget;
use Livewire\ComponentHook;

/**
 * Runs before Livewire's own lifecycle hook, so a refused widget does not execute its boot().
 *
 * @internal
 */
final class RefusesWidgetBoot extends ComponentHook
{
    public function mount(): void
    {
        $this->refuse();
    }

    public function hydrate(): void
    {
        $this->refuse();
    }

    private function refuse(): void
    {
        $component = $this->component;

        if (! $component instanceof Widget) {
            return;
        }
        $context = FilamentContext::serving();

        if ($context?->enforced() !== true || $context->excludes('widgets', $component::class)) {
            return;
        }
        abort_unless(ClassTraits::has($component, AuthorizesWidget::class), 403);
        abort_unless(FilamentGate::widget($component::class)->allowed(), 403);
    }
}
