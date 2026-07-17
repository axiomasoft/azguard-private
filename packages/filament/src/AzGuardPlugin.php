<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Resources\Resource;
use Override;

/**
 * AzGuard Filament plugin (Filament 5).
 *
 * Register in your PanelProvider:
 *
 * $panel->plugins([
 *     AzGuardPlugin::make()->forPanel('admin'),
 * ])
 */
final class AzGuardPlugin implements Plugin
{
    private ?string $panelId = null;

    public static function make(): static
    {
        return new self;
    }

    #[Override]
    public function getId(): string
    {
        return 'az-guard';
    }

    /**
     * Specify which AzGuard panel the management UI is shown for.
     * Allows filtering permissions and roles to that panel only.
     */
    public function forPanel(string $panelId): static
    {
        $this->panelId = $panelId;

        return $this;
    }

    public function getPanelId(): string
    {
        if ($this->panelId !== null) {
            return $this->panelId;
        }

        return config('az-guard-filament.panel', 'admin');
    }

    #[Override]
    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                RoleResource::class,
                DirectGrantResource::class,
            ])
            ->pages([
                DoctorPage::class,
            ]);
    }

    #[Override]
    public function boot(Panel $panel): void
    {
        // For the "policy" source, generated policies enforce via Filament's
        // native authorization, so leave the policy-existence check in place.
        if (! config('az-guard-filament.enforce', true) || config('az-guard-filament.source', 'database') === 'policy') {
            return;
        }

        // Force Filament to consult the Gate for every resource (instead of
        // allowing when no policy exists) so AzGuard's ResourceGate enforces
        // the generated permissions — no per-resource code required.
        foreach ($panel->getResources() as $resource) {
            if (is_subclass_of($resource, Resource::class)) {
                $resource::checkPolicyExistence(false);
            }
        }
    }
}
