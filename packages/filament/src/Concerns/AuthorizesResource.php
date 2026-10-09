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
 * In a Filament panel that enforces, what Filament leaves open on the tables and pages of the resource is closed until
 * the application decides it: an action that runs code (`Action::make()->action()`, `DetachAction`, `DissociateAction`) is
 * refused unless it calls `authorize()`, `visible()` or `hidden()`; a bulk action of the application, `DetachBulkAction`
 * and `DissociateBulkAction` refuse every record unless they call `authorizeIndividualRecords()`; an inline editable
 * column (`TextInputColumn`, `ToggleColumn`, `CheckboxColumn`, `SelectColumn`) is disabled unless the user may update the
 * record of the row. An action or column that sets its own `authorize()` or `disabled()` decides itself.
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
