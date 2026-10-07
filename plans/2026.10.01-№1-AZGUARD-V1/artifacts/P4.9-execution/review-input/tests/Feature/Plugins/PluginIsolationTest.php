<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Plugins\AuditJournal;
use AzGuard\Tests\Fixtures\Plugins\AuditTrailPlugin;
use AzGuard\Tests\Fixtures\Plugins\ReportsPlugin;

beforeEach(function (): void {
    FixturePanel::reset();
    $this->journal = new AuditJournal;
    app()->instance(AuditJournal::class, $this->journal);
});

/**
 * @return array<string, array<string, mixed>> what the audit plugin saw when it booted, by panel
 */
function auditLinesByPanel(AuditJournal $journal): array
{
    return array_column($journal->lines, null, 'panel');
}

it('shows one plugin object its own panel on every panel it is attached to', function (): void {
    $plugin = AuditTrailPlugin::make();

    PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin]),
    ]);
    $lines = auditLinesByPanel($this->journal);

    expect(array_keys($lines))->toBe(['admin', 'cabinet'])
        ->and($lines['admin']['context']->panelId())->toBe('admin')
        ->and($lines['cabinet']['context']->panelId())->toBe('cabinet')
        ->and($lines['admin']['context'])->not->toBe($lines['cabinet']['context'])
        ->and($lines['admin']['context']->pluginId())->toBe('acme/audit-trail')
        ->and($lines['admin']['context']->buildId())->toBe($lines['cabinet']['context']->buildId());
});

it('does not show one panel what a plugin kept while it registered on another', function (): void {
    $audit = AuditTrailPlugin::make();
    $reports = new ReportsPlugin;

    PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$audit, $reports]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$audit, $reports]),
    ]);
    $lines = auditLinesByPanel($this->journal);

    expect($lines['admin']['registered_on'])->toBe(['admin'])
        ->and($lines['cabinet']['registered_on'])->toBe(['cabinet'])
        ->and($reports->registeredOn())->toBe([]);
});

it('keeps different settings of one plugin on different panels apart', function (): void {
    $plugin = AuditTrailPlugin::make(retentionDays: 90);

    PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin->retention(30)]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin]),
    ]);
    $lines = auditLinesByPanel($this->journal);

    expect($lines['admin']['retention'])->toBe(30)
        ->and($lines['cabinet']['retention'])->toBe(90)
        ->and($plugin->retentionDays())->toBe(90);
});

it('copies a plugin the container shares, so panels do not write to the shared object', function (): void {
    app()->singleton(ReportsPlugin::class);
    $shared = app(ReportsPlugin::class);
    $describe = static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ReportsPlugin::class, AuditTrailPlugin::make()]);

    [, , $registry] = PanelWorld::compile([AdminPanel::class => $describe, CabinetPanel::class => $describe]);

    expect($registry->get('admin')->pluginIds())->toBe(['acme/reports', 'acme/audit-trail'])
        ->and($registry->get('cabinet')->pluginIds())->toBe(['acme/reports', 'acme/audit-trail'])
        ->and(app(ReportsPlugin::class))->toBe($shared)
        ->and($shared->registeredOn())->toBe([]);
});

it('lets a panel replace a plugin for all panels with its own settings', function (): void {
    $registry = new PanelRegistry(app());
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([AuditTrailPlugin::make(retentionDays: 90)]));
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->withoutPlugins(['acme/audit-trail'])
        ->plugins([AuditTrailPlugin::make(retentionDays: 30)]));

    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();
    $lines = auditLinesByPanel($this->journal);

    expect($lines['admin']['retention'])->toBe(30)
        ->and($lines['cabinet']['retention'])->toBe(90)
        ->and($registry->get('admin')->pluginIds())->toBe(['acme/audit-trail']);
});
