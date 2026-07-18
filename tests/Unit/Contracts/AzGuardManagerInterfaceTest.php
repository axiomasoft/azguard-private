<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Panels\Panel;

describe('AzGuardManagerInterface contract', function () {
    it('AzGuardManager implements AzGuardManagerInterface', function () {
        $manager = new AzGuardManager;
        expect($manager)->toBeInstanceOf(AzGuardManagerInterface::class);
    });

    it('container resolves AzGuardManagerInterface to AzGuardManager', function () {
        $resolved = app(AzGuardManagerInterface::class);
        expect($resolved)->toBeInstanceOf(AzGuardManager::class);
    });

    it('registers and retrieves panel', function () {
        $manager = new AzGuardManager;

        $manager->registerPanel(fn () => Panel::make()->id('test-panel'));

        expect($manager->panel('test-panel'))->toBeInstanceOf(Panel::class);
        expect($manager->panel('test-panel')->getId())->toBe('test-panel');
    });

    it('returns null for unknown panel', function () {
        $manager = new AzGuardManager;
        expect($manager->panel('unknown'))->toBeNull();
    });

    it('sets and gets current panel', function () {
        $manager = new AzGuardManager;
        $panel = Panel::make()->id('active');

        $manager->setCurrentPanel($panel);
        expect($manager->currentPanel())->toBe($panel);
    });

    it('current panel is null by default', function () {
        $manager = new AzGuardManager;
        expect($manager->currentPanel())->toBeNull();
    });

    it('resolves permission for registered panel', function () {
        $manager = new AzGuardManager;
        $manager->registerPanel(fn () => Panel::make()->id('crm')->scopedByPanelId(true));

        expect($manager->permission('crm', 'users.view'))->toBe('crm.users.view');
    });

    it('throws RuntimeException for unregistered panel', function () {
        $manager = new AzGuardManager;

        expect(fn () => $manager->permission('ghost', 'users.view'))
            ->toThrow(RuntimeException::class);
    });

    it('getPanels returns all registered panels', function () {
        $manager = new AzGuardManager;
        $manager->registerPanel(fn () => Panel::make()->id('panel-a'));
        $manager->registerPanel(fn () => Panel::make()->id('panel-b'));

        $panels = $manager->getPanels();

        expect($panels)->toHaveCount(2)
            ->and(array_keys($panels))->toContain('panel-a', 'panel-b');
    });
});
