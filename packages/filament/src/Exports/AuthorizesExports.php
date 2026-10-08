<?php

declare(strict_types=1);

namespace AzGuard\Filament\Exports;

use AzGuard\Filament\Authorization\FilamentContext;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Support\Facades\Log;

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

        if ($resource === null || ($job !== PrepareCsvExport::class && ! is_a($job, AuthorizedPrepareCsvExport::class, true))) {
            Log::warning('AzGuard: an export without a check of its rows was refused.', ['panel' => $context->guardPanel(), 'job' => $job]);
            $action->failureNotificationTitle('The export was not started: its rows cannot be checked against your permissions.');
            $action->sendFailureNotification();
            $action->halt();

            return;
        }
        $tenant = $context->access()->scope()->tenant;

        $action->job($job === PrepareCsvExport::class ? AuthorizedPrepareCsvExport::class : $job)
            ->options([...$action->getOptions(), AuthorizedExportCsv::OPTION => [
                'panel' => $context->guardPanel(),
                'permission' => $context->resourceKey($resource, 'view'),
                'tenant' => $tenant->isGlobal() ? null : ['type' => $tenant->type(), 'id' => $tenant->id()],
                'model' => $resource::getModel(),
            ]]);
    }
}
