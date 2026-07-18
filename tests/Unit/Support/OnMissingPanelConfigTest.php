<?php

declare(strict_types=1);

use AzGuard\Exceptions\AzGuardException;
use AzGuard\Support\Config;

/**
 * C-02 — az-guard.scope.on_missing_panel accessor: fail-closed default and a
 * loud failure on a typo'd value (mirrors Config::morphType()'s InvalidMorphTypeException
 * pattern — an unrecognized fail-closed knob must not silently fall back).
 */
it('defaults to exception when unset', function (): void {
    expect(Config::onMissingPanel())->toBe('exception');
});

it('accepts all three documented values', function (): void {
    foreach (['exception', 'empty', 'all'] as $mode) {
        config(['az-guard.scope.on_missing_panel' => $mode]);

        expect(Config::onMissingPanel())->toBe($mode);
    }
});

it('throws on an unrecognized value instead of silently defaulting', function (): void {
    config(['az-guard.scope.on_missing_panel' => 'ignore']);

    expect(fn () => Config::onMissingPanel())->toThrow(AzGuardException::class);
});
