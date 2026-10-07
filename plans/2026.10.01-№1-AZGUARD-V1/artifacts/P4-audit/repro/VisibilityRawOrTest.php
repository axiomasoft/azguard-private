<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('A06 a host raw WHERE with a top-level OR escapes the exact authority group', function (): void {
    // Only project 1 (client 101, Paris) is granted.
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    // Typical host search: one raw predicate with OR, combined with AND by the host.
    $host = VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'");
    $query = $visibility->visibleTo($panel, $host, W::subject(), 'orders.view');
    $sql = $query->toSql();
    $ids = $query->orderBy('id')->pluck('id')->all();
    $scalar = [];
    foreach (VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'")->orderBy('id')->get() as $resource) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->allowed()) {
            $scalar[] = $resource->id;
        }
    }
    dump(['sql' => $sql, 'list' => $ids, 'scalar' => $scalar]);
    expect($ids)->toBe($scalar);
});

it('A06c P04a guest list with a host raw OR is not empty', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $ids = $visibility->visibleTo($panel, VisibilityClient::query()->whereRaw("city = 'Rome' or city = 'Paris'"), null, 'orders.view')->orderBy('id')->pluck('id')->all();
    dump(['guest' => $ids]);
    expect($ids)->toBe([]);
});
