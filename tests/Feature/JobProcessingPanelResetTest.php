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
 *
 * The sync driver is exempt (P1.4 review): a sync job runs inline inside the
 * current request/process, so there is no cross-job leak to prevent — and
 * resetting would wipe the active request's panel for the remainder of that
 * request (deny / PanelNotSetException under the fail-closed C-02 default).
 */
function dispatchJobProcessing(string $connection): void
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing($connection, $job));
}

it('resets currentPanel before each job on a real queue connection', function () {
    $panel = AzGuard::panel('test');
    AzGuard::setCurrentPanel($panel);

    expect(AzGuard::currentPanel())->not->toBeNull();

    dispatchJobProcessing('database');

    expect(AzGuard::currentPanel())->toBeNull();
});

it('preserves currentPanel when a sync job runs inline in the current request', function () {
    $panel = AzGuard::panel('test');
    AzGuard::setCurrentPanel($panel);

    dispatchJobProcessing('sync');

    expect(AzGuard::currentPanel())->not->toBeNull()
        ->and(AzGuard::currentPanel()?->getId())->toBe('test');
});
