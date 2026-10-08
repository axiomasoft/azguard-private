<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Exceptions\InvalidConfigurationException;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use ReflectionMethod;

/**
 * Typed view of `config/azguard.php` and the only place the package configuration is read.
 *
 * A key the package does not know is an error, never a silently ignored typo.
 */
final readonly class AzGuardConfig
{
    /** Keys of the root of `config/azguard.php`. */
    private const array ROOT_KEYS = ['panels', 'storages', 'ids', 'sources', 'defaults', 'gate', 'schedule', 'catalog', 'discovery', 'scaffold'];

    private const array PANELS_KEYS = ['providers'];

    private const array GATE_KEYS = ['enabled'];

    private const array SCHEDULE_KEYS = ['enabled', 'prune_expired'];

    private const array SCAFFOLD_KEYS = ['namespace', 'path'];

    private const array STORAGE_KEYS = ['connection', 'table_prefix', 'host_keys'];

    private const array MODEL_KEYS = ['role_grant', 'permission_grant', 'permission'];

    private const array RESOLVER_GROUP_KEYS = ['resolvers'];

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
        'defaults' => ['resource_prefix', 'gate', 'cache', 'consistency', 'trace_decisions', 'models', 'tenants', 'scopes'],
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
        /** @var array<string, array{connection: ?string, table_prefix: string, host_keys: string}> */
        private array $storages,
        private string $hostKeys,
        /** @var array<string, class-string> */
        private array $models,
        /** @var list<class-string> */
        private array $tenantResolvers,
        /** @var list<class-string> */
        private array $scopeResolvers,
        private bool $gateEnabled,
        private bool $scheduleEnabled,
        private ?string $pruneExpiredFrequency,
        private string $scaffoldNamespace,
        private string $scaffoldPath,
    ) {}

    /**
     * @throws InvalidConfigurationException
     */
    public static function fromRepository(Repository $config): self
    {
        self::assertKnownKeys('', self::section('', $config->get('azguard', [])), self::ROOT_KEYS);
        $panels = self::section('panels', $config->get('azguard.panels', []));
        self::assertKnownKeys('panels', $panels, self::PANELS_KEYS);
        $catalog = self::section('catalog', $config->get('azguard.catalog', []));
        self::assertKnownKeys('catalog', $catalog, self::CATALOG_KEYS);

        $ids = self::section('ids', $config->get('azguard.ids', []));
        self::assertKnownKeys('ids', $ids, ['host_keys']);
        $hostKeys = self::hostKeysFrom($ids['host_keys'] ?? 'string');
        $defaults = self::section('defaults', $config->get('azguard.defaults', []));
        $gate = self::section('gate', $config->get('azguard.gate', []));
        self::assertKnownKeys('gate', $gate, self::GATE_KEYS);
        $schedule = self::section('schedule', $config->get('azguard.schedule', []));
        self::assertKnownKeys('schedule', $schedule, self::SCHEDULE_KEYS);
        $scaffold = self::section('scaffold', $config->get('azguard.scaffold', []));
        self::assertKnownKeys('scaffold', $scaffold, self::SCAFFOLD_KEYS);

        return new self(
            self::panelProvidersFrom($panels['providers'] ?? []),
            self::defaultsFrom($defaults),
            self::buildIdFrom($catalog['build_id'] ?? null),
            self::cachePathFrom($catalog['cache_path'] ?? null),
            self::sourcesFrom($config->get('azguard.sources', [])),
            self::discoveryFrom(self::section('discovery', $config->get('azguard.discovery', []))),
            self::storagesFrom($config->get('azguard.storages', ['default' => []]), $hostKeys),
            $hostKeys,
            self::modelsFrom($defaults['models'] ?? []),
            self::resolversFrom('defaults.tenants', $defaults['tenants'] ?? []),
            self::resolversFrom('defaults.scopes', $defaults['scopes'] ?? []),
            self::flag('gate.enabled', $gate['enabled'] ?? true),
            self::flag('schedule.enabled', $schedule['enabled'] ?? true),
            self::frequencyFrom(array_key_exists('prune_expired', $schedule) ? $schedule['prune_expired'] : 'daily'),
            self::namespaceFrom($scaffold['namespace'] ?? 'App\\Guards'),
            self::pathFrom($scaffold['path'] ?? 'app/Guards'),
        );
    }

    /** @return array<string, class-string> */
    public function defaultModels(): array
    {
        return $this->models;
    }

    /**
     * Resolvers of the current tenant every panel tries after its own, in the order the configuration lists them.
     *
     * @return list<class-string>
     */
    public function defaultTenantResolvers(): array
    {
        return $this->tenantResolvers;
    }

    /**
     * Resolvers of the current assignment scope every panel tries after its own, in the order the configuration lists them.
     *
     * @return list<class-string>
     */
    public function defaultScopeResolvers(): array
    {
        return $this->scopeResolvers;
    }

    /** Whether the package registers its `Gate::before` callback. */
    public function gateEnabled(): bool
    {
        return $this->gateEnabled;
    }

    /** Whether the package registers its tasks in the Laravel scheduler. */
    public function scheduleEnabled(): bool
    {
        return $this->scheduleEnabled;
    }

    /**
     * How often expired grants are pruned: the name of a frequency method of the Laravel scheduler (`daily`) or a
     * cron expression. Null registers no pruning.
     */
    public function pruneExpiredFrequency(): ?string
    {
        return $this->pruneExpiredFrequency;
    }

    /** Namespace the generators put the panel directories in, without the leading or trailing backslash. */
    public function scaffoldNamespace(): string
    {
        return $this->scaffoldNamespace;
    }

    /** Directory the generators put the panel directories in, relative to the application, without the trailing slash. */
    public function scaffoldPath(): string
    {
        return $this->scaffoldPath;
    }

    /**
     * Every key of the configuration file the package accepts, as a dotted path. A name chosen by the application
     * is written `*`: `storages.*.connection`, and `sources` stands for the whole map of source parameters.
     *
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        $keys = ['sources', 'panels.providers', 'defaults.resource_prefix', 'defaults.trace_decisions',
            'defaults.tenants.resolvers', 'defaults.scopes.resolvers'];
        $groups = [
            'ids' => ['host_keys'], 'catalog' => self::CATALOG_KEYS, 'discovery' => self::DISCOVERY_KEYS, 'gate' => self::GATE_KEYS,
            'schedule' => self::SCHEDULE_KEYS, 'scaffold' => self::SCAFFOLD_KEYS, 'storages.*' => self::STORAGE_KEYS,
            'defaults.models' => self::MODEL_KEYS, 'defaults.gate' => self::DEFAULTS_KEYS['defaults.gate'],
            'defaults.cache' => self::DEFAULTS_KEYS['defaults.cache'], 'defaults.consistency' => self::DEFAULTS_KEYS['defaults.consistency'],
        ];

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $keys[] = $group.'.'.$name;
            }
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return list<class-string>
     *
     * @throws InvalidConfigurationException
     */
    private static function resolversFrom(string $name, mixed $group): array
    {
        $group = self::section($name, $group);
        self::assertKnownKeys($name, $group, self::RESOLVER_GROUP_KEYS);
        $resolvers = $group['resolvers'] ?? [];

        if (! is_array($resolvers) || ! array_is_list($resolvers)) {
            throw self::invalidValue($name.'.resolvers', $resolvers, 'a list of class names');
        }

        foreach ($resolvers as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw self::invalidValue($name.'.resolvers', $class, 'an existing class');
            }
        }

        /** @var list<class-string> $resolvers */
        return $resolvers;
    }

    private static function flag(string $key, mixed $value): bool
    {
        return is_bool($value) ? $value : throw self::invalidValue($key, $value, 'true or false');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function frequencyFrom(mixed $frequency): ?string
    {
        if ($frequency === null) {
            return null;
        }

        if (is_string($frequency) && preg_match('/\A(?:\S+ ){4}\S+\z/', $frequency) === 1
            && CronExpression::isValidExpression($frequency)) {
            return $frequency;
        }

        if (is_string($frequency) && method_exists(ManagesFrequencies::class, $frequency)
            && (new ReflectionMethod(ManagesFrequencies::class, $frequency))->isPublic()
            && (new ReflectionMethod(ManagesFrequencies::class, $frequency))->getNumberOfRequiredParameters() === 0
            && ! (new ReflectionMethod(ManagesFrequencies::class, $frequency))->isVariadic()) {
            return $frequency;
        }

        throw self::invalidValue('schedule.prune_expired', $frequency, 'a frequency method of the Laravel scheduler without arguments, a cron expression or null');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function namespaceFrom(mixed $namespace): string
    {
        $namespace = is_string($namespace) ? trim($namespace, '\\') : null;

        return $namespace !== null && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $namespace) === 1
            ? $namespace
            : throw self::invalidValue('scaffold.namespace', $namespace, 'a PHP namespace');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function pathFrom(mixed $path): string
    {
        $path = is_string($path) ? rtrim(str_replace('\\', '/', $path), '/') : null;

        return $path !== null && $path !== '' && ! str_contains($path, "\0") && ! str_starts_with($path, '/')
            && preg_match('/\A[a-z][a-z0-9+.-]*:/i', $path) !== 1 && ! in_array('..', explode('/', $path), true)
            ? $path
            : throw self::invalidValue('scaffold.path', $path, 'a directory relative to the application');
    }

    /** @return array<string, class-string> */
    private static function modelsFrom(mixed $models): array
    {
        $models = self::section('defaults.models', $models);
        $defaults = [
            'role_grant' => 'AzGuard\\Storage\\Models\\RoleGrant',
            'permission_grant' => 'AzGuard\\Storage\\Models\\PermissionGrant',
            'permission' => 'AzGuard\\Storage\\Models\\Permission',
        ];
        self::assertKnownKeys('defaults.models', $models, self::MODEL_KEYS);
        $parsed = [];
        foreach ([...$defaults, ...$models] as $kind => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw self::invalidValue('defaults.models.'.$kind, $class, 'an existing model class');
            }
            $parsed[$kind] = $class;
        }

        return $parsed;
    }

    /** @return array<string, array{connection: ?string, table_prefix: string, host_keys: string}> */
    public function storages(): array
    {
        return $this->storages;
    }

    public function hostKeys(): string
    {
        return $this->hostKeys;
    }

    private static function hostKeysFrom(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['string', 'bigint', 'uuid', 'ulid'], true)) {
            throw InvalidConfigurationException::failing('host_keys', 'Host keys must be string, bigint, uuid or ulid.');
        }

        return $value;
    }

    /** @return array<string, array{connection: ?string, table_prefix: string, host_keys: string}> */
    private static function storagesFrom(mixed $storages, string $hostKeys): array
    {
        $parsed = [];
        foreach (self::section('storages', $storages) as $name => $parameters) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) !== 1) {
                throw InvalidConfigurationException::failing('storage', 'Invalid storage name.');
            }
            $parameters = self::section('storages.'.$name, $parameters);
            self::assertKnownKeys('storages.'.$name, $parameters, self::STORAGE_KEYS);
            $connection = $parameters['connection'] ?? null;
            $prefix = $parameters['table_prefix'] ?? 'azg_';

            if (($connection !== null && (! is_string($connection) || $connection === ''))
                || ! is_string($prefix) || preg_match('/\A([a-z][a-z0-9_]{0,19})?\z/', $prefix) !== 1) {
                throw InvalidConfigurationException::failing('storage', 'Invalid connection or table prefix for storage '.$name.'.');
            }
            $parsed[$name] = ['connection' => $connection, 'table_prefix' => $prefix,
                'host_keys' => self::hostKeysFrom($parameters['host_keys'] ?? $hostKeys)];
        }

        return $parsed;
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
            : throw new InvalidConfigurationException(rtrim('azguard.'.$name, '.').' must be an array, got '.get_debug_type($section).'.');
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
                'Unknown key'.(count($unknown) === 1 ? '' : 's').' in '.rtrim('azguard.'.$name, '.').': '.implode(', ', $unknown)
                .'. Known keys: '.implode(', ', $known).'.',
            );
        }
    }
}
