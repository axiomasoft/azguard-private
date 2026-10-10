<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

uses()->group('batch');

it('releases batch read handles without cycle collection on success and preparation failure', function (bool $fail): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::define('reports.export');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission', overrides: ['permission' => 'reports.export'])]);
    $contexts = [];
    [$engine] = CacheWorld::database(DatabaseSource::make()->dynamicPermissions(), configure: static function (PanelBuilder $panel) use (&$contexts): void {
        $panel->after(static function (EvaluationContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });
    });
    $request = AccessRequest::for(DatabaseWorld::request()->subject(), PermissionKey::of('admin', 'reports.export'));
    $requests = [$request, $fail ? AccessRequest::for($request->subject(), PermissionKey::of('admin', 'reports.missing')) : $request];
    $storage = DatabaseWorld::storage();
    $handle = WeakReference::create($storage->connection()->getPdo());
    $collecting = gc_enabled();
    gc_disable();

    try {
        if ($fail) {
            expect(fn () => $engine->decideMany($requests))->toThrow(UnknownPermissionException::class);
        } else {
            $set = $engine->decideMany($requests);
            expect($set->get(0)->allowed())->toBeTrue()->and($contexts)->toHaveCount(2);
        }
        $storage->connection()->disconnect();

        // Keeping observer frames and the DecisionSet must not keep the operation's PDO pin alive.
        expect($handle->get())->toBeNull();
    } finally {
        if ($collecting) {
            gc_enable();
        }
    }
})->with(['success with retained observer frames' => [false], 'dynamic preparation exception' => [true]]);
