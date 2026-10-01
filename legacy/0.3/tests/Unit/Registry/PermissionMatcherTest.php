<?php

declare(strict_types=1);

use AzGuard\Contracts\PermissionMatcher;
use AzGuard\Registry\Matching\HierarchicalPermissionMatcher;
use AzGuard\Registry\Matching\WildcardPermissionMatcher;
use AzGuard\Registry\Values\PermissionSet;

// F22 flip: the hierarchical grammar is the container default.
it('binds the hierarchical matcher by default', function () {
    expect(app(PermissionMatcher::class))->toBeInstanceOf(HierarchicalPermissionMatcher::class);
});

// F22 flip: the deprecated flag restores the legacy grammar for one cycle.
it('binds the legacy matcher when the deprecated wildcard_permission flag is set', function () {
    config()->set('az-guard.features.wildcard_permission', true);
    app()->forgetInstance(PermissionMatcher::class);

    expect(app(PermissionMatcher::class))->toBeInstanceOf(WildcardPermissionMatcher::class);
});

// F22: hierarchical grammar — '*' is one segment, '**' is recursive.
it('matches one segment with * and recurses with ** in the hierarchical grammar', function () {
    $matcher = new HierarchicalPermissionMatcher;

    expect($matcher->matches('a.*', 'a.b'))->toBeTrue()
        ->and($matcher->matches('a.*', 'a.b.c'))->toBeFalse()     // does NOT cross dots
        ->and($matcher->matches('a.**', 'a.b'))->toBeTrue()
        ->and($matcher->matches('a.**', 'a.b.c'))->toBeTrue()
        ->and($matcher->matches('a.**', 'a.b.c.d'))->toBeTrue()
        ->and($matcher->matches('a.b', 'a.b'))->toBeTrue()
        ->and($matcher->matches('a.*', 'x.b'))->toBeFalse();
});

// F22: legacy (0.2) grammar — '*' crosses dot boundaries. Kept for the opt-out cycle.
it('keeps the legacy wildcard grammar crossing dot boundaries', function () {
    $matcher = new WildcardPermissionMatcher;

    expect($matcher->matches('a.*', 'a.b'))->toBeTrue()
        ->and($matcher->matches('a.*', 'a.b.c'))->toBeTrue()      // crosses dots (legacy)
        ->and($matcher->matches('a.b.*', 'a.b.c'))->toBeTrue()
        ->and($matcher->matches('a.*', 'x.b'))->toBeFalse();
});

// F21: compiled pattern is memoized — not recompiled per key.
it('memoizes the compiled pattern instead of recompiling per key', function () {
    $matcher = new WildcardPermissionMatcher;

    $matcher->matches('a.*', 'a.b');
    $matcher->matches('a.*', 'a.c');
    $matcher->matches('a.*', 'a.d');

    $compiled = (new ReflectionProperty($matcher, 'compiled'))->getValue($matcher);

    expect($compiled)->toHaveCount(1)->toHaveKey('a.*');
});

// F21/F22: PermissionSet routes wildcard matching through the config-swappable matcher.
it('lets a config-overridden matcher change PermissionSet wildcard matching', function () {
    // Default matcher: hierarchical — 'a.*' does NOT cross dots.
    expect(PermissionSet::fromKeys(['a.*'])->grants('a.b.c'))->toBeFalse()
        ->and(PermissionSet::fromKeys(['a.*'])->grants('a.b'))->toBeTrue()
        ->and(PermissionSet::fromKeys(['a.**'])->grants('a.b.c'))->toBeTrue();

    config()->set('az-guard.matcher', WildcardPermissionMatcher::class);
    app()->forgetInstance(PermissionMatcher::class);

    expect(PermissionSet::fromKeys(['a.*'])->grants('a.b.c'))->toBeTrue();
});

// C-07: standalone (no container binding) the VO falls back to the hierarchical
// default — aligned with the application default, not the legacy grammar.
it('falls back to the hierarchical grammar when no matcher binding exists', function () {
    unset(app()[PermissionMatcher::class]);

    expect(app()->bound(PermissionMatcher::class))->toBeFalse()
        ->and(PermissionSet::fromKeys(['a.*'])->grants('a.b'))->toBeTrue()
        ->and(PermissionSet::fromKeys(['a.*'])->grants('a.b.c'))->toBeFalse()
        ->and(PermissionSet::fromKeys(['a.**'])->grants('a.b.c'))->toBeTrue();
});
