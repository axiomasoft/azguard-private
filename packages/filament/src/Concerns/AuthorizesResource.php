<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Decides a resource by its own permissions in the guard panel: every `can*()`, `authorize*()` and
 * `get*AuthorizationResponse()` of Filament goes to `{slug}.{ability}`, and the query of the resource keeps the records
 * the user may view (`{slug}.view`), so lists, counts, global search and the records of a route see no other row.
 *
 * Change the query of the resource in `modifyEloquentQuery()`, not in `getEloquentQuery()`: the visible records are
 * chosen after it, and a Filament panel that enforces refuses a resource that overrides the methods of this trait.
 *
 * Inline editable columns of a table save a record without any of these checks; they are not supported in a resource
 * that AzGuard decides, unless they are `disabled()`. Enforced panels refuse enabled editors and exposed methods of
 * editable columns before Livewire invokes them. A column action must be a Filament Action, not a raw closure.
 *
 * @phpstan-require-extends FilamentResource
 *
 * @api
 */
trait AuthorizesResource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return FilamentGate::resource(static::class, $action, $record);
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return FilamentGate::visible(static::class, static::modifyEloquentQuery(parent::getEloquentQuery()));
    }

    /**
     * The query of the resource before AzGuard keeps the visible records of it.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query;
    }
}
