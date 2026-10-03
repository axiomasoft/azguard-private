<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;

/**
 * Typed view of `config/azguard.php` and the only place the package configuration is read.
 *
 * A key the package does not know is an error, never a silently ignored typo.
 */
final readonly class AzGuardConfig
{
    private const array PANELS_KEYS = ['providers'];

    private const array CATALOG_KEYS = ['build_id', 'cache_path'];

    private const array DISCOVERY_KEYS = ['permissions', 'policies', 'roles', 'scopes', 'abilities', 'queries', 'shared'];

    /** @var array<string, string> */
    private const array DISCOVERY_DEFAULTS = [
        'permissions' => 'Permissions',
        'policies' => 'Policies',
        'roles' => 'Roles',
        'scopes' => 'Scopes',
        'abilities' => 'Abilities',
        'queries' => 'Queries',
        'shared' => 'Shared',
    ];

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
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, string>  $discovery
     */
    private function __construct(
        private array $panelProviders,
        private array $defaults,
        private ?string $buildId,
        private ?string $catalogCachePath,
        private array $sources,
        /** @var array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string} */
        private array $discovery,
    ) {}

    /**
     * @throws InvalidConfigurationException
     */
    public static function fromRepository(Repository $config): self
    {
        $panels = self::section('panels', $config->get('azguard.panels', []));
        self::assertKnownKeys('panels', $panels, self::PANELS_KEYS);
        $catalog = self::section('catalog', $config->get('azguard.catalog', []));
        self::assertKnownKeys('catalog', $catalog, self::CATALOG_KEYS);

        return new self(
            self::panelProvidersFrom($panels['providers'] ?? []),
            self::defaultsFrom(self::section('defaults', $config->get('azguard.defaults', []))),
            self::buildIdFrom($catalog['build_id'] ?? null),
            self::cachePathFrom($catalog['cache_path'] ?? null),
            self::sourcesFrom($config->get('azguard.sources', [])),
            self::discoveryFrom(self::section('discovery', $config->get('azguard.discovery', []))),
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
     * Id of the deployed build: `catalog.build_id` when it is set, otherwise a hash of the files of the panel
     * providers.
     *
     * The fallback is the same on every request of the same code and is known before any plugin registers; it does
     * not follow changes outside the provider files, so a deployment sets `catalog.build_id`.
     *
     * @param  list<class-string>  $panelProviders  classes of the registered panel providers
     */
    public function buildId(array $panelProviders): string
    {
        if ($this->buildId !== null) {
            return $this->buildId;
        }

        $files = [];

        foreach ($panelProviders as $provider) {
            $file = (new ReflectionClass($provider))->getFileName();
            $files[$provider] = $file === false ? '' : (string) hash_file('sha256', $file);
        }

        ksort($files, SORT_STRING);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The file of the catalog cache, or null for the default `bootstrap/cache/azguard.php`.
     */
    public function catalogCachePath(): ?string
    {
        return $this->catalogCachePath;
    }

    /**
     * Parameters of one named source. An unknown name and a missing section are an empty array.
     *
     * @return array<string, mixed>
     */
    public function source(string $name): array
    {
        return $this->sources[$name] ?? [];
    }

    /**
     * Folder names discovery uses, keyed by `permissions`, `policies`, `roles`, `scopes`, `abilities`, `queries`
     * and `shared`. A missing key keeps its default.
     *
     * @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}
     */
    public function discovery(): array
    {
        return $this->discovery;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidConfigurationException
     */
    private static function sourcesFrom(mixed $sources): array
    {
        if (! is_array($sources)) {
            throw new InvalidConfigurationException(
                'azguard.sources must be an array of source parameters, got '.get_debug_type($sources).'.',
            );
        }

        $parsed = [];

        foreach ($sources as $name => $parameters) {
            if (! is_string($name) || $name === '') {
                throw new InvalidConfigurationException(
                    'azguard.sources must be keyed by source name, got '.get_debug_type($name).'.',
                );
            }

            if (! is_array($parameters)) {
                throw new InvalidConfigurationException(
                    'azguard.sources.'.$name.' must be an array of parameters, got '.get_debug_type($parameters).'.',
                );
            }

            $parsed[$name] = $parameters;
        }

        return $parsed;
    }

    /**
     * @param  array<mixed>  $discovery
     * @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}
     *
     * @throws InvalidConfigurationException
     */
    private static function discoveryFrom(array $discovery): array
    {
        self::assertKnownKeys('discovery', $discovery, self::DISCOVERY_KEYS);
        $names = self::DISCOVERY_DEFAULTS;

        foreach (self::DISCOVERY_KEYS as $key) {
            if (! array_key_exists($key, $discovery)) {
                continue;
            }

            $value = $discovery[$key];

            if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
                throw new InvalidConfigurationException(
                    'azguard.discovery.'.$key.' must be a folder name, got '.get_debug_type($value).'.',
                );
            }

            $names[$key] = trim(str_replace('\\', '/', $value), '/');
        }

        return $names;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function cachePathFrom(mixed $path): ?string
    {
        return match (true) {
            $path === null, $path === '' => null,
            is_string($path) => $path,
            default => throw self::invalidValue('catalog.cache_path', $path, 'a file path or null'),
        };
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function buildIdFrom(mixed $buildId): ?string
    {
        return match (true) {
            $buildId === null, $buildId === '' => null,
            is_string($buildId) => $buildId,
            default => throw self::invalidValue('catalog.build_id', $buildId, 'a string or null'),
        };
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
