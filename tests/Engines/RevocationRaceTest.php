<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\AuthorityProcess;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(fn () => CacheEngineWorld::seed());
afterEach(fn () => CacheEngineWorld::clean());

it('V18 R52 observes independent root commit with Primary check and new request; records request window', function (StateRefresh $refresh): void {
    $worker = new AuthorityProcess;

    try {
        [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
        $before = $engine->decide($panel, DatabaseWorld::request());
        expect($before->allowed())->toBeTrue();
        $commit = $worker->command(['mode' => 'revoke']);
        expect($commit['committed'])->toBeTrue();

        if ($refresh === StateRefresh::Request) {
            expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
            app()->forgetScopedInstances();
        }
        $after = $engine->decide($panel, DatabaseWorld::request());
        expect($after->reason)->toBe(DecisionReason::NotGranted)
            ->and($after->state->version())->toBe($commit['version'])
            ->and($after->state->version())->toBeGreaterThan($before->state->version());
    } finally {
        $worker->close();
    }
})->with([StateRefresh::Request, StateRefresh::Check])->group('engines');

it('rolls back protected host work before a waiting independent revoke commits', function (): void {
    $worker = new AuthorityProcess;
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check);
    $storage = DatabaseWorld::storage();
    $connection = $storage->connection();
    $original = $connection->table('users')->where('id', 1)->value('department');
    $version = $storage->state('admin')->version;

    try {
        try {
            $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel, $connection, $worker): void {
                $worker->send(['mode' => 'revoke']);
                CacheEngineWorld::waitForLock($worker->connectionId);
                expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
                $connection->table('users')->where('id', 1)->update(['department' => 'rolled-back']);

                throw new RuntimeException('abort host work');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('abort host work');
        }
        $commit = $worker->receive();
        app()->forgetScopedInstances();
        expect($commit['committed'])->toBeTrue()
            ->and($commit['version'])->toBe($version + 1)
            ->and($connection->table('users')->where('id', 1)->value('department'))->toBe($original)
            ->and($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    } finally {
        $worker->close();
    }
})->group('engines');
