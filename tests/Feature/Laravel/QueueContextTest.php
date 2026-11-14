<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\Internal\PanelResolver;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Queue\PanelProbeJob;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

/*
 * V84 (job part) and V103 (queue/sync): a job dispatched from a request in `azguard.panel:{id}` resolves its short
 * names against that panel; a job from the console uses the default rule; the panel before a job comes back after it,
 * also when it fails; a hint that names no panel is refused, never replaced by the default panel.
 */

beforeEach(function (): void {
    HttpWorld::seed();
    HttpWorld::panel();
    PanelProbeJob::reset();
    Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
        Route::get('/dispatch', static function (): array {
            PanelProbeJob::dispatch();

            return ['after' => app(CurrentPanel::class)->get()?->id(), 'hint' => Context::getHidden('azguard.panel')];
        });
        Route::get('/dispatch-failing', static function (): array {
            PanelProbeJob::$fails = true;

            try {
                PanelProbeJob::dispatch();
            } catch (RuntimeException) {
            }

            return ['after' => app(CurrentPanel::class)->get()?->id(), 'hint' => Context::getHidden('azguard.panel')];
        });
        Route::get('/dispatch-as/{panel}', static function (string $panel): array {
            // What a request of another panel would have written, to see a job run in a panel other than its request's.
            Context::addHidden('azguard.panel', $panel);
            PanelProbeJob::dispatch();

            return ['after' => app(CurrentPanel::class)->get()?->id()];
        });
    });
    HttpWorld::actingAs(1);
});
afterEach(function (): void {
    PanelProbeJob::reset();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('V84 resolves the short names of a job against the panel of the request that dispatched it', function (): void {
    $this->getJson('/dispatch', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['after' => 'crm', 'hint' => 'crm']);

    expect(PanelProbeJob::$runs)->toBe([
        ['panel' => 'crm', 'rejected' => null, 'picked' => 'crm', 'step' => PanelResolver::CURRENT, 'short' => true, 'explicit' => true],
    ]);
});

it('V103 sends a job from the console to the default rule, which names no panel for a shared model', function (): void {
    PanelProbeJob::dispatch();

    expect(PanelProbeJob::$runs)->toHaveCount(1)
        ->and(PanelProbeJob::$runs[0]['panel'])->toBeNull()
        ->and(PanelProbeJob::$runs[0]['refused'])->toBe(PanelNotResolvedException::class)
        ->and(PanelProbeJob::$runs[0]['explicit'])->toBeTrue()
        ->and(app(CurrentPanel::class)->get())->toBeNull()
        ->and(Context::hasHidden('azguard.panel'))->toBeFalse();
});

it('V103 runs a sync job in the panel it carries and leaves the panel of the request unchanged', function (): void {
    $this->getJson('/dispatch-as/backoffice', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['after' => 'crm']);

    expect(PanelProbeJob::$runs)->toHaveCount(1)
        ->and(PanelProbeJob::$runs[0]['panel'])->toBe('backoffice')
        ->and(PanelProbeJob::$runs[0]['picked'])->toBe('backoffice')
        ->and(PanelProbeJob::$runs[0]['short'])->toBeFalse()
        ->and(PanelProbeJob::$runs[0]['explicit'])->toBeTrue()
        ->and(app(CurrentPanel::class)->get())->toBeNull();
});

it('V103 leaves no panel behind a job that throws, and no hint behind the request', function (): void {
    $this->getJson('/dispatch-failing', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['after' => 'crm', 'hint' => 'crm']);

    expect(PanelProbeJob::$runs)->toHaveCount(1)
        ->and(PanelProbeJob::$runs[0]['panel'])->toBe('crm')
        ->and(app(CurrentPanel::class)->get())->toBeNull()
        ->and(app(CurrentPanel::class)->rejected())->toBeNull()
        ->and(Context::hasHidden('azguard.panel'))->toBeFalse();
});

it('V103 refuses the short names of a job whose panel no longer exists and keeps explicit names working', function (): void {
    $this->getJson('/dispatch-as/removed', ['X-Tenant' => '1'])->assertOk()->assertExactJson(['after' => 'crm']);

    expect(PanelProbeJob::$runs)->toHaveCount(1)
        ->and(PanelProbeJob::$runs[0]['panel'])->toBeNull()
        ->and(PanelProbeJob::$runs[0]['rejected'])->toBe('removed')
        ->and(PanelProbeJob::$runs[0]['refused'])->toBe(UnknownPanelException::class)
        ->and(PanelProbeJob::$runs[0])->not->toHaveKey('short')
        ->and(PanelProbeJob::$runs[0]['explicit'])->toBeTrue()
        ->and(app(CurrentPanel::class)->rejected())->toBeNull();
});

it('restores an absent worker hint before a late job listener dispatches another job', function (string $event): void {
    Context::addHidden('azguard.panel', 'crm');
    $payload = Context::dehydrate();
    Context::forgetHidden('azguard.panel');
    $job = new SyncJob(app(), json_encode(['illuminate:log:context' => $payload], JSON_THROW_ON_ERROR), 'sync', 'default');
    app('events')->dispatch(new JobProcessing('sync', $job));
    expect(app(CurrentPanel::class)->get()?->id())->toBe('crm')->and(Context::getHidden('azguard.panel'))->toBe('crm');
    $hintSeen = 'listener did not run';
    app('events')->listen($event, function (object $finishedEvent) use (&$hintSeen, $job): void {
        if ($finishedEvent->job !== $job) {
            return;
        }
        $hintSeen = Context::getHidden('azguard.panel');
        PanelProbeJob::dispatch();
    });
    $finished = $event === JobProcessed::class ? new JobProcessed('sync', $job) : new $event('sync', $job, new RuntimeException('job failed'));
    app('events')->dispatch($finished);

    expect($hintSeen)->toBeNull()->and(Context::hasHidden('azguard.panel'))->toBeFalse()
        ->and(PanelProbeJob::$runs[0]['panel'])->toBeNull()->and(app(CurrentPanel::class)->get())->toBeNull();
})->with([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class]);
