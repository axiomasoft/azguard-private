<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Grammar\PatternMatcher;

it('covers a local key by a pattern segment-wise', function (string $pattern, string $local, bool $covered): void {
    expect(PatternMatcher::covers($pattern, $local))->toBe($covered);
})->with([
    'one segment under prefix' => ['orders.*', 'orders.view', true],
    'one segment, not deeper' => ['orders.*', 'orders.a.b', false],
    'one segment with underscore' => ['orders.*', 'orders.view_all', true],
    'deep, one level' => ['orders.**', 'orders.view', true],
    'deep, two levels' => ['orders.**', 'orders.a.b', true],
    'deep, three levels' => ['orders.**', 'orders.a.b.c', true],
    'exact equal' => ['orders.view', 'orders.view', true],
    'exact different' => ['orders.view', 'orders.edit', false],
    'exact is not a prefix' => ['orders.view', 'orders.view.all', false],
    'prefix is a whole segment' => ['orders.*', 'ordersx.view', false],
    'deep prefix is a whole segment' => ['orders.**', 'orders-x.view', false],
    'other prefix' => ['orders.*', 'invoices.view', false],
    'two-segment prefix' => ['orders.a.*', 'orders.a.b', true],
    'two-segment prefix mismatch' => ['orders.a.*', 'orders.b.c', false],
    'one segment does not cover its prefix' => ['orders.a.*', 'orders.a', false],
    'deep does not cover its prefix' => ['orders.a.**', 'orders.a', false],
    'deep under two-segment prefix' => ['orders.a.**', 'orders.a.b.c', true],
]);

it('rejects an input outside the grammar instead of matching it', function (string $pattern, string $local): void {
    expect(fn () => PatternMatcher::covers($pattern, $local))->toThrow(InvalidPermissionKeyException::class);
})->with([
    'bare deep wildcard' => ['**', 'orders.view'],
    'bare one-segment wildcard' => ['*', 'orders'],
    'deep wildcard not last' => ['a.**.b', 'a.x.b'],
    'wildcard key' => ['orders.*', 'orders.*'],
    'uppercase key' => ['orders.*', 'Orders.view'],
]);
