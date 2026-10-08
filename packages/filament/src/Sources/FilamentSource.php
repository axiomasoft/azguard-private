<?php

declare(strict_types=1);

namespace AzGuard\Filament\Sources;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Filament\Facades\Filament;

/**
 * Permission definitions read from the resources, pages and widgets of a Filament panel.
 *
 * Attach it to the guard panel with `permissions([FilamentSource::make('admin')])` when the plugin of the Filament panel
 * uses `FilamentDefinitions::Resources`. The definitions are built when the catalog of the panel is compiled, not
 * stored; who decides them is the explicit authority of the plugin. A permission that an enum of the panel defines
 * too is a conflict of the catalog, not a merge.
 *
 * @api
 */
final readonly class FilamentSource implements DescribesSchema, ProvidesPermissions
{
    private function __construct(private string $filamentPanel) {}

    public static function make(string $filamentPanel): self
    {
        return new self($filamentPanel);
    }

    public function id(): string
    {
        return 'filament';
    }

    public function filamentPanel(): string
    {
        return $this->filamentPanel;
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /**
     * @return list<PermissionDefinition>
     *
     * @throws InvalidConfigurationException when the Filament panel or its plugin is missing or points to another guard panel
     */
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        $filament = (Filament::getPanels()[$this->filamentPanel] ?? null)
            ?? throw InvalidConfigurationException::failing('filament', 'FilamentSource of the AzGuard panel "'.$panel->id().'" names the Filament panel "'.$this->filamentPanel.'", which is not registered.');

        $plugin = AzGuardPlugin::get($this->filamentPanel);

        if ($plugin->getGuardPanel() !== $panel->id()) {
            throw InvalidConfigurationException::failing('filament', 'FilamentSource of the AzGuard panel "'.$panel->id().'" reads the Filament panel "'.$this->filamentPanel
                .'", whose guard panel is "'.$plugin->getGuardPanel().'".');
        }

        if ($plugin->getDefinitions() !== FilamentDefinitions::Resources) {
            throw InvalidConfigurationException::failing('filament', 'FilamentSource of the AzGuard panel "'.$panel->id().'" needs definitions(FilamentDefinitions::Resources) on the plugin of the Filament panel "'
                .$this->filamentPanel.'"; with Enums the permissions come from the enums of the panel.');
        }

        $definitions = [];

        foreach ($plugin->keys($filament)->all() as $key) {
            $definitions[] = new PermissionDefinition(
                local: $key->local,
                authority: $plugin->getAuthority(),
                label: $key->label,
                group: $key->group,
                resourceModel: $key->model,
            );
        }

        return $definitions;
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        return new SourceDescription(
            id: $this->id(),
            class: self::class,
            capabilities: [ProvidesPermissions::class],
            dynamic: false,
            label: 'Filament '.$this->filamentPanel,
        );
    }
}
