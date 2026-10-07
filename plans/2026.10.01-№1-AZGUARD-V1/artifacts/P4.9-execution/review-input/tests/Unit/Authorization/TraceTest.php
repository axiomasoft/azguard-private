<?php

declare(strict_types=1);

use AzGuard\Authorization\Pipeline\Trace;
use Illuminate\Support\Facades\Log;

it('only stores step diagnostics when the request asks for a trace', function (): void {
    $silent = new Trace;
    $trace = new Trace(true);
    $silent->record('before', 'Continue', 'closure');
    $trace->record('before', 'Continue', 'closure');
    expect($silent->steps())->toBe([])->and($trace->steps())->toBe([['stage' => 'before', 'result' => 'Continue', 'component' => 'closure', 'exception' => null]]);
});
it('logs failure reason and exception class without serializing host values or exception secrets', function (): void {
    Log::spy();
    $trace = new Trace;
    $trace->error('source', 'source_error', 'adapter', new RuntimeException('password=secret'));
    Log::shouldHaveReceived('warning')->with('AzGuard evaluation failed.', ['component' => 'adapter', 'reason' => 'source_error', 'exception' => RuntimeException::class]);
    expect($trace->steps())->toBe([]);
});
