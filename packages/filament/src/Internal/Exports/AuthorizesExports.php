<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Exports;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Filament\Internal\Authorization\FilamentContext;
use AzGuard\Panels\Reads;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives every export started in a Filament panel that enforces the jobs that check the rows again, with the view
 * permission of the resource, the guard panel and the tenant in its options. It runs when the export action is called,
 * after the action is configured, so the settings of the table cannot drop it. Filament dispatches the event
 * `ActionCalling` by its class name with the action as the payload. An export that cannot be checked again,
 * because its table belongs to no resource that AzGuard authorizes or its job is not one of these, does not start.
 *
 * @internal
 */
final class AuthorizesExports
{
    public function __invoke(object $action): void
    {
        if (! $action instanceof ExportAction && ! $action instanceof ExportBulkAction) {
            return;
        }
        $context = FilamentContext::serving();

        if ($context === null || ! $context->enforced()) {
            return;
        }
        $resource = FilamentContext::resourceOf($action->getLivewire());
        $job = $action->getJob();

        if ($resource === null || ! in_array($job, [PrepareCsvExport::class, AuthorizedPrepareCsvExport::class, ChecksPrepareCsvExport::class], true)) {
            Log::warning('AzGuard: an export without a check of its rows was refused.', ['panel' => $context->guardPanel(), 'job' => $job]);
            $action->failureNotificationTitle('The export was not started: its rows cannot be checked against your permissions.');
            $action->sendFailureNotification();
            $action->halt();

            return;
        }
        $livewire = $action->getLivewire();
        $authorized = false;

        try {
            if ($context->access()->definition()->settings()->reads() !== Reads::Primary) {
                abort(403);
            }
            $user = Filament::auth()->user();
            $fresh = $user instanceof Model ? $user->newQuery()->useWritePdo()->find($user->getKey()) : null;

            if ($fresh instanceof Model && $livewire instanceof HasTable) {
                Filament::auth()->setUser($fresh);
                $query = $action instanceof ExportBulkAction ? $livewire->getSelectedTableRecordsQuery() : $livewire->getTableQueryForExport();
                $query->useWritePdo();

                if ($query->getModel()::class === $resource::getModel()) {
                    // Do not hold an unbuffered cursor while the gate reloads and authorizes a record.
                    (clone $query)->each(static function (Model $record) use ($resource, &$authorized): bool {
                        $authorized = FilamentGate::resource($resource, 'view', $record)->allowed();

                        return ! $authorized;
                    }, 100);
                }
            }
        } catch (Throwable $error) {
            Log::warning('AzGuard: export authorization failed.', ['exception' => $error::class]);
        }
        abort_unless($authorized, 403);
        $tenant = $context->access()->scope()->tenant;

        $action->job(ChecksPrepareCsvExport::class)
            ->options([...$action->getOptions(), AuthorizedExportCsv::OPTION => [
                'panel' => $context->guardPanel(),
                'permission' => $context->resourceKey($resource, 'view'),
                'tenant' => $tenant->isGlobal() ? null : ['type' => $tenant->type(), 'id' => $tenant->id()],
                'model' => $resource::getModel(),
            ]]);
    }
}
