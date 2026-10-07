<?php

declare(strict_types=1);

use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

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
