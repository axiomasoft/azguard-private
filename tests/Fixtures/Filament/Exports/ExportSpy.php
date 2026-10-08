<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Exports;

use Filament\Actions\Exports\ExportDispatcher;
use Filament\Actions\Exports\Models\Export;

/** Records what an export action dispatches instead of dispatching it: the job class and the options. */
final class ExportSpy extends ExportDispatcher
{
    /** @var list<array{job: string, options: array<string, mixed>, records: array<mixed>|null}> */
    public array $dispatched = [];

    public function dispatch(
        Export $export,
        string $serializedQuery,
        array $columnMap,
        array $options,
        array $formats,
        ?array $records,
        string $job,
        int $chunkSize,
        ?string $jobQueue,
        ?string $jobConnection,
        ?string $jobBatchName,
        string $authGuard,
    ): void {
        $this->dispatched[] = ['job' => $job, 'options' => $options, 'records' => $records];
    }
}
