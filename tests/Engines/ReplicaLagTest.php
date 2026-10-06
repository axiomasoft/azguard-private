<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\ReplicaStand;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Events\QueryExecuted;

it('V46 R52 proves real paused-replay Default window and fresh Primary deny until catch-up LSN', function (): void {
    if (! getenv('AUTHORITY_REPLICA_TEST')) {
        $this->markTestSkipped('Run bash tests/Engines/Support/replica-fixture.sh against the authority-replica profile.');
    }
    $primary = ReplicaStand::connect(false);
    $replica = ReplicaStand::connect(true);
    $paused = false;

    try {
        CacheEngineWorld::seed();
        ReplicaStand::catchUp($replica, $primary->query('select pg_current_wal_flush_lsn()')->fetchColumn());
        $connection = DatabaseWorld::storage()->connection();
        $connection->setReadPdo($replica)->setRecordModificationState(true)->useWriteConnectionWhenReading();
        [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, reads: Reads::Default);
        $before = $engine->decide($panel, DatabaseWorld::request());
        expect($before->allowed())->toBeTrue();
        $replica->query('select pg_wal_replay_pause()');
        $paused = true;
        ReplicaStand::until($replica, "select pg_get_wal_replay_pause_state() = 'paused'");
        $commit = hrtime(true);
        DatabaseWorld::storage()->mutate('admin', function ($mutation): void {
            $mutation->table('permission_grants')->where('panel', 'admin')->delete();
            $mutation->touch('admin');
        });
        $lsn = $primary->query('select pg_current_wal_flush_lsn()')->fetchColumn();
        expect((int) $replica->query('select count(*) from azg_permission_grants')->fetchColumn())->toBe(1)
            ->and((int) $primary->query('select count(*) from azg_permission_grants')->fetchColumn())->toBe(0);
        // A new request on the real standby must use its stale state AND stale grants.
        app()->forgetScopedInstances();
        app('cache')->store('array')->clear();
        $tables = [];
        $connection->listen(function (QueryExecuted $query) use (&$tables, $replica): void {
            if (preg_match('/azg_(panel_state|permission_grants|role_grants)["`]/', $query->sql, $m) && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                expect($query->connection->getPdo())->toBe($replica);
                $tables[] = $m[1];
            }
        });
        $stale = $engine->decide($panel, DatabaseWorld::request());
        expect($stale->allowed())->toBeTrue()->and($stale->state->equals($before->state))->toBeTrue()
            ->and($tables)->toContain('panel_state', 'permission_grants', 'role_grants');
        $connection->getEventDispatcher()->forget(QueryExecuted::class);
        [$strict, $strictPanel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, reads: Reads::Primary);
        $fresh = $strict->decide($strictPanel, DatabaseWorld::request());
        expect($fresh->reason)->toBe(DecisionReason::NotGranted)->and($fresh->state->version)->toBeGreaterThan($stale->state->version);
        $replica->query('select pg_wal_replay_resume()');
        $paused = false;
        ReplicaStand::catchUp($replica, $lsn);
        app()->forgetScopedInstances();
        expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
        $path = getenv('AZGUARD_QUALIFICATION_ARTIFACT_DIR');

        if ($path) {
            file_put_contents($path.'/replica-window.json', json_encode(['commit_lsn' => $lsn,
                'replay_lsn' => $replica->query('select pg_last_wal_replay_lsn()')->fetchColumn(),
                'controlled_window_ms' => (hrtime(true) - $commit) / 1e6, 'default_paused' => 'allow', 'primary_paused' => 'deny', 'default_caught_up' => 'deny'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    } finally {
        if ($paused) {
            $replica->query('select pg_wal_replay_resume()');
        }
        CacheEngineWorld::clean();
    }
})->group('replica');
