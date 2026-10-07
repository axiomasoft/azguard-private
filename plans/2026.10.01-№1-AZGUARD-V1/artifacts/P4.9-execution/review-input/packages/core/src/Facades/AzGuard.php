<?php

declare(strict_types=1);

namespace AzGuard\Facades;

use AzGuard\AzGuardManager;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\SourceManager;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * Entry point a module uses to bring its own panel or to extend a panel the application chose.
 *
 * The facade does not choose a panel. Further methods arrive with the code that owns them.
 *
 * @method static void registerPanel(class-string<PanelProvider> $provider)
 * @method static void configurePanel(string $id, Closure(PanelBuilder): mixed $callback)
 * @method static void configurePanels(Closure(PanelBuilder): mixed $callback)
 * @method static array<string, Panel> panels()
 * @method static Panel|null currentPanel()
 * @method static SourceManager sources()
 *
 * @see AzGuardManager
 */
final class AzGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'azguard';
    }
}
