<?php

declare(strict_types=1);

use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('P10: ten warm request checks read one state and no DB grants', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    for ($check = 0; $check < 10; $check++) {
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    }
    expect($budget['state'])->toBe(1)->and($budget['grants'])->toBe(0);
});
