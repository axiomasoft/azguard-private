<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Contracts\Panels\PanelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Describes one panel of the application; the directory of the provider is the directory of the panel.
 *
 * Listed in `azguard.panels.providers` or registered as a regular service provider by a module.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
abstract class PanelProvider extends ServiceProvider
{
    /**
     * Stable id of the panel; a class or namespace name is never turned into an id.
     */
    abstract public static function getId(): string;

    abstract public function panel(PanelBuilder $panel): PanelBuilder;

    public function register(): void
    {
        $this->app->make(PanelRegistry::class)->register(static::class);
    }
}
