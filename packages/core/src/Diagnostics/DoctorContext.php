<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Panels\Panel;
use AzGuard\Storage\Storage;

/**
 * What one run of `azguard:doctor` inspects: the selected panels and storages, whether the run checks a production
 * deployment and the configuration. A check of a source, a panel or a plugin also gets the panel it runs for.
 *
 * @api
 */
final readonly class DoctorContext
{
    /**
     * @param  list<Panel>  $panels
     * @param  list<Storage>  $storages
     */
    public function __construct(
        private array $panels,
        private array $storages,
        private bool $production,
        private AzGuardConfig $config,
        private ?Panel $panel = null,
    ) {}

    /** @return list<Panel> the selected panels in registration order */
    public function panels(): array
    {
        return $this->panels;
    }

    /** @return list<Storage> the selected storages */
    public function storages(): array
    {
        return $this->storages;
    }

    /** Whether the run checks a production deployment: `--production` or the production environment. */
    public function isProduction(): bool
    {
        return $this->production;
    }

    public function config(): AzGuardConfig
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
        return new self($this->panels, $this->storages, $this->production, $this->config, $panel);
    }
}
