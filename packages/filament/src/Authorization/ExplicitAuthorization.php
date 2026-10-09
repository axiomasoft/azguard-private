<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\SelectAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Contracts\Editable;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The surfaces that Filament leaves open on the tables and pages of a resource: an action of the application and an
 * inline editable column run for any visible record unless the application restricts them itself.
 *
 * Inside a Filament panel that enforces, such an action is refused until it says who may run it (`authorize()`,
 * `visible()` or `hidden()`; a bulk action: `authorizeIndividualRecords()`), and an inline editable column is disabled
 * until the user may update the record of its row.
 *
 * @internal
 */
final class ExplicitAuthorization
{
    /**
     * The actions that Filament decides by their own policy method or that AzGuard decides elsewhere.
     *
     * @var list<class-string<Action>>
     */
    private const DECIDED = [
        CreateAction::class, EditAction::class, ViewAction::class, DeleteAction::class, ForceDeleteAction::class,
        RestoreAction::class, ReplicateAction::class, ExportAction::class, ImportAction::class, SelectAction::class,
        AttachAction::class, AssociateAction::class,
        DeleteBulkAction::class, ForceDeleteBulkAction::class, RestoreBulkAction::class, ExportBulkAction::class,
    ];

    /**
     * An action that runs code on a resource surface and that nothing decides yet: it gets an authorization that refuses it
     * until the application adds its own.
     */
    public static function action(Action $action): void
    {
        if ($action instanceof BulkAction || FilamentContext::serving()?->enforced() !== true || self::decided($action)) {
            return;
        }
        $action->authorize(static fn (Action $action): Response => self::runnable($action) ? Response::allow() : self::refusal($action));
    }

    /**
     * A bulk action of the application gets a per-record authorization that refuses every record until the application
     * replaces it with its own `authorizeIndividualRecords()`: one decision cannot speak for all selected rows.
     */
    public static function bulkAction(BulkAction $action): void
    {
        if (FilamentContext::serving()?->enforced() !== true || self::decided($action)) {
            return;
        }
        $action->fetchSelectedRecords()->authorizeIndividualRecords(
            static fn (Model $record): Response => self::onResource($action->getLivewire()) ? self::refusal($action) : Response::allow(),
        );
    }

    /**
     * An inline editable column of a resource table is disabled until the user may update the record of the row; a column
     * that sets its own `disabled()` decides itself.
     */
    public static function column(Column $column): void
    {
        if (! $column instanceof Editable || FilamentContext::serving()?->enforced() !== true) {
            return;
        }
        $column->disabled(static function (Column $column, mixed $livewire, ?Model $record): bool {
            if (! self::onResource($livewire)) {
                return false;
            }
            $resource = FilamentContext::resourceOf($livewire);

            return $resource === null || $record === null || FilamentGate::resource($resource, 'update', $record)->denied();
        });
    }

    private static function decided(Action $action): bool
    {
        foreach (self::DECIDED as $class) {
            if ($action instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the action may run: it has nothing to run, or the application restricted it with `visible()` or `hidden()`.
     */
    private static function runnable(Action $action): bool
    {
        if (! self::onResource($action->getLivewire())) {
            return true;
        }
        [$handler, $hidden, $visible] = Closure::bind(
            fn (): array => [$this->action, $this->isHidden, $this->isVisible],
            $action,
            Action::class,
        )();

        return $handler === null || $hidden !== false || $visible !== true;
    }

    private static function refusal(Action $action): Response
    {
        return Response::deny('The action "'.$action->getName().'" of a resource that AzGuard decides says nothing about who may run it: add authorize(), visible() or hidden() to it (a bulk action: authorizeIndividualRecords()).')
            ->withStatus(403);
    }

    private static function onResource(mixed $livewire): bool
    {
        return $livewire instanceof RelationManager || $livewire instanceof ResourcePage;
    }
}
