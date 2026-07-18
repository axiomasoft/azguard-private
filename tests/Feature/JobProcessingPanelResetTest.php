<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * C-14: a queue worker keeps the same PHP process (and singleton
 * AzGuardManager) across many jobs — without a reset, a panel set while
 * processing job N would leak into job N+1 on the same worker. Symmetric
 * with the existing Octane RequestReceived listener.
 */
it('resets currentPanel before each job runs', function () {
    $panel = AzGuard::panel('test');
    AzGuard::setCurrentPanel($panel);

    expect(AzGuard::currentPanel())->not->toBeNull();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing('sync', $job));

    expect(AzGuard::currentPanel())->toBeNull();
});
