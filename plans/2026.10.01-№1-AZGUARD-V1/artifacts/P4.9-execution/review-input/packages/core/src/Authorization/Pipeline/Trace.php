<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline;

use Illuminate\Support\Facades\Log;
use Throwable;

final class Trace
{
    /** @var list<array{stage:string, result:string, component:?string, exception:?string}> */
    private array $steps = [];

    public function __construct(private readonly bool $enabled = false) {}

    public function record(string $stage, string $result, ?string $component = null, ?Throwable $error = null): void
    {
        if ($this->enabled) {
            $this->steps[] = ['stage' => $stage, 'result' => $result, 'component' => $component, 'exception' => $error === null ? null : $error::class];
        }
    }

    public function error(string $stage, string $reason, string $component, Throwable $error): void
    {
        $this->record($stage, $reason, $component, $error);
        Log::warning('AzGuard evaluation failed.', ['component' => $component, 'reason' => $reason, 'exception' => $error::class]);
    }

    /** @return list<array{stage:string, result:string, component:?string, exception:?string}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
