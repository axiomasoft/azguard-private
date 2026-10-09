<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\ConcurrentWriter;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

// The boundary of a consistent read (audits/2026-10-09-consistency-design.md, step 1): host inputs are prepared once,
// the sources are materialized, the decision is evaluated once. A write that commits on another connection while
// the engine reads never runs a host hook, a policy or a model event again.

beforeEach(function (): void {
    ConcurrentWriter::open();
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();
    DatabaseWorld::insert('role', [DatabaseWorld::row()]);
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
    DatabaseWorld::storage()->connection()->getSchemaBuilder()->dropIfExists('users');
    ConcurrentWriter::close();
    Relation::morphMap([], false);
});

it('runs host hooks and model events once when another connection commits during the source read', function (): void {
    $before = $after = 0;
    [$panel] = DatabaseWorld::compile(DatabaseSource::make(), before: function () use (&$before): BeforeResult {
        $before++;

        return BeforeResult::Continue;
    }, after: function () use (&$after): void {
        $after++;
    });
    $retrieved = 0;
    Event::listen('eloquent.retrieved: '.app(AzGuardConfig::class)->defaultModels()['role_grant'], function () use (&$retrieved): void {
        $retrieved++;
    });
    $written = false;
    $grantReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$written, &$grantReads): void {
        if (! str_contains($event->sql, 'azg_role_grants') || ! str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
            return;
        }
        $grantReads++;

        if (! $written) {
            $written = true;
            ConcurrentWriter::commit(function (Connection $connection): void {
                $connection->table('azg_role_grants')->delete();
                ConcurrentWriter::touch($connection);
            });
        }
    });
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    // The fenced read retried the raw rows only: the second read saw the revoke, nothing was hydrated twice.
    expect($written)->toBeTrue()->and($before)->toBe(1)->and($after)->toBe(1)->and($grantReads)->toBe(2)
        ->and($retrieved)->toBe(0)->and($decision->reason)->toBe(DecisionReason::NotGranted)
        ->and($decision->state->version)->toBe(DatabaseWorld::storage()->state('admin')->version);
});

it('fails a hydration error as a source error without reading the source again', function (): void {
    [$panel] = DatabaseWorld::compile(DatabaseSource::make());
    Event::listen('eloquent.retrieved: '.app(AzGuardConfig::class)->defaultModels()['role_grant'], static function (): never {
        throw new RuntimeException('cast failed');
    });
    $grantReads = 0;
    DatabaseWorld::storage()->connection()->listen(function (QueryExecuted $event) use (&$grantReads): void {
        if (str_contains($event->sql, 'azg_role_grants') && str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
            $grantReads++;
        }
    });
    $decision = app(Authorizer::class)->decide($panel, DatabaseWorld::request());

    expect($decision->reason)->toBe(DecisionReason::SourceError)->and($grantReads)->toBe(1);
});
