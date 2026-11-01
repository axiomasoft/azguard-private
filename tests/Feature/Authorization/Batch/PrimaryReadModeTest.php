<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Internal\PrimaryReadContext;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

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

it('records trace errors and skips cache publication when batch read modes observe conflicting states', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Request, reads: Reads::Default);
    $connection = DatabaseWorld::storage()->connection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This split-PDO regression uses an isolated SQLite connection.');
    }
    $primary = $connection->getPdo();
    $replica = new PDO('sqlite::memory:');
    $replica->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($primary->query("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $replica->exec($table['sql']);
        $name = '"'.str_replace('"', '""', $table['name']).'"';
        foreach ($primary->query('select * from '.$name)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insert = $replica->prepare('insert into '.$name.' values ('.implode(',', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
    }
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m): void {
        $m->touch('admin');
    });
    $previous = $connection->getRawReadPdo();
    $connection->setReadPdo($replica);
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        if ($event->message === 'AzGuard evaluation failed.') {
            $logged[] = $event->context;
        }
    });

    try {
        $request = DatabaseWorld::request()->traced();
        $set = $engine->decideMany([$request, $request->onPrimary()]);

        expect($set->get(0)->allowed())->toBeFalse()
            ->and($set->get(0)->reason)->toBe(DecisionReason::ConsistencyError)
            ->and($set->get(0)->component)->toBe('sources')
            ->and($set->get(1)->allowed())->toBeFalse()
            ->and($set->get(1)->reason)->toBe(DecisionReason::ConsistencyError)
            ->and($set->get(1)->component)->toBe('sources')
            ->and($logged)->toHaveCount(2);
        foreach ($logged as $context) {
            expect($context['component'] ?? null)->toBe('sources')
                ->and($context['reason'] ?? null)->toBe(DecisionReason::ConsistencyError->value);
        }
        $cache = app(PermissionSetCache::class);
        expect((new ReflectionProperty($cache, 'sets'))->getValue($cache))->toBe([])
            ->and((new ReflectionProperty($cache, 'states'))->getValue($cache))->toBe([]);
    } finally {
        $connection->setReadPdo($previous);
    }
})->group('server-skip');
