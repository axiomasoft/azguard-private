<?php

declare(strict_types=1);

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
