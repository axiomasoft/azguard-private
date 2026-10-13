<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Sources\Database\DatabaseSource;

/** A companion that gives the panel the database writer, which a plugin with participants needs. */
final class DatabaseWriterPlugin implements Plugin
{
    public function id(): string
    {
        return 'acme/database-writer';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->permissions([DatabaseSource::make()]);
    }
}
