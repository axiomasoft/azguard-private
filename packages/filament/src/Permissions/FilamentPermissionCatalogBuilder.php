<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use Closure;
use Override;

/**
 * Feeds the AzGuard permission catalog from the Filament panel's resources and
 * pages — the "database" source. Discovered keys become known permissions, so
 * they appear in the Role UI and roles can be granted them without any code in
 * the Filament resources themselves.
 */
final readonly class FilamentPermissionCatalogBuilder implements PermissionCatalogBuilder
{
    public function __construct(
        private string $panelId,
        private PermissionSchema $schema,
        private PermissionDiscovery $discovery,
        /** @var (Closure(string): ?AzGuardPlugin)|null */
        private ?Closure $pluginForPanel = null,
    ) {}

    #[Override]
    public function build(string $panelId): array
    {
        if (! $this->supports($panelId)) {
            return [];
        }

        $plugin = $this->pluginForPanel === null ? null : ($this->pluginForPanel)($panelId);
        $schema = $plugin instanceof AzGuardPlugin
            ? $this->schema->withOptions($plugin->getKeyTemplate(), $plugin->getCase())
            : $this->schema;

        return $schema->definitions($panelId, $this->discovery->subjects($panelId));
    }

    #[Override]
    public function supports(string $panelId): bool
    {
        return $panelId === $this->panelId
            || ($this->pluginForPanel !== null && ($this->pluginForPanel)($panelId) instanceof AzGuardPlugin);
    }
}
