<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Contracts\HasActions;
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
        if (FilamentContext::serving()?->enforced() !== true) {
            return;
        }
        $action->authorize(static function () use ($action): Response {
            $livewire = $action->getHasActionsLivewire();
            $otherwise = $livewire?->getDefaultActionAuthorizationResponse($action);
            $context = FilamentContext::serving();
            $resource = FilamentContext::resourceOf($livewire);

            if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
                return $otherwise ?? Response::allow();
            }
            $record = $action->getRecord();
            $ability = self::ability($action);
            $bulkQuery = $action instanceof BulkAction && $livewire instanceof HasTable && $livewire->getTable()->hasQuery();

            return $otherwise ?? FilamentGate::resource($resource, $bulkQuery && ! str_ends_with($ability, '_any') ? $ability.'_any' : $ability,
                $record instanceof Model && ! in_array($action->getName(), ['export', 'attach', 'associate'], true) ? $record : null);
        });

        if (! $action instanceof BulkAction) {
            return;
        }
        $action->authorizeIndividualRecords(static function (Model $record) use ($action): Response {
            $resource = FilamentContext::resourceOf($action->getLivewire());

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

    private static function ability(Action $action): string
    {
        return match ($name = FilamentContext::ability($action->getName() ?? 'unnamed')) {
            'edit' => 'update',
            'revoke' => 'delete',
            'why', 'why_grant' => 'view',
            'export', 'attach', 'associate' => 'view_any',
            default => $name,
        };
    }
}
