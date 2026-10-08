<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Authorization\FilamentContext;
use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides a relation manager by the permissions of the resource of its records: the related resource, or the resource
 * class named by `protected static ?string $azguardResource` on the relation manager. That resource uses
 * `AuthorizesResource`; the table of the relation manager keeps the records of it that the user may view. Without such
 * a resource a Filament panel that enforces refuses the relation manager.
 *
 * @phpstan-require-extends RelationManager
 *
 * @api
 */
trait AuthorizesRelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return FilamentGate::relationManager(static::class, 'view_any', otherwise: static fn (): Response => parent::canViewForRecord($ownerRecord, $pageClass)
            ? Response::allow() : Response::deny())->allowed();
    }

    public function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return FilamentGate::relationManager(static::class, $action, $record, fn (): Response => parent::getAuthorizationResponse($action, $record));
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()->modifyQueryUsing(static function (Builder $query): Builder {
            $resource = FilamentContext::relationResource(static::class);

            return $resource === null ? $query : FilamentGate::visible($resource, $query);
        });
    }
}
