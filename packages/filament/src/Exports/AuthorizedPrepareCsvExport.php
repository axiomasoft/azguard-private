<?php

declare(strict_types=1);

namespace AzGuard\Filament\Exports;

use Filament\Actions\Exports\Jobs\PrepareCsvExport;

/**
 * Prepares an export whose chunks are written by `AuthorizedExportCsv`; an export action of a Filament panel that
 * enforces uses it.
 *
 * @api
 */
class AuthorizedPrepareCsvExport extends PrepareCsvExport
{
    public function getExportCsvJob(): string
    {
        return AuthorizedExportCsv::class;
    }
}
