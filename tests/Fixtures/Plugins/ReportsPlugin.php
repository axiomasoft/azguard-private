<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin without parameters; a panel can attach it by class.
 */
final class ReportsPlugin implements Plugin
{
    /** @var list<string> panels this copy registered on */
    private array $registeredOn = [];

    public function id(): string
    {
        return 'acme/reports';
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
