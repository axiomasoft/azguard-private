<?php

declare(strict_types=1);

use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\Facades\AzGuard;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * Journal retention without the authority lock (P3.6, knowledge/p3-execution#persistence) on PostgreSQL, MySQL and
 * MariaDB with independent processes: pruning deletes only rows of its panel before the cut-off, never loses a row a
 * concurrent write journals, deletes each row once when two prunes race, and never waits for the panel lock.
 */

const JOURNAL_RACE_OLD = 2500;

function journalRaceDrop(): void
{
    $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
    foreach (['crm_weight', 'crm_active_build', 'crm_project_revisions', 'clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
        $schema->dropIfExists($table);
    }
    AuditSchema::drop(AzGuard::storage());
    app(StorageSchema::class)->drop('default');
}

/** Journal rows written long before the cut-off: `JOURNAL_RACE_OLD` of panel crm, 10 of panel ops. */
function journalRaceSeedOld(): void
{
    $rows = [];

    foreach (range(1, JOURNAL_RACE_OLD + 10) as $n) {
        $rows[] = ['event_id' => sprintf('01k6%022d', $n), 'type' => 'role.granted', 'panel' => $n > JOURNAL_RACE_OLD ? 'ops' : 'crm',
            'tenant_key' => 'global', 'correlation_id' => sprintf('01k5%022d', $n), 'payload' => '{}', 'occurred_at' => '2026-09-01 00:00:00'];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        CrmWorld::storage()->table('audit_log')->insert($chunk);
    }
}

function journalRaceCount(string $panel, ?string $before = null): int
{
    $query = CrmWorld::storage()->table('audit_log')->where('panel', $panel);

    return ($before === null ? $query : $query->where('occurred_at', '<', $before))->count();
}

function journalRaceBarrier(): string
{
    return sys_get_temp_dir().'/azguard-journal-race-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    journalRaceDrop();
    CrmWorld::seed();
    AuditTable::create();
    journalRaceSeedOld();
    $this->processes = new Processes;
});
afterEach(function (): void {
    journalRaceDrop();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('prunes old rows while a concurrent grant journals new ones: no new row is lost and the writer sees no deadlock', function (): void {
    $start = journalRaceBarrier();

    try {
        $writer = $this->processes->start(['mode' => 'write', 'rounds' => 20, 'start' => $start], 'prune-worker.php');
        $pruner = $this->processes->start(['mode' => 'prune', 'cutoff' => '2026-10-01T00:00:00Z', 'start' => $start], 'prune-worker.php');
        touch($start);
        [$written, $pruned] = [$this->processes->finish($writer), $this->processes->finish($pruner)];
    } finally {
        @unlink($start);
    }

    expect([$written['error'], $pruned['error']])->toBe([null, null])
        ->and($pruned['deleted'])->toBe(JOURNAL_RACE_OLD)
        ->and($written['effects'])->toBe(40)
        ->and(journalRaceCount('crm', '2026-10-01 00:00:00'))->toBe(0)
        ->and(journalRaceCount('crm'))->toBe(40)
        ->and(journalRaceCount('ops'))->toBe(10);
})->group('engines');

it('deletes every row once when two prunes of one panel race, and both succeed', function (): void {
    $start = journalRaceBarrier();

    try {
        $first = $this->processes->start(['mode' => 'prune', 'cutoff' => '2026-10-01T00:00:00Z', 'start' => $start], 'prune-worker.php');
        $second = $this->processes->start(['mode' => 'prune', 'cutoff' => '2026-10-01T00:00:00Z', 'start' => $start], 'prune-worker.php');
        touch($start);
        [$a, $b] = [$this->processes->finish($first), $this->processes->finish($second)];
    } finally {
        @unlink($start);
    }

    expect([$a['error'], $b['error']])->toBe([null, null])
        ->and($a['deleted'] + $b['deleted'])->toBe(JOURNAL_RACE_OLD)
        ->and(journalRaceCount('crm'))->toBe(0)
        ->and(journalRaceCount('ops'))->toBe(10);
})->group('engines');

it('does not wait for a host transaction that holds the authority lock of the panel', function (): void {
    $connection = CrmWorld::storage()->connection();
    CrmWorld::storage()->table('panel_state')->insertOrIgnore(['panel' => 'crm', 'version' => 0, 'incarnation' => '01k70000000000000000000000', 'updated_at' => '2026-10-06 12:00:00', 'epoch' => 0]);
    $connection->beginTransaction();

    try {
        expect(CrmWorld::storage()->table('panel_state')->where('panel', 'crm')->lockForUpdate()->first())->not->toBeNull();
        $pruner = $this->processes->start(['mode' => 'prune', 'cutoff' => '2026-10-01T00:00:00Z'], 'prune-worker.php');
        $pruned = $this->processes->finish($pruner);
    } finally {
        $connection->rollBack();
    }

    expect($pruned['error'])->toBe(null)
        ->and($pruned['deleted'])->toBe(JOURNAL_RACE_OLD)
        ->and($pruned['seconds'])->toBeLessThan(10.0);
})->group('engines');
