<?php

declare(strict_types=1);

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('captures a committed grant in a builder but rechecks every stale target on primary after revoke', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [
        DatabaseWorld::row('permission', W::scope(1), ['permission' => 'orders.view']),
        DatabaseWorld::row('permission', W::scope(3), ['permission' => 'orders.view']),
    ]);
    [$visibility, $panel, $authorizer] = W::compile(extra: [DatabaseSource::make()]);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'));
    $query = $visibility->visibleTo($panel, VisibilityProject::query()->orderBy('id'), W::subject(), 'orders.view');
    $staleCollection = (clone $query)->get();
    expect($staleCollection->pluck('id')->all())->toBe([1, 3]);

    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('permission_grants')->where('context_key', W::scope(1)->context->key())->delete();
        $mutation->touch('admin');
    });

    expect($query->pluck('id')->all())->toBe([1, 3])
        ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([3])
        ->and($authorizer->decide($panel, $request->on(null, VisibilityProject::onWriteConnection()->findOrFail(1))->onPrimary())->allowed())->toBeFalse();

    $access = (new SubjectAccess($panel, W::subject()))->onPrimary();
    foreach (['bulk', 'export', 'download'] as $operation) {
        $allowedIds = [];
        foreach ($staleCollection as $selected) {
            $record = VisibilityProject::onWriteConnection()->findOrFail($selected->getKey());

            if ($access->hasPermission('orders.view', $record)) {
                $allowedIds[] = $record->getKey();
            }
        }
        expect($allowedIds)->toBe([3], $operation);
    }
});
