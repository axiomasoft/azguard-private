<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Events\AccessEvent;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

uses()->group('properties');

it('P16 successful no-op retry and rollback mutations publish only the committed root across 200 seeds', function (): void {
    app(StorageSchema::class)->create('default');
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $outcomes = array_fill_keys(['commit', 'noop', 'retry', 'rollback'], 0);
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        $storage->mutate('admin', function (StorageMutation $m): void {
            $m->table('permission_grants')->delete();
            $m->table('permission_grants')->insert(DatabaseWorld::row('permission'));
            $m->touch('admin');
        });
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue("warm seed=$seed");
        $before = $storage->state('admin')->version;
        $mode = ['commit', 'noop', 'retry', 'rollback'][$seed % 4];
        $outcomes[$mode]++;
        $attempt = 0;
        $published = 0;
        $markers = [];

        try {
            $storage->mutate('admin', function (StorageMutation $m) use ($mode, &$attempt, &$published, &$markers, $storage, $engine, $panel, $seed): void {
                $attempt++;
                $markers[] = $storage->authorityTransaction('admin');

                if ($mode === 'noop') {
                    return;
                }
                $m->table('permission_grants')->delete();
                $m->touch('admin');
                $m->afterCommit(static function () use (&$published): void {
                    $published++;
                });
                expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse("tentative seed=$seed attempt=$attempt");

                if ($mode === 'rollback') {
                    throw new RuntimeException('intentional rollback');
                }

                if ($mode === 'retry' && $attempt === 1) {
                    throw new PDOException('deadlock detected');
                }
            });
        } catch (RuntimeException $e) {
            expect($mode)->toBe('rollback');
        }
        $commits = in_array($mode, ['commit', 'retry'], true);
        expect($storage->state('admin')->version)->toBe($before + (int) $commits, "version seed=$seed")
            ->and($published)->toBe((int) $commits, "publication seed=$seed")
            ->and($attempt)->toBe($mode === 'retry' ? 2 : 1)
            ->and($engine->decide($panel, DatabaseWorld::request())->allowed())->toBe(! $commits, "decision seed=$seed")
            ->and($storage->authorityTransaction())->toBeNull();

        if ($mode === 'retry') {
            expect($markers[0])->not->toBe($markers[1]);
        }
    }
    expect($outcomes)->toBe(['commit' => 50, 'noop' => 50, 'retry' => 50, 'rollback' => 50]);
});

it('P16b participant rows become durable exactly with the committed root of a write operation across 100 seeds', function (): void {
    // The CRM stand brings its own users table instead of the one of this directory.
    Schema::dropIfExists('users');
    CrmWorld::seed();
    AuditTable::create();
    EventWorld::listen();
    $control = new class
    {
        public string $mode = 'commit';

        public int $attempts = 0;
    };
    $panel = W::panel(configure: static fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([AuditPlugin::make()])
        ->participants([new class($control) implements ChangeParticipant
        {
            public function __construct(private readonly object $control) {}

            public function tables(): array
            {
                return [];
            }

            public function record(array $records, OwnedRowsWriter $rows): void
            {
                $this->control->attempts++;

                if ($this->control->mode === 'rollback') {
                    throw new RuntimeException('intentional rollback');
                }

                if ($this->control->mode === 'retry' && $this->control->attempts === 1) {
                    throw new QueryException('testbench', 'update azg_panel_state', [], new PDOException('Deadlock found when trying to get lock'));
                }
            }
        }]));
    $connection = CrmWorld::storage()->connection();
    $held = false;
    $base = count(W::keys());
    $outcomes = array_fill_keys(['commit', 'noop', 'retry', 'rollback', 'host-rollback'], 0);

    try {
        foreach (range(0, 99) as $seed) {
            $control->mode = ['commit', 'noop', 'retry', 'rollback', 'host-rollback'][$seed % 5];
            $control->attempts = 0;
            $outcomes[$control->mode]++;
            $rows = count(EventWorld::auditRows());
            $events = count(EventWorld::events());
            // A no-op repeats the stored state; every other mode flips it.
            $grant = $control->mode === 'noop' ? $held : ! $held;
            $operation = static fn () => $grant ? W::grant($panel, 'analyst', 2, 1) : W::revoke($panel, 'analyst', 2, 1);

            try {
                if ($control->mode === 'host-rollback') {
                    $connection->beginTransaction();

                    try {
                        $operation();
                        expect(count(EventWorld::auditRows()))->toBe($rows + 1, "joined host seed=$seed");
                    } finally {
                        $connection->rollBack();
                    }
                } else {
                    $operation();
                }
            } catch (RuntimeException $error) {
                expect($control->mode)->toBe('rollback', "seed=$seed ".$error->getMessage());
            }
            $commits = in_array($control->mode, ['commit', 'retry'], true);
            $held = $commits ? ! $held : $held;
            $new = array_slice(EventWorld::auditRows(), $rows);

            expect($new)->toHaveCount((int) $commits, "rows seed=$seed")
                ->and(array_column($new, 'event_id'))->toBe(array_map(static fn (AccessEvent $event): string => $event->eventId, array_slice(EventWorld::events(), $events)), "events seed=$seed")
                ->and($control->attempts)->toBe(match ($control->mode) {
                    'noop' => 0, 'retry' => 2, default => 1,
                }, "attempts seed=$seed")
                ->and(count(W::keys()))->toBe($base + (int) $held, "state seed=$seed");
        }
    } finally {
        CrmWorld::resetRuntime();
        EventWorld::reset();
    }
    expect($outcomes)->toBe(['commit' => 20, 'noop' => 20, 'retry' => 20, 'rollback' => 20, 'host-rollback' => 20]);
});
