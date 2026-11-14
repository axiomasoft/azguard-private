<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Internal\Reflection\ClassTraits;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Widgets\Widget;
use Livewire\ComponentHook;

/**
 * Runs before Livewire's own lifecycle hook, so a refused component does not execute its boot().
 *
 * @internal
 */
final class RefusesSurfaceBoot extends ComponentHook
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

        if ($component instanceof RelationManager) {
            RefusesSurfaceCalls::refuseRelationManager($component);

            return;
        }

        if ($component instanceof Page && ! $component instanceof ResourcePage) {
            $context = FilamentContext::serving();

            if ($context?->enforced() !== true || $context->excludes('pages', $component::class)) {
                return;
            }
            abort_unless(ClassTraits::keepsMethods($component, AuthorizesPage::class, ['canAccess']), 403);
            abort_unless(FilamentGate::page($component::class)->allowed(), 403);

            return;
        }

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
