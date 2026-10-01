<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\SetCurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Runtime\CurrentPanelState;
use AzGuard\Tests\Stubs\SwapTestManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

function dispatchPanelLifecycleJob(string $connection): void
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing($connection, $job));
}

it('restores the outer panel after nested success and exception', function (): void {
    AzGuard::setCurrentPanel(panel: null);

    AzGuard::registerPanel(panel: Panel::make()->id(id: 'outer')->label(label: 'Outer'));
    AzGuard::registerPanel(panel: Panel::make()->id(id: 'inner')->label(label: 'Inner'));

    $middleware = new SetCurrentPanel;
    $request = Request::create('/');

    $middleware->handle($request, function (Request $request) use ($middleware): Response {
        expect(AzGuard::currentPanel()?->getId())->toBe('outer');

        $middleware->handle($request, function (): Response {
            expect(AzGuard::currentPanel()?->getId())->toBe('inner');

            return response('nested-ok');
        }, 'inner');

        expect(AzGuard::currentPanel()?->getId())->toBe('outer');

        try {
            $middleware->handle($request, function (): Response {
                expect(AzGuard::currentPanel()?->getId())->toBe('inner');

                throw new RuntimeException('nested-fail');
            }, 'inner');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('nested-fail')
                ->and(AzGuard::currentPanel()?->getId())->toBe('outer');
        }

        return response('outer-ok');
    }, 'outer');

    expect(AzGuard::currentPanel())->toBeNull();
});

it('yields a fresh null current panel after scoped flush while keeping the registry', function (): void {
    $panel = AzGuard::panel('test');
    AzGuard::setCurrentPanel($panel);

    expect(AzGuard::currentPanel()?->getId())->toBe('test')
        ->and(AzGuard::panel('test'))->not->toBeNull();

    $holder = app(CurrentPanelState::class);
    app()->forgetScopedInstances();

    expect(app(CurrentPanelState::class))->not->toBe($holder)
        ->and(AzGuard::currentPanel())->toBeNull()
        ->and(AzGuard::panel('test'))->not->toBeNull()
        ->and(array_keys(AzGuard::getPanels()))->toContain('test');
});

it('clears current panel on Octane RequestReceived', function (): void {
    AzGuard::setCurrentPanel(AzGuard::panel('test'));

    expect(AzGuard::currentPanel())->not->toBeNull();

    Event::dispatch('Laravel\Octane\Events\RequestReceived');

    expect(AzGuard::currentPanel())->toBeNull();
});

it('clears current panel on non-sync JobProcessing and preserves it for sync', function (): void {
    AzGuard::setCurrentPanel(AzGuard::panel('test'));
    dispatchPanelLifecycleJob('database');
    expect(AzGuard::currentPanel())->toBeNull();

    AzGuard::setCurrentPanel(AzGuard::panel('test'));
    dispatchPanelLifecycleJob('sync');
    expect(AzGuard::currentPanel()?->getId())->toBe('test');
});

it('forwards lifecycle set/current calls to a configured custom manager', function (): void {
    $panel = AzGuard::panel('test');
    $manager = new SwapTestManager;
    app()->instance(AzGuardManagerInterface::class, $manager);

    $manager->setCurrentPanel($panel);

    expect($manager->currentPanel()?->getId())->toBe('test');

    dispatchPanelLifecycleJob('database');

    expect($manager->currentPanel())->toBeNull();

    $manager->setCurrentPanel($panel);
    dispatchPanelLifecycleJob('sync');

    expect($manager->currentPanel()?->getId())->toBe('test');
});
