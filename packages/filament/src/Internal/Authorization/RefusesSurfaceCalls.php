<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Concerns\AuthorizesPage;
use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\Internal\Reflection\ClassTraits;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Widgets\Widget;

/** @internal */
final class RefusesSurfaceCalls
{
    public static function refuseRelationManager(RelationManager $component): void
    {
        $context = FilamentContext::serving();

        if ($context?->enforced() !== true) {
            return;
        }
        $owner = FilamentContext::ownerOf($component);

        if (is_array($owner) && $context->excludes('resources', $owner[0])) {
            return;
        }
        abort_unless(ClassTraits::keepsMethods($component, AuthorizesRelationManager::class, ['canViewForRecord', 'getAuthorizationResponse', 'makeTable']), 403);
        abort_unless(FilamentContext::relationResource($component::class) !== null, 403);
        abort_unless($component::canViewForRecord($component->getOwnerRecord(), $component->getPageClass()), 403);
    }

    public function __invoke(object $component): void
    {
        if ($component instanceof RelationManager) {
            self::refuseRelationManager($component);

            return;
        }

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
