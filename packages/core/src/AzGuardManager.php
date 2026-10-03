<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Root of the AzGuard facade.
 *
 * The manager keeps no panel state and chooses no panel: each call forwards to the registry or reads the panel of
 * the current request. The rule that picks a panel stays in the resolver.
 */
final class AzGuardManager
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  class-string<PanelProvider>  $provider
     */
    public function registerPanel(string $provider): void
    {
        $this->app->make(PanelRegistry::class)->register($provider);
    }

    /**
     * Adds to one panel on behalf of its provider. An id nobody registered is reported when the panels are compiled.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     */
    public function configurePanel(string $id, Closure $callback): void
    {
        $this->app->make(PanelRegistry::class)->configure($id, $callback);
    }

    /**
     * Applies one adjustment to every panel, below each panel's own provider.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     */
    public function configurePanels(Closure $callback): void
    {
        $this->app->make(PanelRegistry::class)->configureAll($callback);
    }

    /**
     * @return array<string, Panel> panels by id, in registration order
     */
    public function panels(): array
    {
        return $this->app->make(PanelRegistry::class)->all();
    }

    /**
     * The panel of the current request, or null when the request has none. This reads the panel; it does not pick one.
     */
    public function currentPanel(): ?Panel
    {
        return $this->app->make(CurrentPanel::class)->get();
    }
}
