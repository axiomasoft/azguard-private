<?php

declare(strict_types=1);

namespace AzGuard\Laravel\About;

use AzGuard\Catalog\CacheState;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\SourceManager;
use Composer\InstalledVersions;
use Throwable;

/**
 * The AzGuard section of `php artisan about`: version, panels with their sources, storage and state version, the named
 * sources, the host key type, the cache store and the freshness of the catalog cache.
 *
 * The section reads the compiled configuration and the version column of each storage. A storage that cannot be read
 * shows `unavailable`; the section never prints the reason, a connection or any secret, and never throws.
 *
 * @internal
 */
final readonly class AboutSection
{
    public const string TITLE = 'AzGuard';

    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        private PanelRegistry $registry,
        private SourceManager $sources,
        private AzGuardConfig $config,
    ) {}

    /** @return array<string, string> */
    public function __invoke(): array
    {
        $rows = [
            'Version' => $this->version(),
            'Catalog cache' => $this->cache(),
            'Host keys' => $this->config->hostKeys(),
            'Named sources' => $this->named(),
        ];

        try {
            foreach ($this->registry->all() as $panel) {
                $rows['Panel '.$panel->id()] = $this->describe($panel);
            }
        } catch (Throwable) {
            $rows['Panels'] = self::UNAVAILABLE;
        }

        return $rows;
    }

    private function version(): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('axiomasoft/azguard')) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion('axiomasoft/azguard') ?? 'unknown';
    }

    /**
     * The catalog cache with the state the doctor reports for a production deployment.
     */
    private function cache(): string
    {
        try {
            $state = $this->registry->cacheState();
            $stale = $state === CacheState::Stale
                ? array_keys(array_filter($this->registry->all(), fn (Panel $panel): bool => ! $this->registry->isCached($panel->id())))
                : [];

            return $stale === [] ? $state->value : $state->value.' ('.implode(', ', $stale).')';
        } catch (Throwable) {
            return self::UNAVAILABLE;
        }
    }

    private function named(): string
    {
        $names = array_keys($this->sources->names());

        return $names === [] ? 'none' : implode(', ', $names);
    }

    private function describe(Panel $panel): string
    {
        $writer = $panel->writer();
        $storage = $writer instanceof DatabaseSource ? $writer->boundStorage() : null;
        $parts = [
            $panel->isDefault() ? 'default' : 'not default',
            'sources: '.($panel->sources() === [] ? 'none' : implode(', ', array_map(static fn (SourceDescription $source): string => $source->id, $panel->sources()))),
            'storage: '.($storage === null ? 'none' : $storage->id()),
            'cache store: '.($panel->settings()->cacheStore() ?? 'default'),
        ];

        if ($storage !== null) {
            try {
                $state = $storage->state($panel->id());
                $parts[] = 'state version: '.($state === null ? 'none' : (string) $state->version);
            } catch (Throwable) {
                $parts[] = 'state version: '.self::UNAVAILABLE;
            }
        }

        return implode('; ', $parts);
    }
}
