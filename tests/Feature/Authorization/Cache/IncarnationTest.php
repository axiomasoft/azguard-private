<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('does not resurrect request or durable authority after a restore to a smaller version and new incarnation', function (StateRefresh $refresh): void {
    $schema = app(StorageSchema::class);
    $schema->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $storage = DatabaseWorld::storage();
    $storage->mutate('admin', fn (StorageMutation $m) => $m->touch('admin'));
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
    $before = $engine->decide($panel, DatabaseWorld::request());
    expect($before->allowed())->toBeTrue();

    // Test-local reset/restore protocol recreates storage metadata instead of writing protected panel_state.
    $schema->drop('default');
    $schema->create('default');
    $storage->mutate('admin', static fn (): null => null);
    $restored = $storage->state('admin');
    expect($restored->incarnation)->not->toBe($before->state->incarnation)
        ->and($restored->version)->toBeLessThan($before->state->version);

    if ($refresh === StateRefresh::Request) {
        // A new request reads the restored authority; request refresh intentionally keeps its warm token.
        app()->forgetScopedInstances();
    }
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
})->with([StateRefresh::Request, StateRefresh::Check]);
