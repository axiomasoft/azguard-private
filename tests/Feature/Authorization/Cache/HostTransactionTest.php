<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

it('locks panel state before host work and refreshes live subject policies on the joint write handle', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->grantConditions([new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            expect($context)->toBeInstanceOf(EvaluationFrame::class);
            expect($context->authorityTransaction)->not->toBeNull();

            return $context->subjectModel()?->getAttribute('department') === 'sales';
        }
    }]));
    $storage = DatabaseWorld::storage();
    $before = $storage->state('admin')->version;
    $result = $engine->withinAuthorityTransaction($panel, function () use ($storage, $engine, $panel): string {
        expect($storage->authorityTransaction('admin'))->not->toBeNull();
        $source = DatabaseSource::make();
        [, $frame] = DatabaseWorld::compile($source);
        expect($source->openReadSession($frame)->transaction())->toBe($storage->authorityTransaction('admin'));
        $storage->connection()->table('users')->where('id', 1)->update(['department' => 'legal']);
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
        $storage->connection()->table('users')->where('id', 1)->update(['department' => 'sales']);
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();

        return 'host result';
    });
    expect($result)->toBe('host result')->and($storage->state('admin')->version)->toBe($before)
        ->and($storage->authorityTransaction())->toBeNull();
});

it('refuses joint helper entry inside an unknown or recognized open transaction', function (bool $recognized): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $entry = fn () => $engine->withinAuthorityTransaction($panel, static fn (): string => 'unreachable');

    if ($recognized) {
        $storage->mutate('admin', function () use ($entry): void {
            expect($entry)->toThrow(InvalidConfigurationException::class);
        });
    } else {
        $storage->connection()->transaction(function () use ($entry, $storage, $engine, $panel): void {
            expect($entry)->toThrow(InvalidConfigurationException::class);
            $storage->mutate('admin', function () use ($engine, $panel): void {
                expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::SourceError);
            });
        });
    }
})->with([false, true]);

it('rejects a marker in another Fiber or for an unlocked panel without adopting that transaction', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $storage->mutate('admin', function () use ($storage, $engine, $panel): void {
        expect(fn () => $storage->authorityTransaction('other'))->toThrow(InvalidConfigurationException::class);
        $fiber = new Fiber(fn () => $engine->decide($panel, DatabaseWorld::request()));
        $fiber->start();
        expect($fiber->getReturn()->reason)->toBe(DecisionReason::SourceError);
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    });
});

it('cannot reuse a capability after root rollback and replacement at the same transaction depth', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    expect(function () use ($storage, $engine, $panel): void {
        $storage->mutate('admin', function () use ($storage, $engine, $panel): void {
            $connection = $storage->connection();
            $connection->rollBack();
            $connection->beginTransaction();
            expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::SourceError);
        });
    })->toThrow(InvalidConfigurationException::class);
    expect($storage->authorityTransaction())->toBeNull()->and($storage->connection()->transactionLevel())->toBe(0);
});

it('retains an early before veto without inspecting DB authority in another execution context', function (): void {
    $source = DatabaseSource::make();
    [$panel] = DatabaseWorld::compile($source, before: fn () => BeforeResult::Deny);
    $engine = app(Authorizer::class);
    DatabaseWorld::storage()->mutate('admin', function () use ($engine, $panel): void {
        $fiber = new Fiber(fn () => $engine->decide($panel, DatabaseWorld::request()));
        $fiber->start();
        expect($fiber->getReturn()->reason)->toBe(DecisionReason::Hook);
    });
});
