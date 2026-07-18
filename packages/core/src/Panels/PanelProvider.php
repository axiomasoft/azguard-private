<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Auth\PolicyAttributeRegistrar;
use AzGuard\AzGuardManager;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Guard\PolicyDiscovery;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Registry\Builders\PolicyAbilityCatalogBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;
use ReflectionClass;

abstract class PanelProvider extends ServiceProvider
{
    private ?Panel $cachedPanel = null;

    abstract public function panel(Panel $panel): Panel;

    #[Override]
    public function register(): void
    {
        AzGuard::registerPanel(function (): Panel {
            $panel = $this->getPanel();
            $reflection = new ReflectionClass($this);
            $panel->basePath(basePath: dirname(path: $reflection->getFileName()));

            return $panel;
        });
    }

    public function boot(): void
    {
        $panel = $this->getPanel();
        $reflection = new ReflectionClass($this);
        $basePath = dirname(path: $reflection->getFileName());
        $baseNamespace = $reflection->getNamespaceName();

        $discovery = new PolicyDiscovery;
        $policyClasses = $discovery->discoverPolicyClasses(
            basePath: $basePath,
            baseNamespace: $baseNamespace,
        );

        app(PolicyAttributeRegistrar::class)->register(
            policyClasses: $policyClasses,
            panel: $panel,
        );

        foreach ($policyClasses as $policyClass) {
            $modelClass = $discovery->resolveModelClass(
                policyClass: $policyClass,
                basePath: $basePath,
            );

            if ($modelClass !== null) {
                Gate::policy($modelClass, $policyClass);
            }
        }

        $this->registerCatalogBuilders(
            panel: $panel,
            policyClasses: $policyClasses,
            basePath: $basePath,
            baseNamespace: $baseNamespace,
        );
    }

    /**
     * Registers EnumPermissionCatalogBuilder and PolicyAbilityCatalogBuilder
     * for this panel under the azguard.catalog_builders tag so that
     * CompositePermissionCatalog can assemble the full permission catalog.
     *
     * To ADD a custom builder without losing the defaults, override
     * {@see registerCustomCatalogBuilders()} — it runs after this. Override this
     * method only when you need to REPLACE the default enum/policy registration.
     *
     * @param  list<string>  $policyClasses
     */
    protected function registerCatalogBuilders(
        Panel $panel,
        array $policyClasses,
        string $basePath,
        string $baseNamespace,
    ): void {
        $panelId = $panel->getId();
        $permissionEnums = $panel->getPermissionEnums();

        $manager = $this->app->make(AzGuardManagerInterface::class);

        if ($permissionEnums !== []) {
            $abstract = 'azguard.catalog_builder.'.$panelId.'.enum';
            $this->app->instance($abstract, new EnumPermissionCatalogBuilder(
                panelId: $panelId,
                enumClasses: $permissionEnums,
                manager: $manager,
            ));
            $this->app->tag([$abstract], AzGuardManager::CATALOG_BUILDERS_TAG);
        }

        if ($policyClasses !== []) {
            $abstract = 'azguard.catalog_builder.'.$panelId.'.policy';
            $this->app->instance($abstract, new PolicyAbilityCatalogBuilder(
                panelId: $panelId,
                policyClasses: $policyClasses,
                manager: $manager,
            ));
            $this->app->tag([$abstract], AzGuardManager::CATALOG_BUILDERS_TAG);
        }

        $this->registerCustomCatalogBuilders($panel);
    }

    /**
     * Extension hook: register additional PermissionCatalogBuilder instances for
     * this panel (e.g. a database-backed catalog) without re-implementing the
     * default enum/policy registration. Tag each with 'azguard.catalog_builders'.
     * Empty by default — override in a concrete PanelProvider.
     */
    protected function registerCustomCatalogBuilders(Panel $panel): void
    {
        // No custom builders by default.
    }

    protected function getPanel(): Panel
    {
        if (! $this->cachedPanel instanceof Panel) {
            $reflection = new ReflectionClass($this);
            $this->cachedPanel = $this->panel(panel: Panel::make());
            $this->cachedPanel->namespace(namespace: $reflection->getNamespaceName());
        }

        return $this->cachedPanel;
    }
}
