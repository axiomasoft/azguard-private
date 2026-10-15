<?php

declare(strict_types=1);

use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\Internal\JournalPruner;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CrmWorld::seed();
    AuditTable::create();
});
afterEach(function (): void {
    app()->instance('env', 'testing');
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Journal rows of the crm panel written on 2026-10-01, 2026-10-04 and 2026-10-06 (retention 3 days: one is old). */
function auditPruneJournal(): void
{
    $panel = W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make(3)]));
    foreach (['2026-10-01T09:00:00Z', '2026-10-04T09:00:00Z', '2026-10-06T09:00:00Z'] as $i => $moment) {
        Carbon::setTestNow($moment);
        W::grant($panel, 'auditor', $i + 1, null);
    }
    Carbon::setTestNow('2026-10-06T12:00:00Z');
}

/** @return list<string> */
function auditPruneMoments(): array
{
    return CrmWorld::storage()->table('audit_log')->orderBy('id')->pluck('occurred_at')->map(static fn (string $at): string => substr($at, 0, 10))->all();
}

it('deletes journal rows older than the retention of the audit plugin', function (): void {
    auditPruneJournal();

    expect(auditPruneMoments())->toBe(['2026-10-01', '2026-10-04', '2026-10-06'])
        ->and(Artisan::call('azguard:audit:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Panel crm: 1 journal row(s)')
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06'])
        ->and(W::keys())->toContain('crm.organization:1|auditor|1|global|manual');
});

it('moves the cut-off back with --before but never past the retention', function (): void {
    auditPruneJournal();

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm', '--before' => '2026-10-06T10:00:00Z']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06'])
        ->and(Artisan::call('azguard:audit:prune', ['--panel' => 'crm', '--before' => '2026-09-30T00:00:00Z']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06']);
});

it('has nothing to prune on a panel without the audit plugin', function (): void {
    W::panel();

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(0)
        ->and(Artisan::output())->toContain('no journal to prune');
});

it('needs --force in production', function (): void {
    auditPruneJournal();
    app()->instance('env', 'production');

    expect(Artisan::call('azguard:audit:prune'))->toBe(2)
        ->and(auditPruneMoments())->toHaveCount(3)
        ->and(Artisan::call('azguard:audit:prune', ['--force' => true]))->toBe(0)
        ->and(auditPruneMoments())->toHaveCount(2);
});

/** Journal rows inserted directly: `$old` of panel crm long ago, one of crm exactly at the cut-off, two of panel ops. */
function auditPruneRows(int $old): void
{
    $row = static fn (int $n, string $panel, string $at): array => ['event_id' => sprintf('01k6%022d', $n), 'type' => 'role.granted', 'panel' => $panel,
        'tenant_key' => 'global', 'correlation_id' => sprintf('01k5%022d', $n), 'payload' => '{}', 'occurred_at' => $at];
    $rows = array_map(static fn (int $n): array => $row($n, 'crm', '2026-09-01 00:00:00'), range(1, $old));
    $rows[] = $row($old + 1, 'crm', '2026-10-01 00:00:00');
    $rows[] = $row($old + 2, 'ops', '2026-09-01 00:00:00');
    $rows[] = $row($old + 3, 'ops', '2026-09-02 00:00:00');

    foreach (array_chunk($rows, 500) as $chunk) {
        CrmWorld::storage()->table('audit_log')->insert($chunk);
    }
}

it('prunes in batches only the rows of the panel before the cut-off and keeps the row at the cut-off', function (): void {
    auditPruneRows(JournalPruner::BATCH * 2 + 7);
    $deletes = 0;
    DB::listen(static function (QueryExecuted $query) use (&$deletes): void {
        $deletes += str_starts_with(strtolower($query->sql), 'delete') ? 1 : 0;
    });

    expect(JournalPruner::prune('crm', AzGuard::storage(), new DateTimeImmutable('2026-10-01T00:00:00Z')))->toBe(JournalPruner::BATCH * 2 + 7)
        ->and($deletes)->toBe(3)
        ->and(auditPruneMoments())->toBe(['2026-10-01', '2026-09-01', '2026-09-02'])
        ->and(CrmWorld::storage()->table('audit_log')->where('panel', 'ops')->count())->toBe(2);
});

it('prunes without the authority lock, a revision, a panel state change or an event', function (): void {
    auditPruneJournal();
    $state = CrmWorld::storage()->table('panel_state')->orderBy('panel')->get()->map(static fn (object $row): array => (array) $row)->all();
    $revisions = CrmWorld::storage()->table('subject_revisions')->orderBy('subject_id')->get()->map(static fn (object $row): array => (array) $row)->all();
    Event::fake();
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06'])
        ->and(array_values(array_filter($queries, static fn (string $sql): bool => ! str_contains($sql, 'audit_log'))))->toBe([])
        ->and(CrmWorld::storage()->table('panel_state')->orderBy('panel')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($state)
        ->and(CrmWorld::storage()->table('subject_revisions')->orderBy('subject_id')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($revisions);
    Event::assertNothingDispatched();
});

it('retries a batch that hits a deadlock or a serialization failure, then fails with exit 1 and keeps the rows of that batch', function (int $failures, int $exit, int $left): void {
    auditPruneJournal();
    $remaining = $failures;
    CrmWorld::storage()->connection()->beforeExecuting(static function (string $sql) use (&$remaining): void {
        if (str_starts_with(strtolower($sql), 'delete') && $remaining-- > 0) {
            $deadlock = new PDOException('Deadlock found when trying to get lock');
            $deadlock->errorInfo = $remaining % 2 === 0 ? ['40001', 1213, 'Deadlock found'] : ['40P01', 7, 'deadlock detected'];

            throw new QueryException('testbench', $sql, [], $deadlock);
        }
    });

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe($exit)
        ->and(auditPruneMoments())->toHaveCount($left);
})->with([
    'three retries succeed' => [JournalPruner::RETRIES, 0, 2],
    'a fourth failure fails the command' => [JournalPruner::RETRIES + 1, 1, 3],
]);

it('does not retry any other database error', function (): void {
    auditPruneJournal();
    $attempts = 0;
    CrmWorld::storage()->connection()->beforeExecuting(static function (string $sql) use (&$attempts): void {
        if (str_starts_with(strtolower($sql), 'delete')) {
            $attempts++;
            $error = new PDOException('no such column');
            $error->errorInfo = ['HY000', 1, 'no such column'];

            throw new QueryException('testbench', $sql, [], $error);
        }
    });

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(1)
        ->and($attempts)->toBe(1);
});

it('prunes the journal of the storage it was created on after the default connection changes', function (): void {
    auditPruneJournal();
    $descriptor = AzGuard::storage();
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $other = DB::connection('secondary');
    $other->getSchemaBuilder()->create('azg_audit_log', static function (Blueprint $table): void {
        $table->id();
        $table->string('panel');
        $table->dateTime('occurred_at');
    });
    $other->table('azg_audit_log')->insert(['panel' => 'crm', 'occurred_at' => '2026-01-01 00:00:00']);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('secondary');

    try {
        expect($descriptor->connection)->toBe(CrmWorld::storage()->connectionName())->not->toBe('secondary')
            ->and(AzGuard::storage()->connection)->toBe($descriptor->connection)
            ->and(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(0)
            ->and($other->table('azg_audit_log')->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($default);
    }
    expect(auditPruneMoments())->toBe(['2026-10-04', '2026-10-06']);
});

it('refuses a retention shorter than one day in every way to build the options', function (int $days): void {
    expect(fn () => new AuditOptions($days))->toThrow(InvalidArgumentException::class, 'at least 1')
        ->and(fn () => AuditPlugin::make($days))->toThrow(InvalidArgumentException::class, 'at least 1');
})->with([0, -1, -10]);

it('keeps a fresh row when the options are built with the smallest retention', function (): void {
    $panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()])->options(new AuditOptions(1)));
    Carbon::setTestNow('2026-10-06T12:00:00Z');
    W::grant($panel, 'auditor', 1, null);

    expect(Artisan::call('azguard:audit:prune', ['--panel' => 'crm']))->toBe(0)
        ->and(auditPruneMoments())->toBe(['2026-10-06']);
});
