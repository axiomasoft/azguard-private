<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Internal\PrimaryReadContext;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('does not share a batch attempt or cache entry across effective read modes', function (bool $primaryFirst): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Request, reads: Reads::Default);
    $request = DatabaseWorld::request();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $requests = $primaryFirst ? [$request->onPrimary(), $request] : [$request, $request->onPrimary()];
    $set = $engine->decideMany($requests);
    expect($set->get(0)->allowed())->toBeTrue()->and($set->get(1)->allowed())->toBeTrue()
        ->and($budget['grants'])->toBeGreaterThanOrEqual(4)->and($budget['state'])->toBeGreaterThanOrEqual(2);
})->with([true, false]);

it('routes optional source contexts to the requested physical handle and preserves the legacy fallback', function (): void {
    $source = DatabaseSource::make();
    [, $panel] = CacheWorld::database($source, StateRefresh::Check, reads: Reads::Default);
    $connection = DatabaseWorld::storage()->connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This split-PDO regression uses an isolated SQLite connection.');
    }
    $replica = new PDO('sqlite::memory:');
    $primary = $connection->getPdo();
    $previous = $connection->getRawReadPdo();
    $connection->setReadPdo($replica);

    try {
        $legacy = Mockery::mock(EvaluationContext::class);
        $legacy->shouldReceive('panel')->andReturn($panel);
        $context = Mockery::mock(EvaluationContext::class, PrimaryReadContext::class);
        $context->shouldReceive('panel')->andReturn($panel);
        $context->shouldReceive('requiresPrimary')->andReturn(false, true);

        expect($source->openReadSession($legacy)->handleIdentity())->toBe(spl_object_id($replica))
            ->and($source->openReadSession($context)->handleIdentity())->toBe(spl_object_id($replica))
            ->and($source->openReadSession($context)->handleIdentity())->toBe(spl_object_id($primary));
    } finally {
        $connection->setReadPdo($previous);
    }
})->group('server-skip');
