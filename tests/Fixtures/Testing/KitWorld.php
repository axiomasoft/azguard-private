<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Authorization\Authorizer;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use Closure;

/** Compiles the seller panel of the kit tests into a fresh registry, over the schema the test migrated. */
final class KitWorld
{
    /** @param (Closure(PanelBuilder): mixed)|null $configure */
    public static function panel(?Closure $configure = null, bool $superAdmin = true): Panel
    {
        KitPanelProvider::$configure = $configure;
        KitPanelProvider::$superAdmin = $superAdmin;
        $registry = new PanelRegistry(app());
        $registry->register(KitPanelProvider::class);
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);

        return $registry->get('seller');
    }

    public static function reset(): void
    {
        KitPanelProvider::$configure = null;
        KitPanelProvider::$superAdmin = true;
    }
}
