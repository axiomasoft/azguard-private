<?php

declare(strict_types=1);

namespace AzGuard\Configuration;

use AzGuard\Configuration\Internal\CacheSigningConfig;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Validated, immutable compilation of `config/azguard.php` obeying a closed schema.
 */
#[Api(since: '1.0')]
final readonly class AzGuardOptions implements OptionsFactory
{
    /** Default of `decision_sets.max_subjects`. */
    public const int DEFAULT_MAX_SET_SUBJECTS = 500;

    /**
     * Optional tooling section tolerated in config when DevTools (`--no-dev`) is absent.
     *
     * @var list<string>
     */
    private const array OPTIONAL_SECTIONS = ['scaffold'];

    /** @var list<string> */
    public const array ROOT_KEYS = [
        'panels',
        'storages',
        'ids',
        'sources',
        'defaults',
        'gate',
        'schedule',
        'catalog',
        'discovery',
        'decision_sets',
        'cache',
    ];

    /** @var list<string> */
    private const array PANELS_KEYS = ['providers'];

    /** @var list<string> */
    private const array GATE_KEYS = ['enabled'];

    /** @var list<string> */
    private const array SCHEDULE_KEYS = ['enabled', 'prune_expired'];

    /** @var list<string> */
    private const array DECISION_SETS_KEYS = ['max_subjects'];

    /** @var list<string> */
    private const array CACHE_KEYS = [
        'namespace',
        'signing_key',
        'key_id',
        'previous_keys',
        'max_memo_entries',
        'max_state_entries',
    ];

    /** @var list<string> */
    private const array IDS_KEYS = ['host_keys'];

    /** @var list<string> */
    private const array STORAGE_KEYS = ['connection', 'table_prefix', 'host_keys'];

    /** @var list<string> */
    private const array MODEL_KEYS = ['role_grant', 'permission_grant', 'permission'];

    /** @var list<string> */
    private const array RESOLVER_GROUP_KEYS = ['resolvers'];

    /** @var list<string> */
    private const array CATALOG_KEYS = ['build_id', 'cache_path'];

    /** @var list<string> */
    private const array DISCOVERY_KEYS = ['permissions', 'policies', 'roles', 'scopes', 'abilities', 'queries', 'shared'];

    /** @var array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string} */
    private const array DISCOVERY_DEFAULTS = [
        'permissions' => 'Permissions',
        'policies' => 'Policies',
        'roles' => 'Roles',
        'scopes' => 'Scopes',
        'abilities' => 'Abilities',
        'queries' => 'Queries',
        'shared' => 'Shared',
    ];

    /** @var array<string, list<string>> */
    private const array DEFAULTS_KEYS = [
        'defaults' => ['resource_prefix', 'cache', 'consistency', 'trace_decisions', 'models', 'tenants', 'scopes'],
        'defaults.cache' => ['store', 'ttl', 'generation'],
        'defaults.consistency' => ['reads', 'state_refresh'],
    ];

    /**
     * @param  list<string>  $panelProviders
     * @param  array<string, bool|int|string|null>  $defaults
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}  $discovery
     * @param  array<string, array{connection: ?string, table_prefix: string, host_keys: string}>  $storages
     * @param  array<string, class-string>  $models
     * @param  list<class-string>  $tenantResolvers
     * @param  list<class-string>  $scopeResolvers
     */
    private function __construct(
        private array $panelProviders,
        private array $defaults,
        private ?string $buildId,
        private ?string $catalogCachePath,
        private array $sources,
        private array $discovery,
        private array $storages,
        private string $hostKeys,
        private array $models,
        private array $tenantResolvers,
        private array $scopeResolvers,
        private bool $gateEnabled,
        private bool $scheduleEnabled,
        private ?string $pruneExpiredFrequency,
        private int $maxSetSubjects,
        private bool $cacheSigningAvailable,
        private SecretRef $cacheSigningKey,
        private ?string $cacheNamespace,
        private int $maxMemoEntries,
        private int $maxStateEntries,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path = 'azguard'): self
    {
        $validator = new ConfigurationValidator;
        $sections = Container::getInstance()->bound(ConfigSections::class)
            ? Container::getInstance()->make(ConfigSections::class)
            : null;
        $owned = $sections instanceof ConfigSections ? $sections->peekOwned() : [];
        $allowedRoots = [...self::ROOT_KEYS, ...self::OPTIONAL_SECTIONS, ...array_keys($owned)];

        $validator->rejectUnknownKeys($config, $allowedRoots, $path);
        $validator->failIfAny();

        foreach (self::OPTIONAL_SECTIONS as $optionalRoot) {
            if (array_key_exists($optionalRoot, $config) && ! array_key_exists($optionalRoot, $owned)) {
                self::validateOptionalSection($optionalRoot, $config[$optionalRoot], $path, $validator);
            }
        }

        foreach ($owned as $root => $validate) {
            if (! array_key_exists($root, $config)) {
                continue;
            }
            $section = $config[$root];

            if (! is_array($section)) {
                $validator->add("{$path}.{$root}", 'must be an array');

                continue;
            }
            $map = [];
            foreach ($section as $k => $v) {
                $map[(string) $k] = $v;
            }
            $validate($map);
        }
        $validator->failIfAny();

        try {
            return self::build($config, $path, $validator, failWithValidator: true);
        } catch (InvalidConfigurationException $error) {
            throw new InvalidConfiguration([$path.': '.$error->getMessage()], $error->getMessage());
        }
    }

    /**
     * @throws InvalidConfigurationException
     */
    #[Internal('Compiles repository config with AzGuard section ownership semantics.')]
    public static function fromRepository(Repository $config, ?ConfigSections $sections = null): self
    {
        $sections ??= Container::getInstance()->bound(ConfigSections::class)
            ? Container::getInstance()->make(ConfigSections::class)
            : new ConfigSections;
        $owned = $sections->owned();
        $claimed = array_intersect(array_keys($owned), self::ROOT_KEYS);

        if ($claimed !== []) {
            throw new InvalidConfigurationException('The section azguard.'.implode(', azguard.', $claimed).' belongs to core and cannot have another owner.');
        }

        $raw = self::section('', $config->get('azguard', []));
        self::assertKnownKeys('', $raw, [...self::ROOT_KEYS, ...self::OPTIONAL_SECTIONS, ...array_keys($owned)]);

        foreach (self::OPTIONAL_SECTIONS as $optionalRoot) {
            if (array_key_exists($optionalRoot, $raw) && ! array_key_exists($optionalRoot, $owned)) {
                self::validateOptionalSection($optionalRoot, self::rawKey($raw, $optionalRoot));
            }
        }

        foreach ($owned as $root => $validate) {
            $section = self::section($root, self::rawKey($raw, $root));
            $map = [];

            foreach ($section as $key => $value) {
                $map[is_string($key) ? $key : throw new InvalidConfigurationException('azguard.'.$root.' must be a map of named keys.')] = $value;
            }
            $validate($map);
        }

        $appKey = $config->get('app.key');

        return self::build($raw, 'azguard', new ConfigurationValidator, failWithValidator: false, appKey: is_string($appKey) ? $appKey : null, repository: $config);
    }

    #[Internal('Builds an empty default options object when no repository is supplied.')]
    public static function defaultsOnly(): self
    {
        return self::build([], 'azguard', new ConfigurationValidator, failWithValidator: false);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidConfigurationException
     */
    private static function build(
        array $raw,
        string $path,
        ConfigurationValidator $validator,
        bool $failWithValidator,
        ?string $appKey = null,
        ?Repository $repository = null,
    ): self {
        $panels = self::checkSection('panels', self::rawKey($raw, 'panels'), self::PANELS_KEYS, $path, $validator, $failWithValidator);
        $catalog = self::checkSection('catalog', self::rawKey($raw, 'catalog'), self::CATALOG_KEYS, $path, $validator, $failWithValidator);
        $ids = self::checkSection('ids', self::rawKey($raw, 'ids'), self::IDS_KEYS, $path, $validator, $failWithValidator);
        $hostKeys = self::hostKeysFrom(self::rawKey($ids, 'host_keys', 'string'));
        $defaults = self::section('defaults', self::rawKey($raw, 'defaults'));
        $gate = self::checkSection('gate', self::rawKey($raw, 'gate'), self::GATE_KEYS, $path, $validator, $failWithValidator);
        $schedule = self::checkSection('schedule', self::rawKey($raw, 'schedule'), self::SCHEDULE_KEYS, $path, $validator, $failWithValidator);
        $sets = self::checkSection('decision_sets', self::rawKey($raw, 'decision_sets'), self::DECISION_SETS_KEYS, $path, $validator, $failWithValidator);
        $cache = self::checkSection('cache', self::rawKey($raw, 'cache'), self::CACHE_KEYS, $path, $validator, $failWithValidator);

        $memoLimit = self::memoLimit('max_memo_entries', self::rawKey($cache, 'max_memo_entries', 1024));
        $stateLimit = self::memoLimit('max_state_entries', self::rawKey($cache, 'max_state_entries', 1024));

        $discoveryRaw = self::checkSection('discovery', self::rawKey($raw, 'discovery'), self::DISCOVERY_KEYS, $path, $validator, $failWithValidator);

        $signingSecret = $cache['signing_key'] ?? null;
        $effectiveSecret = is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : ($appKey ?? '');
        $signingAvailable = $repository !== null
            ? CacheSigningConfig::fromConfig($repository) !== null
            : CacheSigningConfig::fromParts($cache['namespace'] ?? null, self::rawKey($cache, 'key_id', 'active'), $effectiveSecret, self::rawKey($cache, 'previous_keys')) !== null;
        $namespaceRaw = $cache['namespace'] ?? null;
        $namespace = is_string($namespaceRaw) && $namespaceRaw !== '' && strlen($namespaceRaw) <= 255 && preg_match('//u', $namespaceRaw) === 1
            ? $namespaceRaw
            : null;

        return new self(
            panelProviders: self::panelProvidersFrom(self::rawKey($panels, 'providers')),
            defaults: self::defaultsFrom($defaults, $path, $validator, $failWithValidator),
            buildId: self::buildIdFrom($catalog['build_id'] ?? null),
            catalogCachePath: self::cachePathFrom($catalog['cache_path'] ?? null),
            sources: self::sourcesFrom(self::rawKey($raw, 'sources')),
            discovery: self::discoveryFrom($discoveryRaw),
            storages: self::storagesFrom(self::rawKey($raw, 'storages', ['default' => []]), $hostKeys, $path, $validator, $failWithValidator),
            hostKeys: $hostKeys,
            models: self::modelsFrom(self::rawKey($defaults, 'models'), $path, $validator, $failWithValidator),
            tenantResolvers: self::resolversFrom('defaults.tenants', self::rawKey($defaults, 'tenants'), $path, $validator, $failWithValidator),
            scopeResolvers: self::resolversFrom('defaults.scopes', self::rawKey($defaults, 'scopes'), $path, $validator, $failWithValidator),
            gateEnabled: self::flag('gate.enabled', self::rawKey($gate, 'enabled', true)),
            scheduleEnabled: self::flag('schedule.enabled', self::rawKey($schedule, 'enabled', true)),
            pruneExpiredFrequency: self::frequencyFrom(self::rawKey($schedule, 'prune_expired', 'daily')),
            maxSetSubjects: self::maxSetSubjectsFrom(self::rawKey($sets, 'max_subjects', self::DEFAULT_MAX_SET_SUBJECTS)),
            cacheSigningAvailable: $signingAvailable,
            cacheSigningKey: new SecretRef($effectiveSecret),
            cacheNamespace: $namespace,
            maxMemoEntries: $memoLimit,
            maxStateEntries: $stateLimit,
        );
    }

    /**
     * @param  list<string>  $allowed
     * @return array<mixed>
     *
     * @throws InvalidConfigurationException
     */
    private static function checkSection(
        string $name,
        mixed $value,
        array $allowed,
        string $rootPath,
        ConfigurationValidator $validator,
        bool $failWithValidator,
    ): array {
        $section = self::section($name, $value);

        if ($failWithValidator) {
            $validator->rejectUnknownKeys($section, $allowed, "{$rootPath}.{$name}");
            $validator->failIfAny();
        } else {
            self::assertKnownKeys($name, $section, $allowed);
        }

        return $section;
    }

    public function cacheSigningAvailable(): bool
    {
        return $this->cacheSigningAvailable;
    }

    public function cacheSigningKey(): SecretRef
    {
        return $this->cacheSigningKey;
    }

    public function cacheNamespace(): ?string
    {
        return $this->cacheNamespace;
    }

    public function maxMemoEntries(): int
    {
        return $this->maxMemoEntries;
    }

    public function maxStateEntries(): int
    {
        return $this->maxStateEntries;
    }

    /** @return array<string, class-string> */
    public function defaultModels(): array
    {
        return $this->models;
    }

    /** @return list<class-string> */
    public function defaultTenantResolvers(): array
    {
        return $this->tenantResolvers;
    }

    /** @return list<class-string> */
    public function defaultScopeResolvers(): array
    {
        return $this->scopeResolvers;
    }

    public function gateEnabled(): bool
    {
        return $this->gateEnabled;
    }

    public function maxSetSubjects(): int
    {
        return $this->maxSetSubjects;
    }

    public function scheduleEnabled(): bool
    {
        return $this->scheduleEnabled;
    }

    public function pruneExpiredFrequency(): ?string
    {
        return $this->pruneExpiredFrequency;
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

    /** @return list<string> */
    public function panelProviders(): array
    {
        return $this->panelProviders;
    }

    /** @return array<string, bool|int|string|null> */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /** @param list<class-string> $panelProviders */
    public function buildId(array $panelProviders): string
    {
        if ($this->buildId !== null) {
            return $this->buildId;
        }

        $files = [];

        foreach ($panelProviders as $provider) {
            $file = new ReflectionClass($provider)->getFileName();
            $files[$provider] = $file === false ? '' : (string) hash_file('sha256', $file);
        }

        ksort($files, SORT_STRING);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function configuredBuildId(): ?string
    {
        return $this->buildId;
    }

    public function catalogCachePath(): ?string
    {
        return $this->catalogCachePath;
    }

    /** @return array<string, mixed> */
    public function source(string $name): array
    {
        return $this->sources[$name] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string} */
    public function discovery(): array
    {
        return $this->discovery;
    }

    /** @return list<string> */
    public static function knownKeys(): array
    {
        $keys = [
            'sources',
            'panels.providers',
            'defaults.resource_prefix',
            'defaults.trace_decisions',
            'defaults.tenants.resolvers',
            'defaults.scopes.resolvers',
            'scaffold.namespace',
            'scaffold.path',
        ];
        $groups = [
            'cache' => self::CACHE_KEYS,
            'ids' => self::IDS_KEYS,
            'catalog' => self::CATALOG_KEYS,
            'discovery' => self::DISCOVERY_KEYS,
            'gate' => self::GATE_KEYS,
            'schedule' => self::SCHEDULE_KEYS,
            'storages.*' => self::STORAGE_KEYS,
            'decision_sets' => self::DECISION_SETS_KEYS,
            'defaults.models' => self::MODEL_KEYS,
            'defaults.cache' => self::DEFAULTS_KEYS['defaults.cache'],
            'defaults.consistency' => self::DEFAULTS_KEYS['defaults.consistency'],
        ];

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $keys[] = $group.'.'.$name;
            }
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    #[Internal('Validates cache LRU limits cleanly.')]
    public static function memoLimit(string $key, mixed $value): int
    {
        $limit = self::integer('cache.'.$key, $value, false);

        return $limit !== null && $limit >= 1 && $limit <= 1024
            ? $limit
            : throw self::invalidValue('cache.'.$key, $value, 'an integer between 1 and 1024');
    }

    /** @return list<class-string> */
    private static function resolversFrom(string $name, mixed $group, string $rootPath, ConfigurationValidator $validator, bool $failWithValidator): array
    {
        $group = self::checkSection($name, $group, self::RESOLVER_GROUP_KEYS, $rootPath, $validator, $failWithValidator);
        $resolvers = self::rawKey($group, 'resolvers');

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

    private static function maxSetSubjectsFrom(mixed $value): int
    {
        $limit = self::integer('decision_sets.max_subjects', $value, false);

        return $limit !== null && $limit >= 1
            ? $limit
            : throw self::invalidValue('decision_sets.max_subjects', $value, 'a positive integer');
    }

    private static function flag(string $key, mixed $value): bool
    {
        return is_bool($value) ? $value : throw self::invalidValue($key, $value, 'true or false');
    }

    private static function frequencyFrom(mixed $frequency): ?string
    {
        if ($frequency === null) {
            return null;
        }

        if (is_string($frequency) && preg_match('/\A(?:\S+ ){4}\S+\z/', $frequency) === 1) {
            if (! class_exists(CronExpression::class)) {
                throw new InvalidConfigurationException('azguard.schedule.prune_expired needs dragonmantank/cron-expression to validate a cron expression. Install it or use a named scheduler frequency.');
            }

            if (CronExpression::isValidExpression($frequency)) {
                return $frequency;
            }
        }

        if (
            is_string($frequency)
            && method_exists(ManagesFrequencies::class, $frequency)
            && new ReflectionMethod(ManagesFrequencies::class, $frequency)->isPublic()
            && new ReflectionMethod(ManagesFrequencies::class, $frequency)->getNumberOfRequiredParameters() === 0
            && ! new ReflectionMethod(ManagesFrequencies::class, $frequency)->isVariadic()
        ) {
            return $frequency;
        }

        throw self::invalidValue('schedule.prune_expired', $frequency, 'a frequency method of the Laravel scheduler without arguments, a cron expression or null');
    }

    /** @return array<string, class-string> */
    private static function modelsFrom(mixed $models, string $rootPath, ConfigurationValidator $validator, bool $failWithValidator): array
    {
        $models = self::checkSection('defaults.models', $models, self::MODEL_KEYS, $rootPath, $validator, $failWithValidator);
        $defaults = [
            'role_grant' => 'AzGuard\\Storage\\Models\\RoleGrant',
            'permission_grant' => 'AzGuard\\Storage\\Models\\PermissionGrant',
            'permission' => 'AzGuard\\Storage\\Models\\Permission',
        ];
        $parsed = [];
        foreach ([...$defaults, ...$models] as $kind => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw self::invalidValue('defaults.models.'.$kind, $class, 'an existing model class');
            }
            $parsed[(string) $kind] = $class;
        }

        return $parsed;
    }

    private static function hostKeysFrom(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['string', 'bigint', 'uuid', 'ulid'], true)) {
            throw InvalidConfigurationException::failing('host_keys', 'Host keys must be string, bigint, uuid or ulid.');
        }

        return $value;
    }

    /** @return array<string, array{connection: ?string, table_prefix: string, host_keys: string}> */
    private static function storagesFrom(mixed $storages, string $hostKeys, string $rootPath, ConfigurationValidator $validator, bool $failWithValidator): array
    {
        $parsed = [];
        foreach (self::section('storages', $storages) as $name => $parameters) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) !== 1) {
                throw InvalidConfigurationException::failing('storage', 'Invalid storage name.');
            }
            $parameters = self::checkSection('storages.'.$name, $parameters, self::STORAGE_KEYS, $rootPath, $validator, $failWithValidator);
            $connection = $parameters['connection'] ?? null;
            $prefix = self::rawKey($parameters, 'table_prefix', 'azg_');

            if (
                ($connection !== null && (! is_string($connection) || $connection === ''))
                || ! is_string($prefix)
                || preg_match('/\A([a-z][a-z0-9_]{0,19})?\z/', $prefix) !== 1
            ) {
                throw InvalidConfigurationException::failing('storage', 'Invalid connection or table prefix for storage '.$name.'.');
            }
            $parsed[$name] = [
                'connection' => $connection,
                'table_prefix' => $prefix,
                'host_keys' => self::hostKeysFrom($parameters['host_keys'] ?? $hostKeys),
            ];
        }

        return $parsed;
    }

    /** @return array<string, array<string, mixed>> */
    private static function sourcesFrom(mixed $sources): array
    {
        if (! is_array($sources)) {
            throw new InvalidConfigurationException('azguard.sources must be an array of source parameters, got '.get_debug_type($sources).'.');
        }

        $parsed = [];
        foreach ($sources as $name => $parameters) {
            if (! is_string($name) || $name === '') {
                throw new InvalidConfigurationException('azguard.sources must be keyed by source name, got '.get_debug_type($name).'.');
            }

            if (! is_array($parameters)) {
                throw new InvalidConfigurationException('azguard.sources.'.$name.' must be an array of parameters, got '.get_debug_type($parameters).'.');
            }
            $parsed[$name] = [];
            foreach ($parameters as $parameter => $value) {
                if (! is_string($parameter)) {
                    throw new InvalidConfigurationException('azguard.sources.'.$name.' must be keyed by parameter name, got '.get_debug_type($parameter).'.');
                }
                $parsed[$name][$parameter] = $value;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<mixed>  $discovery
     * @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}
     */
    private static function discoveryFrom(array $discovery): array
    {
        $names = self::DISCOVERY_DEFAULTS;

        foreach (self::DISCOVERY_KEYS as $key) {
            if (! array_key_exists($key, $discovery)) {
                continue;
            }
            $value = $discovery[$key];

            if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
                throw new InvalidConfigurationException('azguard.discovery.'.$key.' must be a folder name, got '.get_debug_type($value).'.');
            }
            $names[$key] = self::discoveryFolder($key, $value);
        }

        return $names;
    }

    private static function discoveryFolder(string $key, string $value): string
    {
        $folder = trim(str_replace('\\', '/', $value), '/');

        foreach (explode('/', $folder) as $segment) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1) {
                throw new InvalidConfigurationException('azguard.discovery.'.$key.' must be a folder of identifier segments, got "'.$value.'".');
            }
        }

        return $folder;
    }

    private static function cachePathFrom(mixed $path): ?string
    {
        return match (true) {
            $path === null, $path === '' => null,
            is_string($path) => $path,
            default => throw self::invalidValue('catalog.cache_path', $path, 'a file path or null'),
        };
    }

    private static function buildIdFrom(mixed $buildId): ?string
    {
        return match (true) {
            $buildId === null, $buildId === '' => null,
            is_string($buildId) => $buildId,
            default => throw self::invalidValue('catalog.build_id', $buildId, 'a string or null'),
        };
    }

    /** @return list<string> */
    private static function panelProvidersFrom(mixed $providers): array
    {
        if (! is_array($providers)) {
            throw new InvalidConfigurationException('azguard.panels.providers must be a list of panel provider classes, got '.get_debug_type($providers).'.');
        }

        $classes = [];
        foreach ($providers as $provider) {
            if (! is_string($provider) || $provider === '') {
                throw new InvalidConfigurationException('azguard.panels.providers must list panel provider classes, got '.get_debug_type($provider).'.');
            }
            $classes[] = $provider;
        }

        return $classes;
    }

    /**
     * @param  array<mixed>  $defaults
     * @return array<string, bool|int|string|null>
     */
    private static function defaultsFrom(array $defaults, string $rootPath, ConfigurationValidator $validator, bool $failWithValidator): array
    {
        if ($failWithValidator) {
            $validator->rejectUnknownKeys($defaults, self::DEFAULTS_KEYS['defaults'], "{$rootPath}.defaults");
            $validator->failIfAny();
        } else {
            self::assertKnownKeys('defaults', $defaults, self::DEFAULTS_KEYS['defaults']);
        }

        $values = [];
        foreach (['resource_prefix', 'trace_decisions'] as $flag) {
            if (array_key_exists($flag, $defaults)) {
                $values[$flag] = is_bool($defaults[$flag])
                    ? $defaults[$flag]
                    : throw self::invalidValue('defaults.'.$flag, $defaults[$flag], 'true or false');
            }
        }

        foreach (['cache', 'consistency'] as $group) {
            $section = self::checkSection('defaults.'.$group, self::rawKey($defaults, $group), self::DEFAULTS_KEYS['defaults.'.$group], $rootPath, $validator, $failWithValidator);

            foreach ($section as $key => $value) {
                $name = $group.'.'.$key;
                $values[$name] = match ($name) {
                    'cache.ttl', 'cache.generation' => self::integer('defaults.'.$name, $value, $name === 'cache.ttl'),
                    'cache.store' => $value === null || (is_string($value) && $value !== '')
                        ? $value
                        : throw self::invalidValue('defaults.'.$name, $value, 'the name of a cache store or null'),
                    default => is_string($value)
                        ? $value
                        : throw InvalidConfigurationException::failing('enum', 'azguard.defaults.'.$name.' must be a string, got '.get_debug_type($value).'.'),
                };
            }
        }

        return $values;
    }

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

    /** @param  array<array-key, mixed>  $source */
    private static function rawKey(array $source, string $key, mixed $default = []): mixed
    {
        return array_key_exists($key, $source) ? $source[$key] : $default;
    }

    /** @return array<mixed> */
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

    private static function validateOptionalSection(
        string $root,
        mixed $section,
        ?string $path = null,
        ?ConfigurationValidator $validator = null,
    ): void {
        $prefix = $path ?? 'azguard';

        if (! is_array($section)) {
            if ($validator !== null) {
                $validator->add("{$prefix}.{$root}", 'must be an array');
                $validator->failIfAny();
            }

            throw new InvalidConfigurationException("azguard.{$root} must be an array, got ".get_debug_type($section).'.');
        }

        if ($section !== [] && array_is_list($section)) {
            throw new InvalidConfigurationException("azguard.{$root} must be a map of named keys.");
        }

        if ($root !== 'scaffold') {
            return;
        }

        $known = ['namespace', 'path'];

        if ($validator !== null) {
            $validator->rejectUnknownKeys($section, $known, "{$prefix}.scaffold");
            $validator->failIfAny();
        } else {
            self::assertKnownKeys('scaffold', $section, $known);
        }

        if (array_key_exists('namespace', $section)) {
            $ns = is_string($section['namespace']) ? trim($section['namespace'], '\\') : null;

            if ($ns === null || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $ns) !== 1) {
                throw new InvalidConfigurationException('azguard.scaffold.namespace must be a PHP namespace, got '.get_debug_type($section['namespace']).'.');
            }
        }

        if (array_key_exists('path', $section)) {
            $rel = is_string($section['path']) ? rtrim(str_replace('\\', '/', $section['path']), '/') : null;

            if ($rel === null || $rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/')
                || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $rel) === 1 || in_array('..', explode('/', $rel), true)) {
                throw new InvalidConfigurationException('azguard.scaffold.path must be a directory relative to the application, got '.get_debug_type($section['path']).'.');
            }
        }
    }
}
