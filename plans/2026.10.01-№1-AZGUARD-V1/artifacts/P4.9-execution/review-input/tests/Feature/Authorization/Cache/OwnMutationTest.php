<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

it('sees tentative revoke before touch without publishing it on rollback', function (StateRefresh $refresh): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
    $request = DatabaseWorld::request();
    $before = $engine->decide($panel, $request);
    expect($before->allowed())->toBeTrue();
    $storage = DatabaseWorld::storage();
    $captured = null;

    expect(function () use ($storage, $engine, $panel, $request, &$captured): void {
        $storage->mutate('admin', function (StorageMutation $mutation) use ($engine, $panel, $request, $storage, &$captured): void {
            $captured = $storage->authorityTransaction('admin');
            $mutation->table('permission_grants')->delete();
            expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
            $mutation->touch('admin');
            expect($engine->decide($panel, $request)->allowed())->toBeFalse();

            throw new RuntimeException('rollback');
        });
    })->toThrow(RuntimeException::class, 'rollback');

    expect($engine->decide($panel, $request)->allowed())->toBeTrue()
        ->and($storage->state('admin')->version)->toBe($before->state->version)
        ->and($storage->authorityTransaction())->toBeNull();
    expect(fn () => $captured->assertActive())->toThrow(InvalidConfigurationException::class);
    app()->forgetScopedInstances();
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
})->with([StateRefresh::Request, StateRefresh::Check]);

it('publishes one root revision for nested mutations and only invokes callbacks after commit', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $before = $engine->decide($panel, DatabaseWorld::request());
    $events = [];
    $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel, $storage, &$events): void {
        for ($i = 0; $i < 2; $i++) {
            $storage->mutate('admin', function (StorageMutation $mutation) use (&$events): void {
                $mutation->table('permission_grants')->delete();
                $mutation->touch('admin');
                $mutation->afterCommit(static function () use (&$events): void {
                    $events[] = 'committed';
                });
            });
        }
        expect($events)->toBe([])->and($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
    });
    expect($events)->toBe(['committed', 'committed'])
        ->and($storage->state('admin')->version)->toBe($before->state->version + 1)
        ->and($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
});

it('does not publish rolled back savepoint touches or callbacks into the supported root', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $version = $storage->state('admin')->version;
    $called = false;
    $engine->withinAuthorityTransaction($panel, function () use ($storage, $engine, $panel, &$called): void {
        try {
            $storage->mutate('admin', function (StorageMutation $mutation) use (&$called): void {
                $mutation->table('permission_grants')->delete();
                $mutation->touch('admin');
                $mutation->afterCommit(static function () use (&$called): void {
                    $called = true;
                });

                throw new RuntimeException('savepoint rollback');
            });
        } catch (RuntimeException) {
        }
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    });
    expect($called)->toBeFalse()->and($storage->state('admin')->version)->toBe($version);
});

it('pins tentative Default reads to the root write PDO and fences the dynamic overlay with assignments', function (): void {
    $source = DatabaseSource::make()->dynamicPermissions();
    [$engine, $panel] = CacheWorld::database($source, reads: Reads::Default);
    $storage = DatabaseWorld::storage();
    $replica = new PDO('sqlite::memory:');
    $storage->connection()->setReadPdo($replica);
    $engine->withinAuthorityTransaction($panel, function () use ($storage, $source, $panel): void {
        DatabaseWorld::define('reports.export');
        [, $frame] = DatabaseWorld::compile($source, reads: Reads::Default);
        $session = $source->openReadSession($frame);
        expect($session->handleIdentity())->toBe(spl_object_id($storage->connection()->getPdo()))
            ->and(array_column($source->readPermissions($session, $panel, $frame->scope()->tenant), 'local'))->toContain('reports.export');
    });
});

it('does not publish a tentative allow under an unchanged token into request or durable stores', function (): void {
    $storage = DatabaseWorld::storage();
    $storage->mutate('admin', function (StorageMutation $m): void {
        $m->table('permission_grants')->delete();
        $m->touch('admin');
    });
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
    expect(function () use ($storage, $engine, $panel): void {
        $storage->mutate('admin', function (StorageMutation $m) use ($engine, $panel): void {
            $m->table('permission_grants')->insert(DatabaseWorld::row('permission'));
            expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();

            // No touch: tentative set would collide with the committed deny's token.
            throw new RuntimeException('rollback allow');
        });
    })->toThrow(RuntimeException::class, 'rollback allow');
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
});
