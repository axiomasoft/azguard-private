<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('materializes a fenced foreign-connection DatabaseSource without joining assignment tables', function (): void {
    $storage = Storage::own('secondary');
    expect($storage->connection()->getDatabaseName())->toBe(':memory:');
    app(StorageSchema::class)->create($storage->id());
    $storage->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->insert(DatabaseWorld::row('permission', W::scope(1), ['permission' => 'orders.view']));
        $mutation->touch('admin');
    });
    [$visibility, $panel] = W::compile(extra: [DatabaseSource::make()->storage($storage)]);
    DB::connection()->enableQueryLog();
    $query = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view');
    expect($query->toSql())->not->toContain('azg_')->and($query->pluck('id')->all())->toBe([1]);
    foreach (DB::connection()->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('azg_');
    }
});

it('supports 1000 materialized scope refs and rejects 1001 before querying host rows', function (): void {
    $source = new VisibilitySource;
    $source->direct = array_map(static fn (int $id) => W::grant($id), range(1, 1000));
    [$visibility, $panel] = W::compile($source);
    $query = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view');
    expect($query->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3, 4]);
    $source->direct[] = W::grant(1001);
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class, 'selection_budget');
    foreach (DB::connection()->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('visibility_projects');
    }
});
