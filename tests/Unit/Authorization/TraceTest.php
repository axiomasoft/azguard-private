<?php

declare(strict_types=1);

use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\Log;

it('only stores step diagnostics when the request asks for a trace', function (): void {
    $silent = new Trace;
    $trace = new Trace(true);
    $silent->record('before', 'Continue', 'closure');
    $trace->record('before', 'Continue', 'closure');
    expect($silent->steps())->toBe([])->and($trace->steps())->toBe([[
        'stage' => 'before',
        'component' => 'closure',
        'outcome' => 'pass',
        'detail' => [],
        'result' => 'Continue',
        'exception' => null,
    ]]);
});
it('logs failure reason and exception class without serializing host values or exception secrets', function (): void {
    Log::spy();
    $trace = new Trace;
    $trace->error('source', 'source_error', 'adapter', new RuntimeException('password=secret'));
    Log::shouldHaveReceived('warning')->with('AzGuard evaluation failed.', ['component' => 'adapter', 'reason' => 'source_error', 'exception' => RuntimeException::class]);
    expect($trace->steps())->toBe([]);
});
it('logs the stable code of a package exception so a fail-closed denial names its cause', function (): void {
    Log::spy();
    (new Trace)->error('sources', 'source_error', 'sources', InvalidConfigurationException::failing('authority_transaction', 'password=secret'));
    Log::shouldHaveReceived('warning')->with('AzGuard evaluation failed.', ['component' => 'sources', 'reason' => 'source_error',
        'exception' => InvalidConfigurationException::class, 'code' => 'invalid_configuration.authority_transaction']);
});
