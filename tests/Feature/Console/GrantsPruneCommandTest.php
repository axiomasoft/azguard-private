<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Events\GrantExpired;
use AzGuard\Events\RoleRevoked;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CrmWorld::seed();
    $panel = W::panel();
    W::grant($panel, 'auditor', 2, null, until: new DateTimeImmutable('2026-10-06T12:10:00Z'));
    W::grant($panel, 'auditor', 1, null, 2, until: new DateTimeImmutable('2026-10-06T12:20:00Z'));
    W::grant($panel, 'auditor', 3, null, until: new DateTimeImmutable('2026-10-06T18:00:00Z'));
    Carbon::setTestNow('2026-10-06T13:00:00Z');
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('R66 prunes expired grants of every tenant with GrantExpired as the system actor and keeps live ones', function (): void {
    Event::fake([GrantExpired::class, RoleRevoked::class]);

    expect(Artisan::call('azguard:grants:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Panel crm: 2 expired grant(s) removed.')
        ->and(W::keys())->not->toContain('crm.organization:1|auditor|2|global|manual', 'crm.organization:2|auditor|1|global|manual')
        ->and(W::keys())->toContain('crm.organization:1|auditor|3|global|manual');
    Event::assertDispatchedTimes(GrantExpired::class, 2);
    Event::assertNotDispatched(RoleRevoked::class);
    Event::assertDispatched(GrantExpired::class, static fn (GrantExpired $event): bool => $event->actor?->reason === 'azguard:grants:prune');
});

it('counts without writing on a dry run and narrows to one tenant of one panel', function (): void {
    $version = W::version();

    expect(Artisan::call('azguard:grants:prune', ['--panel' => 'crm', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('2 expired grant(s) would be removed', 'Dry run')
        ->and(W::version())->toBe($version)
        ->and(Artisan::call('azguard:grants:prune', ['--panel' => 'crm', '--tenant' => 'crm.organization:2']))->toBe(0)
        ->and(W::keys())->toContain('crm.organization:1|auditor|2|global|manual')
        ->and(W::keys())->not->toContain('crm.organization:2|auditor|1|global|manual');
});

it('moves the cut-off back with --before and never forward', function (): void {
    expect(Artisan::call('azguard:grants:prune', ['--before' => '2026-10-06T12:15:00Z']))->toBe(0)
        ->and(W::keys())->not->toContain('crm.organization:1|auditor|2|global|manual')
        ->and(W::keys())->toContain('crm.organization:2|auditor|1|global|manual')
        ->and(Artisan::call('azguard:grants:prune', ['--before' => '2026-10-07T00:00:00Z']))->toBe(2)
        ->and(Artisan::output())->toContain('future')
        ->and(W::keys())->toContain('crm.organization:1|auditor|3|global|manual')
        ->and(Artisan::call('azguard:grants:prune', ['--tenant' => 'crm.organization:1']))->toBe(2);
});

/** @return list<ScheduledEvent> scheduler events after the provider registered its tasks with this configuration */
function pruneSchedule(array $schedule): array
{
    config()->set('azguard.schedule', $schedule);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(Schedule::class);
    // The schedule as the booted provider leaves it; the provider under test adds to the resolved schedule at once.
    $booted = count(app(Schedule::class)->events());
    $provider = new AzGuardServiceProvider(app());
    (fn () => $this->scheduleMaintenance())->call($provider);

    return array_values(array_filter(array_slice(app(Schedule::class)->events(), $booted),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'azguard:grants:prune')));
}

it('registers the prune in the scheduler by schedule.prune_expired', function (): void {
    // The booted provider with the default configuration: enabled, daily.
    expect(array_values(array_map(static fn (ScheduledEvent $event): string => $event->expression, array_filter(app(Schedule::class)->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'azguard:grants:prune')))))->toBe(['0 0 * * *']);

    expect(array_map(static fn (ScheduledEvent $event): string => $event->expression, pruneSchedule(['enabled' => true, 'prune_expired' => 'daily'])))->toBe(['0 0 * * *'])
        ->and(array_map(static fn (ScheduledEvent $event): string => $event->expression, pruneSchedule(['enabled' => true, 'prune_expired' => '*/15 * * * *'])))->toBe(['*/15 * * * *'])
        ->and(pruneSchedule(['enabled' => true, 'prune_expired' => null]))->toBe([])
        ->and(pruneSchedule(['enabled' => false, 'prune_expired' => 'hourly']))->toBe([]);
});
