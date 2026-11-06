<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Exports;

use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class RefusesUnverifiableDownload extends DownloadExport
{
    public function __invoke(Request $request, Export $export): Response
    {
        // The export can predate the current panel configuration. Its origin and rows cannot be reconstructed from
        // Filament's shared route, so a current lack of enforced panels cannot establish authority either.
        abort(403);
    }
}
