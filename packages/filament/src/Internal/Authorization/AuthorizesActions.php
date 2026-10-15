<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Authorization;

use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Default checks for custom actions, and a fresh check before a bulk callback can consume selected records or keys.
 *
 * @internal
 */
final class AuthorizesActions
{
    public static function configure(Action $action): void
    {
        // An action may be constructed before the request selects its panel. Decide at execution, not construction.
        $action->authorize(static function (Action $action): Response {
            $livewire = $action->getHasActionsLivewire();
            $otherwise = $livewire?->getDefaultActionAuthorizationResponse($action);
            $context = FilamentContext::serving();
            $resource = FilamentContext::resourceOf($livewire);

            if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
                return $otherwise ?? Response::allow();
            }

            if (self::changesRelation($action)) {
                return $otherwise ?? self::relation($action, $livewire, $resource);
            }
            $record = $action->getRecord();
            $ability = self::ability($action);
            $bulkQuery = $action instanceof BulkAction && $livewire instanceof HasTable && $livewire->getTable()->hasQuery();

            return $otherwise ?? FilamentGate::resource($resource, $bulkQuery && ! str_ends_with($ability, '_any') ? $ability.'_any' : $ability,
                $record instanceof Model && $action->getName() !== 'export' ? $record : null);
        });

        if (! $action instanceof BulkAction || FilamentContext::serving()?->enforced() !== true) {
            return;
        }
        $action->authorizeIndividualRecords(static function (Model $record) use ($action): Response {
            $resource = FilamentContext::resourceOf($action->getLivewire());

            if ($resource !== null && self::changesRelation($action)) {
                return self::relation($action, $action->getLivewire(), $resource);
            }

            return $resource === null ? Response::deny() : FilamentGate::resource($resource, self::ability($action), $record);
        });
    }

    public function __invoke(object $action): void
    {
        if (! $action instanceof Action) {
            return;
        }
        $context = FilamentContext::serving();
        $resource = FilamentContext::resourceOf($action->getLivewire());

        if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
            return;
        }
        abort_unless($action->getAuthorizationResponse()->allowed(), 403);

        if (! $action instanceof BulkAction) {
            return;
        }
        $livewire = $action->getLivewire();

        // Grant editors use array records and do their atomic target/fingerprint checks in the core writer.
        if (! $livewire instanceof HasTable || ! $livewire instanceof HasActions || ! $livewire->getTable()->hasQuery()) {
            return;
        }
        abort_unless($action->shouldAuthorizeIndividualRecords(), 403);

        if ($livewire->getDefaultActionIndividualRecordAuthorizationResponseResolver($action) !== null) {
            // Native bulk actions consume Filament's filtered collection and report individual failures.
            $action->fetchSelectedRecords();

            return;
        }

        // Custom callbacks may consume raw keys or issue their own query instead of Filament's filtered collection.
        // Refuse the entire selection before calling them if even one selected visible row is unauthorized.
        foreach ($livewire->getSelectedTableRecordsQuery()->cursor() as $record) {
            abort_unless($action->getIndividualRecordAuthorizationResponse($record)->allowed(), 403);
        }
    }

    /** Whether the action links or unlinks the record of another resource to the record that owns the relation. */
    private static function changesRelation(Action $action): bool
    {
        return $action instanceof AttachAction || $action instanceof AssociateAction || $action instanceof DetachAction || $action instanceof DetachBulkAction
            || $action instanceof DissociateAction || $action instanceof DissociateBulkAction;
    }

    /**
     * Changing a relation changes the record that owns it: that is `update` of the owner, whatever the user may read, or
     * the ability the relation manager declares for it.
     * Attaching or associating also needs the related records to be readable; the records offered are filtered to
     * the visible ones by the plugin.
     *
     * @param  class-string<FilamentResource>  $related
     */
    private static function relation(Action $action, mixed $livewire, string $related): Response
    {
        $owner = FilamentContext::ownerOf($livewire);

        if ($owner === null || ! is_object($livewire)) {
            return Response::deny();
        }
        $response = FilamentGate::resource($owner[0], FilamentContext::relationAbility($livewire::class), $owner[1]);

        if (! $response->allowed() || ! $action instanceof AttachAction && ! $action instanceof AssociateAction) {
            return $response;
        }

        return FilamentGate::resource($related, 'view_any');
    }

    private static function ability(Action $action): string
    {
        $name = FilamentContext::ability($action->getName() ?? 'unnamed');

        return match ($name) {
            'edit' => 'update',
            'revoke' => 'delete',
            'why', 'why_grant' => 'view',
            'export' => 'view_any',
            default => $name,
        };
    }
}
