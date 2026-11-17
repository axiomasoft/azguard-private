<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use Closure;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use UnitEnum;

/**
 * Packman `FilamentSurfaceGate` implementation backed by AzGuard's fail-closed `FilamentGate`.
 */
#[Api(since: '1.0')]
final readonly class AzGuardFilamentSurfaceGate implements FilamentSurfaceGate
{
    /**
     * @param  class-string<FilamentResource>  $resource
     */
    public function authorizeResource(
        string $resource,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
    ): Response {
        return FilamentGate::resource($resource, $action, $record);
    }

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizeRelationManager(
        string $relationManager,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        return FilamentGate::relationManager($relationManager, $action, $record, $otherwise);
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizePage(
        string $page,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        return FilamentGate::page($page, $otherwise);
    }

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizeWidget(
        string $widget,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        return FilamentGate::widget($widget, $otherwise);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleQuery(
        string $resource,
        Builder $query,
        FilamentPanelPlugin $plugin,
    ): Builder {
        return FilamentGate::visible($resource, $query);
    }

    /**
     * @param  array<mixed>  $arguments
     */
    public function beforeGate(
        ?object $user,
        string $ability,
        array $arguments,
        FilamentPanelPlugin $plugin,
    ): ?bool {
        return FilamentGate::before($user, $ability, $arguments);
    }
}
