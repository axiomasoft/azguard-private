<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Diagnostics\Internal\DoctorStorages;
use AzGuard\Panels\Panel;
use AzGuard\Storage\StorageDescriptor;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * What one run of `azguard:doctor` inspects: the selected panels and storages, whether the run checks a production
 * deployment and the configuration. A check of a source, a panel or a plugin also gets the panel it runs for.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class DoctorContext
{
    private AzGuardOptions $config;

    /**
     * @param  list<Panel>  $panels
     * @param  list<StorageDescriptor>  $storages
     */
    #[Internal('built by the doctor')]
    public function __construct(
        private array $panels,
        private array $storages,
        private bool $production,
        ?AzGuardOptions $config = null,
        private ?Panel $panel = null,
    ) {
        $this->config = $config ?? AzGuardOptions::defaultsOnly();
    }

    /** @return list<Panel> the selected panels in registration order */
    public function panels(): array
    {
        return $this->panels;
    }

    /** @return list<StorageDescriptor> the selected storages */
    public function storages(): array
    {
        return $this->storages;
    }

    /** Whether the run checks a production deployment: `--production` or the production environment. */
    public function isProduction(): bool
    {
        return $this->production;
    }

    /** Compiled package configuration for doctor checks. */
    public function config(): AzGuardOptions
    {
        return $this->config;
    }

    /** The panel a check of a source, a panel or a plugin runs for; null for a check of the core. */
    public function panel(): ?Panel
    {
        return $this->panel;
    }

    /** The same run for a check that belongs to one panel. */
    public function forPanel(Panel $panel): self
    {
        return DoctorStorages::propagate(
            $this,
            new self($this->panels, $this->storages, $this->production, $this->config, $panel),
        );
    }
}
