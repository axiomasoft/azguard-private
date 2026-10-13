<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Testing\Internal\Contracts\ObservedPlugin;
use Closure;
use PHPUnit\Framework\Attributes\Test;

/**
 * For the authors of plugins: what every plugin guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application and implement `azguardPlugin()`; the plugins yours
 * needs next to it go in `azguardCompanions()` (a plugin that declares participants needs one that attaches the
 * database writer).
 *
 * - the id is stable and the panel lists the plugin;
 * - it builds on a clean panel and on two panels, and a panel without it is the panel it was before;
 * - `register()` runs once per panel with the context of that panel and writes nothing to the database;
 * - a plugin outside the package does not take an id of the reserved `azguard/` prefix;
 * - a panel that keeps the plugin off is the panel without it, with no trace left.
 */
#[Spi(kind: SpiKind::Call, since: '0.7')]
trait PluginContractTests
{
    /** A new instance of the plugin under test. */
    abstract protected function azguardPlugin(): Plugin;

    /**
     * Plugins the plugin under test is attached next to, such as the ones that declare what it refers to.
     *
     * @return list<Plugin>
     */
    protected function azguardCompanions(): array
    {
        return [];
    }

    #[Test]
    public function pluginHasAStableId(): void
    {
        $plugin = $this->azguardPlugin();

        $this->assertNotSame('', $plugin->id(), 'A plugin has an id.');
        $this->assertSame($plugin->id(), $plugin->id(), 'The id changes between calls.');
        $this->assertSame($plugin->id(), $this->azguardPlugin()->id(), 'Two instances of the plugin have different ids.');
    }

    #[Test]
    public function pluginBuildsOnACleanPanel(): void
    {
        $panel = ContractWorld::panel(fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]));

        $this->assertContains($this->azguardPlugin()->id(), $panel->pluginIds(), 'The panel does not list the plugin.');
    }

    #[Test]
    public function pluginBuildsOnTwoPanelsAndTouchesOnlyTheOneItIsAttachedTo(): void
    {
        $alone = ContractWorld::build(null, static fn (PanelBuilder $builder): null => null)->get(ContractWorld::OTHER);
        $baseline = ContractWorld::snapshot($alone);
        $registry = ContractWorld::build(
            fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]),
            static fn (PanelBuilder $builder): null => null,
        );

        $this->assertContains($this->azguardPlugin()->id(), $registry->get(ContractWorld::PANEL)->pluginIds(), 'The panel does not list the plugin.');
        $this->assertSame($baseline, ContractWorld::snapshot($registry->get(ContractWorld::OTHER)), 'The plugin changed a panel it was not attached to.');

        $both = ContractWorld::build(null, null, fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]));
        $this->assertContains($this->azguardPlugin()->id(), $both->get(ContractWorld::PANEL)->pluginIds());
        $second = ContractWorld::build(
            null,
            static fn (PanelBuilder $builder): null => null,
            fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]),
        );

        foreach ([ContractWorld::PANEL, ContractWorld::OTHER] as $id) {
            $this->assertContains($this->azguardPlugin()->id(), $second->get($id)->pluginIds(), 'The plugin does not build on panel '.$id.' when it is attached to both.');
        }
    }

    #[Test]
    public function registerRunsOncePerPanelAndWritesNothing(): void
    {
        $registered = [];
        $plugin = new ObservedPlugin($this->azguardPlugin(), static function (PluginContext $context, Closure $register) use (&$registered): void {
            $registered[] = [$context->panelId(), $context->pluginId()];
            $register();
        });

        $companions = $this->azguardCompanions();
        $writes = ContractWorld::writesDuring(static function () use ($plugin, $companions): void {
            ContractWorld::build(null, static fn (PanelBuilder $builder): null => null, static fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$companions, $plugin]));
        });

        $this->assertSame([], $writes, 'register() wrote to the database: '.implode('; ', $writes));
        $this->assertSame(
            [[ContractWorld::PANEL, $plugin->id()], [ContractWorld::OTHER, $plugin->id()]],
            $registered,
            'register() must run exactly once on every panel the plugin is attached to, with the context of that panel.',
        );
    }

    #[Test]
    public function pluginOutsideThePackageDoesNotTakeAReservedId(): void
    {
        $plugin = $this->azguardPlugin();

        if (str_starts_with($plugin::class, 'AzGuard\\')) {
            $this->assertNotSame('', $plugin->id(), 'A plugin of the package has an id.');

            return;
        }

        $this->assertFalse(str_starts_with($plugin->id(), 'azguard/'), 'The prefix "azguard/" is reserved for the plugins of the package; use your own vendor name in the id.');
    }

    #[Test]
    public function pluginKeptOffAPanelLeavesNoTraceThere(): void
    {
        $baseline = ContractWorld::snapshot(ContractWorld::build(null, static fn (PanelBuilder $builder): null => null,
            fn (PanelBuilder $builder): PanelBuilder => $builder->plugins($this->azguardCompanions()))->get(ContractWorld::OTHER));
        $id = $this->azguardPlugin()->id();
        $registry = ContractWorld::build(
            null,
            static fn (PanelBuilder $builder): PanelBuilder => $builder->withoutPlugins([$id]),
            fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $this->azguardPlugin()]),
        );

        $this->assertContains($id, $registry->get(ContractWorld::PANEL)->pluginIds(), 'The plugin is not on the panel it is attached to.');
        $this->assertNotContains($id, $registry->get(ContractWorld::OTHER)->pluginIds(), 'withoutPlugins() did not keep the plugin off the panel.');
        $this->assertSame($baseline, ContractWorld::snapshot($registry->get(ContractWorld::OTHER)), 'The plugin left a trace on a panel that keeps it off.');
    }
}
