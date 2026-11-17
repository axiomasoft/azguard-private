<?php

declare(strict_types=1);

namespace AzGuard\Configuration\Internal;

use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Configuration\ConfigSections;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;

/**
 * Internal repository facade delegating typed compilation to {@see AzGuardOptions}.
 */
#[Internal]
final readonly class AzGuardConfig
{
    public const int DEFAULT_MAX_SET_SUBJECTS = AzGuardOptions::DEFAULT_MAX_SET_SUBJECTS;

    private function __construct(private AzGuardOptions $options) {}

    /**
     * @throws InvalidConfigurationException
     */
    public static function fromRepository(Repository $config, ?ConfigSections $sections = null): self
    {
        return new self(AzGuardOptions::fromRepository($config, $sections));
    }

    public function options(): AzGuardOptions
    {
        return $this->options;
    }

    public function cacheSigningAvailable(): bool
    {
        return $this->options->cacheSigningAvailable();
    }

    #[Internal('The raw namespace also partitions keys when signing is unavailable.')]
    public static function cacheNamespace(Repository $config): ?string
    {
        $namespace = $config->get('azguard.cache.namespace');

        return is_string($namespace) && $namespace !== '' && strlen($namespace) <= 255 && preg_match('//u', $namespace) === 1
            ? $namespace
            : null;
    }

    #[Internal('Reads and validates a cache memory limit.')]
    public static function cacheMemoLimit(Repository $config, string $key): int
    {
        return AzGuardOptions::memoLimit($key, $config->get('azguard.cache.'.$key, 1024));
    }

    /** @throws InvalidConfigurationException */
    public static function memoLimit(string $key, mixed $value): int
    {
        return AzGuardOptions::memoLimit($key, $value);
    }

    /** @return array<string, class-string> */
    public function defaultModels(): array
    {
        return $this->options->defaultModels();
    }

    /** @return list<class-string> */
    public function defaultTenantResolvers(): array
    {
        return $this->options->defaultTenantResolvers();
    }

    /** @return list<class-string> */
    public function defaultScopeResolvers(): array
    {
        return $this->options->defaultScopeResolvers();
    }

    public function gateEnabled(): bool
    {
        return $this->options->gateEnabled();
    }

    public function maxSetSubjects(): int
    {
        return $this->options->maxSetSubjects();
    }

    public function scheduleEnabled(): bool
    {
        return $this->options->scheduleEnabled();
    }

    public function pruneExpiredFrequency(): ?string
    {
        return $this->options->pruneExpiredFrequency();
    }

    /** @return list<string> */
    public static function knownKeys(): array
    {
        return AzGuardOptions::knownKeys();
    }

    /** @return array<string, array{connection: ?string, table_prefix: string, host_keys: string}> */
    public function storages(): array
    {
        return $this->options->storages();
    }

    public function hostKeys(): string
    {
        return $this->options->hostKeys();
    }

    /** @return list<string> */
    public function panelProviders(): array
    {
        return $this->options->panelProviders();
    }

    /** @return array<string, bool|int|string|null> */
    public function defaults(): array
    {
        return $this->options->defaults();
    }

    /** @param list<class-string> $panelProviders */
    public function buildId(array $panelProviders): string
    {
        return $this->options->buildId($panelProviders);
    }

    public function configuredBuildId(): ?string
    {
        return $this->options->configuredBuildId();
    }

    public function catalogCachePath(): ?string
    {
        return $this->options->catalogCachePath();
    }

    /** @return array<string, mixed> */
    public function source(string $name): array
    {
        return $this->options->source($name);
    }

    /** @return array<string, array<string, mixed>> */
    public function sources(): array
    {
        return $this->options->sources();
    }

    /** @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string} */
    public function discovery(): array
    {
        return $this->options->discovery();
    }
}
