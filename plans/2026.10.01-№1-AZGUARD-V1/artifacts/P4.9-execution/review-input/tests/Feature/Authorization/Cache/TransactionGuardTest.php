<?php

declare(strict_types=1);

use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

it('rejects a consumed DB authority in an unknown transaction even with a warm cache', function (bool $warm): void {
    $source = DatabaseSource::make();
    [$engine, $panel] = CacheWorld::database($source);

    if ($warm) {
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    }
    $connection = DatabaseWorld::storage()->connection();
    $connection->beginTransaction();

    try {
        $budget = CacheWorld::emptyBudget();
        CacheWorld::listen($budget);
        $decision = $engine->decide($panel, DatabaseWorld::request()->traced());
        expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError)
            ->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
        [, $frame] = DatabaseWorld::compile($source);

        try {
            $source->openReadSession($frame);
            test()->fail('An unknown authority transaction must fail configuration validation.');
        } catch (InvalidConfigurationException $error) {
            expect($error->code())->toBe('invalid_configuration.authority_transaction');
        }
    } finally {
        $connection->rollBack();
    }
})->with([false, true]);

it('does not inspect assignment transactions for static PolicyOnly', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make()->dynamicPermissions());
    $connection = DatabaseWorld::storage()->connection();
    $connection->beginTransaction();

    try {
        $budget = CacheWorld::emptyBudget();
        CacheWorld::listen($budget);
        expect($engine->decide($panel, DatabaseWorld::request(DatabasePermission::Policy))->allowed())->toBeTrue()
            ->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
    } finally {
        $connection->rollBack();
    }
});

it('does not inspect unrelated assignment transactions for code authority', function (): void {
    $source = new CacheSource(name: 'code');
    $source->reuse = Volatility::Request;
    $source->direct = [AuthorizationWorld::grant()];
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array'));
    $connection = DatabaseWorld::storage()->connection();
    $connection->beginTransaction();

    try {
        $budget = CacheWorld::emptyBudget();
        CacheWorld::listen($budget);
        expect($engine->decide($panel, $request)->allowed())->toBeTrue()->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
    } finally {
        $connection->rollBack();
    }
});

it('qualifies real relation-only authority without inspecting the assignment transaction', function (): void {
    RelationWorld::seed();
    RelationWorld::attach(RelationWorld::project());
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [, $frame, $registry] = RelationWorld::compile([$source]);
    $connection = DatabaseWorld::storage()->connection();
    $connection->beginTransaction();

    try {
        $budget = CacheWorld::emptyBudget();
        CacheWorld::listen($budget);
        [, $qualified, $denial] = app(AuthorityStage::class)->qualify(RelationWorld::request(), $frame, $registry->catalog('admin'), new Trace(false));
        expect($qualified)->toBeTrue()->and($denial)->toBeNull()->and($budget['state'])->toBe(0)->and($budget['grants'])->toBe(0);
    } finally {
        $connection->rollBack();
        RelationWorld::reset();
    }
});
