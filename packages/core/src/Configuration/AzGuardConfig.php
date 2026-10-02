<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Config\Repository;

/**
 * Typed view of `config/azguard.php` and the only place the package configuration is read.
 *
 * A key the package does not know is an error, never a silently ignored typo.
 */
final readonly class AzGuardConfig
{
    private const array PANELS_KEYS = ['providers'];

    /**
     * @param  list<string>  $panelProviders
     */
    private function __construct(private array $panelProviders) {}

    /**
     * @throws InvalidConfigurationException
     */
    public static function fromRepository(Repository $config): self
    {
        $panels = $config->get('azguard.panels', []);

        if (! is_array($panels)) {
            throw new InvalidConfigurationException('azguard.panels must be an array, got '.get_debug_type($panels).'.');
        }

        self::assertKnownKeys('panels', $panels, self::PANELS_KEYS);

        return new self(self::panelProvidersFrom($panels['providers'] ?? []));
    }

    /**
     * @return list<string> classes of the panel providers listed in the configuration
     */
    public function panelProviders(): array
    {
        return $this->panelProviders;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    private static function panelProvidersFrom(mixed $providers): array
    {
        if (! is_array($providers)) {
            throw new InvalidConfigurationException(
                'azguard.panels.providers must be a list of panel provider classes, got '.get_debug_type($providers).'.',
            );
        }

        $classes = [];

        foreach ($providers as $provider) {
            if (! is_string($provider) || $provider === '') {
                throw new InvalidConfigurationException(
                    'azguard.panels.providers must list panel provider classes, got '.get_debug_type($provider).'.',
                );
            }

            $classes[] = $provider;
        }

        return $classes;
    }

    /**
     * @param  array<mixed>  $section
     * @param  list<string>  $known
     *
     * @throws InvalidConfigurationException
     */
    private static function assertKnownKeys(string $name, array $section, array $known): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($section)), $known);

        if ($unknown !== []) {
            throw new InvalidConfigurationException(
                'Unknown key'.(count($unknown) === 1 ? '' : 's').' in azguard.'.$name.': '.implode(', ', $unknown)
                .'. Known keys: '.implode(', ', $known).'.',
            );
        }
    }
}
