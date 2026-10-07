<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\AuthorityProcess;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(fn () => CacheEngineWorld::seed());
afterEach(fn () => CacheEngineWorld::clean());

it('V99 rejects a real old repeatable-read host snapshot after independent revoke', function (): void {
    $worker = new AuthorityProcess;
    $storage = DatabaseWorld::storage();
    $connection = $storage->connection();
    $source = DatabaseSource::make();
    [$engine, $panel] = CacheWorld::database($source);
    $warm = $engine->decide($panel, DatabaseWorld::request());

    if ($connection->getDriverName() !== 'pgsql') {
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $connection->beginTransaction();

    try {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        expect($storage->table('permission_grants')->count())->toBe(1);
        $worker->command(['mode' => 'revoke']);
        expect($storage->table('permission_grants')->count())->toBe(1);
        [, $frame] = DatabaseWorld::compile($source);
        expect(fn () => $source->openReadSession($frame))->toThrow(InvalidConfigurationException::class)
            ->and($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::SourceError);
    } finally {
        $connection->rollBack();
        $worker->close();
    }
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
})->group('engines');

it('V99 lock-first joint protocol commits protected host work while independent revoke waits at panel lock', function (): void {
    $worker = new AuthorityProcess;
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();

    try {
        $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel, $storage, $worker): void {
            expect($storage->authorityTransaction('admin'))->not->toBeNull();
            $worker->send(['mode' => 'revoke']);
            CacheEngineWorld::waitForLock($worker->connectionId);
            expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
            $storage->connection()->table('users')->where('id', 1)->update(['department' => 'protected-commit']);
        });
        expect($worker->receive()['committed'])->toBeTrue()
            ->and($storage->connection()->table('users')->where('id', 1)->value('department'))->toBe('protected-commit');
        app()->forgetScopedInstances();
        expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    } finally {
        $worker->close();
    }
})->group('engines');
