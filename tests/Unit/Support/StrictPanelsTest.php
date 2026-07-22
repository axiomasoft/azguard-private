<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotFoundException;
use AzGuard\Panels\PanelResolver;

// F47: opt-in strict_panels throws on an unregistered panel; default lenient.

it('throws PanelNotFoundException for an unregistered panel in strict mode', function () {
    config()->set('az-guard.strict_panels', true);

    expect(fn () => PanelResolver::resolveDefault('ghost'))
        ->toThrow(PanelNotFoundException::class);
});

it('accepts a registered panel in strict mode', function () {
    config()->set('az-guard.strict_panels', true);

    expect(PanelResolver::resolveDefault('test'))->toBe('test');
});

it('resolves leniently (no throw) for an unregistered panel by default', function () {
    expect(PanelResolver::resolveDefault('ghost'))->toBe('ghost');
});
