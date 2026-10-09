<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use Closure;
use PHPUnit\Framework\Attributes\Test;

/**
 * For the authors of plugins: what every plugin guarantees, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application and implement `azguardPlugin()`; the plugins yours
 * needs next to it go in `azguardCompanions()`.
 *
 * - the id is stable and the panel lists the plugin;
 * - it builds on a clean panel and on two panels, and a panel without it is the panel it was before;
 * - `boot()` does not change the panel;
 * - a panel that keeps the plugin off is the panel without it, with no trace left.
 *
 * @api
 */
trait PluginContractTests
{
    /** A new instance of the plugin under test. */
    abstract protected function azguardPlugin(): Plugin;

    /**
     * The plugins the plugin under test requires, attached to the panel next to it.
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

        if ($plugin instanceof DependsOnPlugins) {
            $companions = array_map(static fn (Plugin $companion): string => $companion->id(), $this->azguardCompanions());

            foreach ($plugin->requires() as $required) {
                $this->assertContains($required, $companions, 'The plugin requires "'.$required.'", which azguardCompanions() does not provide.');
            }
        }
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
    public function bootDoesNotChangeThePanel(): void
    {
        $plugin = new ObservedPlugin($this->azguardPlugin(), function (Panel $panel, Closure $boot): void {
            $before = ContractWorld::snapshot($panel);
            $boot();
            $this->assertSame($before, ContractWorld::snapshot($panel), 'boot() changed the panel.');
        });
        $registry = ContractWorld::build(fn (PanelBuilder $builder): PanelBuilder => $builder->plugins([...$this->azguardCompanions(), $plugin]));
        $this->assertTrue($registry->isFrozen(), 'boot() unfroze the registry.');
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
