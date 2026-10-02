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

    /** Keys of the `defaults` section and of its nested groups. */
    private const array DEFAULTS_KEYS = [
        'defaults' => ['resource_prefix', 'gate', 'cache', 'consistency', 'trace_decisions'],
        'defaults.gate' => ['mode'],
        'defaults.cache' => ['store', 'ttl', 'generation'],
        'defaults.consistency' => ['reads', 'state_refresh'],
    ];

    /**
     * @param  list<string>  $panelProviders
     * @param  array<string, bool|int|string|null>  $defaults
     */
    private function __construct(
        private array $panelProviders,
        private array $defaults,
    ) {}

    /**
     * @throws InvalidConfigurationException
     */
    public static function fromRepository(Repository $config): self
    {
        $panels = self::section('panels', $config->get('azguard.panels', []));
        self::assertKnownKeys('panels', $panels, self::PANELS_KEYS);

        return new self(
            self::panelProvidersFrom($panels['providers'] ?? []),
            self::defaultsFrom(self::section('defaults', $config->get('azguard.defaults', []))),
        );
    }

    /**
     * @return list<string> classes of the panel providers listed in the configuration
     */
    public function panelProviders(): array
    {
        return $this->panelProviders;
    }

    /**
     * Values of the `defaults` section the configuration sets, by setting name (`cache.ttl`, `gate.mode`).
     *
     * Enumerated values stay strings: the panel compiler checks them against their enums.
     *
     * @return array<string, bool|int|string|null>
     */
    public function defaults(): array
    {
        return $this->defaults;
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
     * @param  array<mixed>  $defaults
     * @return array<string, bool|int|string|null>
     *
     * @throws InvalidConfigurationException
     */
    private static function defaultsFrom(array $defaults): array
    {
        self::assertKnownKeys('defaults', $defaults, self::DEFAULTS_KEYS['defaults']);
        $values = [];

        foreach (['resource_prefix', 'trace_decisions'] as $flag) {
            if (array_key_exists($flag, $defaults)) {
                $values[$flag] = is_bool($defaults[$flag])
                    ? $defaults[$flag]
                    : throw self::invalidValue('defaults.'.$flag, $defaults[$flag], 'true or false');
            }
        }

        foreach (['gate', 'cache', 'consistency'] as $group) {
            $section = self::section('defaults.'.$group, $defaults[$group] ?? []);
            self::assertKnownKeys('defaults.'.$group, $section, self::DEFAULTS_KEYS['defaults.'.$group]);

            foreach ($section as $key => $value) {
                $name = $group.'.'.$key;
                $values[$name] = match ($name) {
                    'cache.ttl', 'cache.generation' => self::integer('defaults.'.$name, $value, $name === 'cache.ttl'),
                    'cache.store' => $value === null || (is_string($value) && $value !== '')
                        ? $value
                        : throw self::invalidValue('defaults.'.$name, $value, 'the name of a cache store or null'),
                    default => is_string($value)
                        ? $value
                        : throw InvalidConfigurationException::failing(
                            'enum',
                            'azguard.defaults.'.$name.' must be a string, got '.get_debug_type($value).'.',
                        ),
                };
            }
        }

        return $values;
    }

    /**
     * An integer, also when it arrives from the environment as a string of digits.
     *
     * @throws InvalidConfigurationException
     */
    private static function integer(string $key, mixed $value, bool $nullable): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 => (int) $value,
            $value === null && $nullable => null,
            default => throw InvalidConfigurationException::failing(
                'enum',
                'azguard.'.$key.' must be an integer'.($nullable ? ' or null' : '').', got '.get_debug_type($value).'.',
            ),
        };
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidConfigurationException
     */
    private static function section(string $name, mixed $section): array
    {
        return is_array($section)
            ? $section
            : throw new InvalidConfigurationException('azguard.'.$name.' must be an array, got '.get_debug_type($section).'.');
    }

    private static function invalidValue(string $key, mixed $value, string $expected): InvalidConfigurationException
    {
        return new InvalidConfigurationException('azguard.'.$key.' must be '.$expected.', got '.get_debug_type($value).'.');
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
