<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Permissions\PermissionSet;

it('deduplicates patterns by their full form and keeps the first order', function (): void {
    $set = PermissionSet::of([
        PermissionPattern::of('admin', 'orders.*'),
        PermissionPattern::of('admin', 'invoices.view'),
        PermissionPattern::of('admin', 'orders.*'),
        PermissionPattern::of('crm', 'orders.*'),
    ]);

    expect(array_map(static fn (PermissionPattern $p): string => $p->full(), $set->patterns()))
        ->toBe(['admin:orders.*', 'admin:invoices.view', 'crm:orders.*']);
});

it('covers a key when any pattern of its panel covers it', function (): void {
    $set = PermissionSet::of((function (): Generator {
        yield PermissionPattern::of('admin', 'orders.*');
        yield PermissionPattern::of('admin', 'reports.**');
    })());

    expect($set->covers(PermissionKey::of('admin', 'orders.view')))->toBeTrue()
        ->and($set->covers(PermissionKey::of('admin', 'reports.sales.monthly')))->toBeTrue()
        ->and($set->covers(PermissionKey::of('admin', 'orders.view.all')))->toBeFalse()
        ->and($set->covers(PermissionKey::of('crm', 'orders.view')))->toBeFalse()
        ->and(PermissionSet::of([])->covers(PermissionKey::of('admin', 'orders.view')))->toBeFalse();
});

it('carries an optional absolute deadline', function (): void {
    $until = new DateTimeImmutable('2026-12-31 23:59:59');

    expect(PermissionSet::of([], $until)->validUntil())->toBe($until)
        ->and(PermissionSet::of([])->validUntil())->toBeNull();
});
