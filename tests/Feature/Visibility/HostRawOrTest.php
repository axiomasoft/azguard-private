<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

/** @return list<int> */
function scalarVisibleIds(object $authorizer, object $panel, Builder $host): array
{
    $ids = [];
    foreach ($host->orderBy('id')->get() as $resource) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->allowed()) {
            $ids[] = $resource->id;
        }
    }

    return $ids;
}

it('A06 keeps a host raw OR inside its own group so the authority cannot be bypassed', function (): void {
    // Only project 1 (client 101, Paris) is granted.
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $host = static fn (): Builder => VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'");
    $query = $visibility->visibleTo($panel, $host(), W::subject(), 'orders.view');

    expect($query->toSql())->toContain("where (city = 'Rome' or city = 'Paris') and ")
        ->and($query->orderBy('id')->pluck('id')->all())->toBe(scalarVisibleIds($authorizer, $panel, $host()))->toBe([101]);
});

it('A06 keeps host raw OR bindings aligned with the authority bindings', function (): void {
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $host = static fn (): Builder => VisibilityClient::query()->whereRaw('city = ? or city = ?', ['Rome', 'Paris']);
    $query = $visibility->visibleTo($panel, $host(), W::subject(), 'orders.view');

    expect($query->orderBy('id')->pluck('id')->all())->toBe(scalarVisibleIds($authorizer, $panel, $host()))->toBe([101])
        ->and($visibility->visibleTo($panel, $host(), W::subject(), 'orders.view')->count())->toBe(1);
});

it('A06c lists nothing for a guest over a host raw OR', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $ids = $visibility->visibleTo($panel, VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'"), null, 'orders.view')->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([]);
});

it('A06 keeps a scope descriptor raw OR from escaping the owner tenant and the selected context', function (): void {
    // Grant only project 1 of tenant A; the descriptor query adds "org = 'B' or org = 'A'" without grouping.
    W::$tenanted = true;
    W::$projectQuery = static fn (): Builder => VisibilityProject::query()->whereRaw("org = 'B' or org = 'A'");
    [$visibility, $panel, $authorizer] = W::compile(
        new VisibilitySource(direct: [W::grant(1)]),
        tenant: true,
    );
    $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe(scalarVisibleIds($authorizer, $panel, VisibilityClient::query()->where('org', 'A')))->toBe([101]);
});
