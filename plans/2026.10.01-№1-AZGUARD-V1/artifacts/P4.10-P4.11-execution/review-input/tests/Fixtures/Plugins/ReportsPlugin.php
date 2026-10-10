<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin without parameters that works only next to the audit plugin; a panel can attach it by class.
 */
final class ReportsPlugin extends BasePlugin implements DependsOnPlugins
{
    /** @var list<string> panels this copy registered on */
    private array $registeredOn = [];

    public function id(): string
    {
        return 'acme/reports';
    }

    public function requires(): array
    {
        return ['acme/audit-trail'];
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->registeredOn[] = $context->panelId();

        $panel->doctorChecks([ReportsTableExists::class]);
    }

    /**
     * @return list<string>
     */
    public function registeredOn(): array
    {
        return $this->registeredOn;
    }
}
