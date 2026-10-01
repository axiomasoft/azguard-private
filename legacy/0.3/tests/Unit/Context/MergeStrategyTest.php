<?php

declare(strict_types=1);

use AzGuard\Context\Strategies\ContextOnlyStrategy;
use AzGuard\Context\Strategies\DenyWithoutContextStrategy;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Registry\Values\PermissionSet;
use Carbon\CarbonImmutable;

it('GlobalPlusContext returns only global when no context', function (): void {
    $result = (new GlobalPlusContextStrategy)->merge(PermissionSet::fromKeys(['app.posts.view']), null);

    expect($result->grants('app.posts.view'))->toBeTrue()
        ->and($result->grants('app.posts.edit'))->toBeFalse();
});

it('GlobalPlusContext merges global and context', function (): void {
    $result = (new GlobalPlusContextStrategy)->merge(
        PermissionSet::fromKeys(['app.posts.view']),
        PermissionSet::fromKeys(['app.posts.edit']),
    );

    expect($result->grants('app.posts.view'))->toBeTrue()
        ->and($result->grants('app.posts.edit'))->toBeTrue();
});

it('GlobalPlusContext propagates a wildcard context', function (): void {
    $result = (new GlobalPlusContextStrategy)->merge(
        PermissionSet::fromKeys(['app.posts.view']),
        PermissionSet::wildcard(),
    );

    expect($result->isWildcard())->toBeTrue();
});

it('ContextOnly ignores global and is empty without context', function (): void {
    $strategy = new ContextOnlyStrategy;

    expect($strategy->merge(PermissionSet::fromKeys(['app.posts.view']), null)->keys())->toBe([]);

    $result = $strategy->merge(
        PermissionSet::fromKeys(['app.posts.view']),
        PermissionSet::fromKeys(['app.posts.edit']),
    );

    expect($result->grants('app.posts.view'))->toBeFalse()
        ->and($result->grants('app.posts.edit'))->toBeTrue();
});

it('DenyWithoutContext denies without context and merges with it', function (): void {
    $strategy = new DenyWithoutContextStrategy;
    $global = PermissionSet::fromKeys(['app.posts.view']);

    expect($strategy->merge($global, null)->grants('app.posts.view'))->toBeFalse()
        ->and($strategy->merge($global, PermissionSet::fromKeys(['app.posts.edit']))->grants('app.posts.view'))->toBeTrue();
});

it('all three strategies keep the nearest contributing deadline', function (): void {
    $early = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
    $late = $early->addHour();
    $global = PermissionSet::fromKeys(['app.posts.view'])->withValidUntil($late);
    $context = PermissionSet::fromKeys(['app.posts.edit'])->withValidUntil($early);

    expect((new GlobalPlusContextStrategy)->merge($global, $context)->validUntil()?->eq($early))->toBeTrue()
        ->and((new DenyWithoutContextStrategy)->merge($global, $context)->validUntil()?->eq($early))->toBeTrue()
        ->and((new ContextOnlyStrategy)->merge($global, $context)->validUntil()?->eq($early))->toBeTrue()
        ->and((new GlobalPlusContextStrategy)->merge($global, null)->validUntil()?->eq($late))->toBeTrue()
        ->and((new ContextOnlyStrategy)->merge($global, null)->validUntil())->toBeNull()
        ->and((new DenyWithoutContextStrategy)->merge($global, null)->validUntil())->toBeNull();
});
