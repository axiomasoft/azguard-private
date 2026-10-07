<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\HostFencePipe;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

/*
 * Change races on PostgreSQL/MySQL/MariaDB with independent processes. Ordering comes from barriers: a worker holds
 * the panel lock inside a pipe until released, and the competitor is observed in a server lock wait before release.
 */

function changeRaceDrop(): void
{
    $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
    foreach (['crm_weight', 'crm_active_build', 'crm_project_revisions', 'clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
        $schema->dropIfExists($table);
    }
    app(StorageSchema::class)->drop('default');
}

function changeRaceBarrier(string $name): string
{
    return sys_get_temp_dir().'/azguard-change-race-'.bin2hex(random_bytes(6)).'-'.$name;
}

/** @param list<string> $files */
function changeRaceCleanup(array $files): void
{
    foreach ($files as $file) {
        @unlink($file);
    }
}

function changeRaceWaitForId(string $file): int
{
    Processes::wait($file);

    return (int) file_get_contents($file);
}

/** The fingerprint a worker compiles: closures count by kind, so the coordinator can compute it. */
function changeRaceFenceFingerprint(): string
{
    W::panel([static fn () => null, HostFencePipe::class]);

    return app(PanelRegistry::class)->fingerprint('crm');
}

beforeEach(function (): void {
    changeRaceDrop();
    CrmWorld::seed();
    $this->processes = new Processes;
});
afterEach(function (): void {
    changeRaceDrop();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/**
 * Runs `$first` holding the panel lock, starts `$second`, observes it waiting for that lock, then releases.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function changeRacePair(Processes $processes, array $first, array $second): array
{
    $ready = changeRaceBarrier('ready');
    $release = changeRaceBarrier('release');
    $id = changeRaceBarrier('id');

    try {
        $holder = $processes->start([...$first, 'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        $waiter = $processes->start([...$second, 'id_file' => $id], 'change-worker.php');
        CacheEngineWorld::waitForLock(changeRaceWaitForId($id));
        touch($release);

        return [$processes->finish($holder), $processes->finish($waiter)];
    } finally {
        changeRaceCleanup([$ready, $release, $id]);
    }
}

it('V101 serializes grant ∥ grant of one identity: one Applied, one Unchanged, one bump', function (): void {
    $version = W::version();
    [$first, $second] = changeRacePair($this->processes,
        ['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1],
        ['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1]);

    expect([$first['error'], $second['error']])->toBe([null, null])
        ->and([$first['status'], $second['status']])->toBe(['applied', 'unchanged'])
        ->and([$first['version'], $second['version']])->toBe([$version + 1, $version + 1])
        ->and(W::version())->toBe($version + 1)
        ->and(array_count_values(W::keys())['crm.organization:1|analyst|2|crm.project:1|manual'])->toBe(1);
})->group('engines');

it('V101 C13 lets one update ∥ update with the same fingerprint win and refuses the stale form', function (): void {
    // The fingerprint covers the build: read the form on the build shape the workers compile (one closure pipe).
    $record = W::grant(W::panel([static fn (Change $c, Closure $next) => $next($c)]), 'analyst', 2, 1)->record;
    [$first, $second] = changeRacePair($this->processes,
        ['op' => 'update', 'id' => $record->id, 'fingerprint' => $record->fingerprint, 'fields' => ['region' => 'R1']],
        ['op' => 'update', 'id' => $record->id, 'fingerprint' => $record->fingerprint, 'fields' => ['region' => 'R2']]);

    expect($first['status'])->toBe('applied')
        ->and($second['error'])->toBe(StaleSelectionException::class)
        ->and(json_decode((string) array_values(array_filter(W::rows(), fn (array $row): bool => 'role:'.$row['id'] === $record->id))[0]['meta'], true))
        ->toBe(['region' => 'R1']);
})->group('engines');

it('V101 C12 orders revokeIds ∥ update of one id by the panel lock', function (bool $revokeFirst): void {
    $record = W::grant(W::panel(), 'analyst', 2, 1)->record;
    $revoke = ['op' => 'revoke-ids', 'ids' => [$record->id]];
    $update = ['op' => 'update', 'id' => $record->id, 'fields' => ['region' => 'R1']];
    [$first, $second] = changeRacePair($this->processes, $revokeFirst ? $revoke : $update, $revokeFirst ? $update : $revoke);

    expect($first['status'])->toBe('applied')
        ->and($revokeFirst ? $second['error'] : $second['status'])->toBe($revokeFirst ? StaleSelectionException::class : 'applied')
        ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
})->with(['revoke first' => [true], 'update first' => [false]])->group('engines');

it('V101 C8 keeps tenant B out of a concurrent sync of tenant A', function (): void {
    $version = W::version();
    [$sync, $grant] = changeRacePair($this->processes,
        ['op' => 'sync', 'user' => 1, 'roles' => [], 'project' => 1, 'tenant' => 1],
        ['op' => 'grant', 'role' => 'seller', 'user' => 1, 'project' => 4, 'tenant' => 2]);

    expect([$sync['effects'], $grant['effects']])->toBe([1, 1])
        ->and(W::version())->toBe($version + 2)
        ->and(W::keys())->not->toContain('crm.organization:1|seller|1|crm.project:1|manual')
        ->and(W::keys())->toContain('crm.organization:2|analyst|1|crm.project:4|manual', 'crm.organization:2|seller|1|crm.project:4|manual');
})->group('engines');

it('V101 retries the root after a real deadlock with fresh pipes and writes once', function (): void {
    HostFencePipe::install('unused');
    $connection = CrmWorld::storage()->connection();
    $connection->getSchemaBuilder()->create('crm_weight', fn (Blueprint $table) => $table->string('value'));
    $version = W::version();
    $ready = changeRaceBarrier('ready');

    try {
        $connection->beginTransaction();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL deadlock_timeout = '5s'");
        }
        $connection->table('crm_active_build')->where('id', 1)->lockForUpdate()->first();
        $connection->table('crm_weight')->insert(array_fill(0, 100, ['value' => 'weight']));
        $worker = $this->processes->start(['op' => 'grant', 'role' => 'analyst', 'user' => 2, 'project' => 1, 'deadlock' => true, 'ready' => $ready], 'change-worker.php');
        Processes::wait($ready);
        $connection->table('azg_panel_state')->where('panel', 'crm')->lockForUpdate()->first();
        $connection->commit();
        $report = $this->processes->finish($worker);

        expect($report['error'])->toBeNull()
            ->and($report['pipes'])->toBe(2)
            ->and($report['effects'])->toBe(1)
            ->and(W::version())->toBe($version + 1)
            ->and(array_count_values(W::keys())['crm.organization:1|analyst|2|crm.project:1|manual'])->toBe(1);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        changeRaceCleanup([$ready]);
    }
})->group('engines');

it('V101 rolls back a change nested in a host transaction on the engine', function (): void {
    $connection = CrmWorld::storage()->connection();
    $version = W::version();
    $connection->beginTransaction();
    $pending = W::grant(W::panel(), 'analyst', 2, 1);
    $connection->rollBack();

    expect($pending->committed)->toBeFalse()->and($pending->state->version)->toBe($version + 1)
        ->and(W::version())->toBe($version)
        ->and(W::keys())->not->toContain('crm.organization:1|analyst|2|crm.project:1|manual');
})->group('engines');

it('R29 refuses a change prepared on the old build after a coordinated deployment and accepts the current build', function (): void {
    $fingerprint = changeRaceFenceFingerprint();
    HostFencePipe::install($fingerprint);
    $prepared = changeRaceBarrier('prepared');
    $start = changeRaceBarrier('start');
    $version = W::version();

    try {
        $worker = $this->processes->start(['op' => 'grant', 'role' => 'seller', 'user' => 1, 'project' => 5, 'fence' => true,
            'prepared' => $prepared, 'start' => $start], 'change-worker.php');
        Processes::wait($prepared);
        expect($this->processes->finish($this->processes->start(['mode' => 'deploy', 'marker' => 'next-build'], 'change-worker.php'))['status'])->toBe('deployed');
        touch($start);
        $refused = $this->processes->finish($worker);

        expect($refused['fingerprint'])->toBe($fingerprint)
            ->and($refused['error'])->toBe(ChangeCancelledException::class)
            ->and($refused['message'])->toContain('active build')
            ->and(W::version())->toBe($version)
            ->and(W::keys())->not->toContain('crm.organization:1|seller|1|crm.project:5|manual');

        expect($this->processes->finish($this->processes->start(['mode' => 'deploy', 'marker' => $fingerprint], 'change-worker.php'))['status'])->toBe('deployed')
            ->and($this->processes->finish($this->processes->start(['op' => 'grant', 'role' => 'seller', 'user' => 1, 'project' => 5, 'fence' => true],
                'change-worker.php'))['status'])->toBe('applied');
    } finally {
        changeRaceCleanup([$prepared, $start]);
    }
})->group('engines');

it('R29 makes a deployment wait for an in-flight fenced change, which commits on its build first', function (): void {
    $fingerprint = changeRaceFenceFingerprint();
    HostFencePipe::install($fingerprint);
    $ready = changeRaceBarrier('ready');
    $release = changeRaceBarrier('release');
    $id = changeRaceBarrier('id');

    try {
        $worker = $this->processes->start(['op' => 'grant', 'role' => 'seller', 'user' => 1, 'project' => 5, 'fence' => true,
            'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        $deploy = $this->processes->start(['mode' => 'deploy', 'marker' => 'next-build', 'id_file' => $id], 'change-worker.php');
        CacheEngineWorld::waitForLock(changeRaceWaitForId($id));
        touch($release);

        expect($this->processes->finish($worker)['status'])->toBe('applied')
            ->and($this->processes->finish($deploy)['status'])->toBe('deployed')
            ->and(CrmWorld::storage()->connection()->table('crm_active_build')->value('fingerprint'))->toBe('next-build');
    } finally {
        changeRaceCleanup([$ready, $release, $id]);
    }
})->group('engines');

it('R29 refuses a change when the host owner revision moved after preparation', function (): void {
    HostFencePipe::install(changeRaceFenceFingerprint());
    $prepared = changeRaceBarrier('prepared');
    $start = changeRaceBarrier('start');

    try {
        $worker = $this->processes->start(['op' => 'grant', 'role' => 'seller', 'user' => 1, 'project' => 5, 'fence' => true,
            'prepared' => $prepared, 'start' => $start], 'change-worker.php');
        Processes::wait($prepared);
        $this->processes->finish($this->processes->start(['mode' => 'deploy', 'revision' => 2], 'change-worker.php'));
        touch($start);
        $refused = $this->processes->finish($worker);

        expect($refused['error'])->toBe(ChangeCancelledException::class)
            ->and($refused['message'])->toContain('changed owner')
            ->and(W::keys())->not->toContain('crm.organization:1|seller|1|crm.project:5|manual');
    } finally {
        changeRaceCleanup([$prepared, $start]);
    }
})->group('engines');
