<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\AzGuardServiceProvider;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Exceptions\PanelIdTooLongException;
use AzGuard\Exceptions\PanelNotFoundException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
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

it('throws in strict mode for an unknown id when the registry is empty', function () {
    config()->set('az-guard.strict_panels', true);
    app()->instance(AzGuardManagerInterface::class, new AzGuardManager);
    AzGuard::clearResolvedInstance(AzGuardManagerInterface::class);

    expect(fn () => PanelResolver::resolveDefault('ghost'))
        ->toThrow(PanelNotFoundException::class);
});

it('throws in strict mode for the app fallback when the registry is empty', function () {
    config()->set('az-guard.strict_panels', true);
    config()->set('az-guard.default_panel', null);
    app()->instance(AzGuardManagerInterface::class, new AzGuardManager);
    AzGuard::clearResolvedInstance(AzGuardManagerInterface::class);

    expect(fn () => PanelResolver::resolveDefault(null))
        ->toThrow(PanelNotFoundException::class);
});

it('accepts a registered configured default after boot', function () {
    config()->set('az-guard.strict_panels', true);
    config()->set('az-guard.default_panel', 'test');

    expect(PanelResolver::resolveDefault(null))->toBe('test');
});

it('rejects an unknown configured default when the provider boots in strict mode', function (): void {
    config()->set('az-guard.strict_panels', true);
    config()->set('az-guard.default_panel', 'ghost');

    expect(fn () => (new AzGuardServiceProvider(app()))->boot())
        ->toThrow(PanelNotFoundException::class);
});

it('throws in strict mode for an unregistered current panel', function () {
    config()->set('az-guard.strict_panels', true);
    AzGuard::setCurrentPanel(Panel::make()->id('ghost'));

    expect(fn () => PanelResolver::resolve(null))
        ->toThrow(PanelNotFoundException::class);
});

it('accepts a registered current panel in strict mode', function () {
    config()->set('az-guard.strict_panels', true);
    AzGuard::setCurrentPanel(Panel::make()->id('test'));

    expect(PanelResolver::resolve(null))->toBe('test');
});

it('rejects a 129-character panel id in strict and legacy modes', function (bool $strict) {
    config()->set('az-guard.strict_panels', $strict);
    $tooLong = str_repeat('p', 129);

    expect(fn () => PanelResolver::resolveDefault($tooLong))
        ->toThrow(PanelIdTooLongException::class)
        ->and(fn () => Panel::make()->id($tooLong))
        ->toThrow(PanelIdTooLongException::class);
})->with([true, false]);

it('accepts a 128-character panel id in strict and legacy modes', function (bool $strict) {
    $id = str_repeat('p', 128);
    AzGuard::registerPanel(Panel::make()->id($id));
    config()->set('az-guard.strict_panels', $strict);

    expect(PanelResolver::resolveDefault($id))->toBe($id)
        ->and(Panel::make()->id($id)->getId())->toBe($id);
})->with([true, false]);
