<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * `azguard:state:reset` against concurrent work on PostgreSQL/MySQL/MariaDB with independent processes. The reset
 * takes the lock of `panel_state` that every write of the panel takes: a worker holds it inside a pipe until released,
 * and the competitor is observed in a server lock wait before release. Barriers, not sleeps.
 */

beforeEach(function (): void {
    W::clean();
    CrmWorld::seed();
    $this->processes = new Processes;
});
afterEach(function (): void {
    W::clean();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function stateResetRaceBarrier(string $name): string
{
    return sys_get_temp_dir().'/azguard-reset-race-'.bin2hex(random_bytes(6)).'-'.$name;
}

/**
 * Runs `$first` holding the panel lock, starts `$second`, observes it waiting for that lock, then releases.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function stateResetRacePair(Processes $processes, array $first, array $second): array
{
    $ready = stateResetRaceBarrier('ready');
    $release = stateResetRaceBarrier('release');
    $id = stateResetRaceBarrier('id');

    try {
        $holder = $processes->start([...$first, 'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        $waiter = $processes->start([...$second, 'id_file' => $id], 'change-worker.php');
        Processes::wait($id);
        CacheEngineWorld::waitForLock((int) file_get_contents($id));
        touch($release);

        return [$processes->finish($holder), $processes->finish($waiter)];
    } finally {
        foreach ([$ready, $release, $id] as $file) {
            @unlink($file);
        }
    }
}

/** Anna (user 1) views client 1 of P1 in a fresh request scope of this process. */
function stateResetRaceDecision(): DecisionReason
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return CrmWorld::decide(app(PanelRegistry::class)->get('crm'))->reason;
}

it('I6 serializes reset ∥ grant by the panel_state lock in both orders', function (bool $resetFirst): void {
    $before = CrmWorld::storage()->state('crm');
    $reset = ['op' => 'reset'];
    $grant = ['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1];
    [$first, $second] = stateResetRacePair($this->processes, $resetFirst ? $reset : $grant, $resetFirst ? $grant : $reset);
    [$resetReport, $grantReport] = $resetFirst ? [$first, $second] : [$second, $first];
    $after = CrmWorld::storage()->state('crm');

    expect([$first['error'], $second['error']])->toBe([null, null])
        ->and([$resetReport['status'], $grantReport['status']])->toBe(['reset', 'applied'])
        ->and([$first['version'], $second['version']])->toBe([$before->version + 1, $before->version + 2])
        ->and($after->version)->toBe($before->version + 2)
        ->and($after->incarnation)->not->toBe($before->incarnation)
        ->and($resetReport['incarnation'])->toBe($after->incarnation)
        // The grant after the reset sees the new incarnation; the grant before it carries the old one.
        ->and($grantReport['incarnation'])->toBe($resetFirst ? $after->incarnation : $before->incarnation)
        ->and(W::keys())->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
})->with(['reset first' => [true], 'grant first' => [false]])->group('engines');

it('I6 V100 lets reset ∥ cached read keep the old answer only until the reset commits', function (): void {
    W::panel(configure: static fn (PanelBuilder $panel) => $panel->cache(store: 'array', ttl: 3600));
    $before = CrmWorld::storage()->state('crm');
    expect(stateResetRaceDecision())->toBe(DecisionReason::Granted);
    // A manual change of the tables bypasses the pipeline; the cached decision of the same state still answers.
    CrmWorld::storage()->connection()->table('azg_role_grants')->where('subject_id', '1')->where('role', 'seller')->delete();
    expect(stateResetRaceDecision())->toBe(DecisionReason::Granted);

    $ready = stateResetRaceBarrier('ready');
    $release = stateResetRaceBarrier('release');

    try {
        $worker = $this->processes->start(['op' => 'reset', 'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        // The reset holds the lock with its new incarnation written but not committed: readers see the old state.
        expect(stateResetRaceDecision())->toBe(DecisionReason::Granted)
            ->and(CrmWorld::storage()->state('crm')->incarnation)->toBe($before->incarnation);
        touch($release);
        $report = $this->processes->finish($worker);
    } finally {
        @unlink($ready);
        @unlink($release);
    }
    $after = CrmWorld::storage()->state('crm');

    expect($report['error'])->toBeNull()
        ->and($report['incarnation'])->toBe($after->incarnation)
        ->and($after->incarnation)->not->toBe($before->incarnation)
        ->and($after->version)->toBe($before->version + 1)
        ->and(stateResetRaceDecision())->toBe(DecisionReason::NotGranted)
        ->and(stateResetRaceDecision())->toBe(DecisionReason::NotGranted);
})->group('engines');
