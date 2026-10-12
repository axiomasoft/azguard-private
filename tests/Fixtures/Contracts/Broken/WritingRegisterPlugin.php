<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/** A plugin that does database work while it registers instead of only declaring. */
final class WritingRegisterPlugin implements Plugin
{
    public function id(): string
    {
        return 'acme/writing-register';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        app('db')->table('contract_probe')->insert(['note' => $context->panelId()]);
    }
}
