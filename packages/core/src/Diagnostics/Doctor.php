<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Sources\ChecksHealth;
use AzGuard\Diagnostics\Checks\CacheStore;
use AzGuard\Diagnostics\Checks\CatalogBuildId;
use AzGuard\Diagnostics\Checks\CatalogCached;
use AzGuard\Diagnostics\Checks\CatalogCollisions;
use AzGuard\Diagnostics\Checks\ConfigValid;
use AzGuard\Diagnostics\Checks\ConsistencyReads;
use AzGuard\Diagnostics\Checks\DirectWrites;
use AzGuard\Diagnostics\Checks\GateModeCheck;
use AzGuard\Diagnostics\Checks\MembershipConfigured;
use AzGuard\Diagnostics\Checks\PanelsPlugins;
use AzGuard\Diagnostics\Checks\PanelsPolicies;
use AzGuard\Diagnostics\Checks\PanelsRelations;
use AzGuard\Diagnostics\Checks\PanelsSources;
use AzGuard\Diagnostics\Checks\PanelsValid;
use AzGuard\Diagnostics\Checks\PoliciesComplete;
use AzGuard\Diagnostics\Checks\RolesKeys;
use AzGuard\Diagnostics\Checks\RoutesChecks;
use AzGuard\Diagnostics\Checks\StorageMigrated;
use AzGuard\Diagnostics\Checks\StorageSchema;
use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the checks of the core, then the checks of the sources, panels and plugins of every selected panel, and
 * returns what they found in a stable order: by scope, key and message.
 *
 * Every check runs: an exception inside one is reported as an error `<key>.failed` that names the class of the
 * exception and nothing of its message, which may carry a driver message or connection details. Values of the
 * database connections and source parameters that look like secrets are removed from every finding before it
 * leaves the doctor. The doctor only reads.
 *
 * @api
 */
final readonly class Doctor
{
    /** @var list<class-string<DoctorCheck>> checks of the core in the order of the catalog of checks */
    private const array CORE = [
        ConfigValid::class, StorageSchema::class, StorageMigrated::class, PanelsValid::class, PanelsPlugins::class,
        PanelsSources::class, PanelsPolicies::class, PoliciesComplete::class, RolesKeys::class, RoutesChecks::class, PanelsRelations::class,
        CatalogCollisions::class, CatalogCached::class, MembershipConfigured::class, ConsistencyReads::class,
        CacheStore::class, GateModeCheck::class, DirectWrites::class, CatalogBuildId::class,
    ];

    private const string SECRET_NAME = '/pass(word)?|secret|token|dsn|url|credential|api[_-]?key|private/i';

    public function __construct(private Container $container) {}

    /**
     * What a run inspects. No panel and no storage selects all of them; selecting panels selects the storages they
     * use, selecting storages selects the panels that use them, unless both are named.
     *
     * @param  list<string>  $panels  ids of the panels
     * @param  list<string>  $storages  ids of the storages
     *
     * @throws UnknownPanelException when a panel is not registered
     * @throws InvalidConfigurationException when a storage is not registered
     */
    public function context(array $panels = [], array $storages = [], bool $production = false): DoctorContext
    {
        $registry = $this->container->make(PanelRegistry::class);
        $selected = $panels === [] ? array_values($registry->all()) : array_map($registry->get(...), array_values(array_unique($panels)));
        $known = $this->storages(array_values($registry->all()));

        foreach ($storages as $id) {
            if (! isset($known[$id])) {
                throw InvalidConfigurationException::failing('storage', 'Unknown storage '.$id.'.');
            }
        }

        if ($storages !== []) {
            $chosen = array_values(array_intersect_key($known, array_flip($storages)));

            if ($panels === []) {
                $ids = array_map(static fn (Storage $storage): string => $storage->id(), $chosen);
                $selected = array_values(array_filter($selected, fn (Panel $panel): bool => array_intersect(array_keys($this->storages([$panel], false)), $ids) !== []));
            }
        } else {
            $chosen = array_values($panels === [] ? $known : $this->storages($selected, false));
        }

        return new DoctorContext($selected, $chosen, $production, $this->config());
    }

    /**
     * @return list<DoctorFinding> what the checks found, by scope, key and message
     */
    public function run(DoctorContext $context): array
    {
        $findings = [];

        foreach (self::CORE as $check) {
            $findings = [...$findings, ...$this->collect($check, $context, null)];
        }

        foreach ($context->panels() as $panel) {
            $local = $context->forPanel($panel);

            foreach ($panel->attachedSources() as $source) {
                if (! $source instanceof ChecksHealth) {
                    continue;
                }

                try {
                    $checks = $source->doctorChecks();
                } catch (Throwable $error) {
                    $findings[] = self::failed('panels.sources', $error, 'panel:'.$panel->id());

                    continue;
                }

                foreach ($checks as $check) {
                    $findings = [...$findings, ...$this->collect($check, $local, 'source')];
                }
            }

            foreach ($panel->doctorChecks() as ['check' => $check, 'origin' => $origin]) {
                $findings = [...$findings, ...$this->collect($check, $local, $origin)];
            }
        }
        $secrets = $this->secrets();
        $findings = array_map(static fn (DoctorFinding $finding): DoctorFinding => $finding->redacted($secrets), $findings);
        usort($findings, static fn (DoctorFinding $a, DoctorFinding $b): int => [$a->scope, $a->key, $a->message] <=> [$b->scope, $b->key, $b->message]);

        return $findings;
    }

    /**
     * Findings of one check; a check of a panel reports in the scope of its panel, a check of a plugin in the scope
     * of its plugin with the panel as a detail.
     *
     * @return list<DoctorFinding>
     */
    private function collect(mixed $check, DoctorContext $context, ?string $origin): array
    {
        $panel = $context->panel();
        $scope = $panel === null ? 'core' : 'panel:'.$panel->id();
        $key = 'doctor.check';
        $findings = [];

        try {
            $check = is_string($check) ? $this->container->make($check) : $check;

            if (! $check instanceof DoctorCheck) {
                throw new InvalidConfigurationException('A doctor check is '.get_debug_type($check).', not a '.DoctorCheck::class.'.');
            }
            $declared = $check->key();

            if (preg_match(DoctorFinding::KEY, $declared) !== 1) {
                throw new InvalidConfigurationException('A doctor check of '.$check::class.' has a malformed key.');
            }
            $key = $declared;

            // A value that is not a finding fails attribute() with a TypeError, which reports the check as failed.
            foreach ($check->run($context) as $finding) {
                $findings[] = self::attribute($finding, $panel, $origin);
            }
        } catch (Throwable $error) {
            $findings[] = self::attribute(self::failed($key, $error, $scope), $panel, $origin);
        }

        return $findings;
    }

    private static function attribute(DoctorFinding $finding, ?Panel $panel, ?string $origin): DoctorFinding
    {
        if ($panel === null || $origin === null) {
            return $finding;
        }

        if (str_starts_with($origin, 'plugin:')) {
            return $finding->in($origin, ['panel' => $panel->id()]);
        }

        return $finding->scope === 'core' ? $finding->in('panel:'.$panel->id()) : $finding;
    }

    private static function failed(string $key, Throwable $error, string $scope): DoctorFinding
    {
        return DoctorFinding::error($key.'.failed', 'The check '.$key.' failed with '.$error::class.'; the other checks ran.', $scope,
            ['exception' => $error::class]);
    }

    /**
     * Storages of the configuration and of the database sources of the panels, by id.
     *
     * @param  list<Panel>  $panels
     * @return array<string, Storage>
     */
    private function storages(array $panels, bool $configured = true): array
    {
        $found = [];

        if ($configured) {
            try {
                $registry = $this->container->make(StorageRegistry::class);

                foreach (array_keys($this->container->make(AzGuardConfig::class)->storages()) as $id) {
                    $found[$id] = $registry->get($id);
                }
            } catch (Throwable) {
                // A storage that cannot be built is reported by config.valid; the run goes on without the storages.
            }
        }

        foreach ($panels as $panel) {
            foreach ($panel->attachedSources() as $source) {
                if ($source instanceof DatabaseSource) {
                    try {
                        $storage = $source->boundStorage();
                    } catch (Throwable) {
                        continue;
                    }
                    $found[$storage->id()] = $storage;
                }
            }
        }

        return $found;
    }

    /**
     * The configuration as it is now; the booted one when the current one does not load (`config.valid` reports it).
     */
    private function config(): AzGuardConfig
    {
        try {
            return AzGuardConfig::fromRepository($this->container->make(Repository::class));
        } catch (ConfigurationException) {
            return $this->container->make(AzGuardConfig::class);
        }
    }

    /**
     * Values of the database connections and of the source parameters whose names look like secrets.
     *
     * @return list<string> longest first, so a secret that contains another one is removed whole
     */
    private function secrets(): array
    {
        $repository = $this->container->make(Repository::class);
        $connections = $repository->get('database.connections', []);
        $secrets = self::secretValues(is_array($connections) ? $connections : []);

        try {
            $secrets = [...$secrets, ...self::secretValues(AzGuardConfig::fromRepository($repository)->sources())];
        } catch (ConfigurationException) {
            $secrets = [...$secrets, ...self::secretValues($this->container->make(AzGuardConfig::class)->sources())];
        }
        $secrets = array_values(array_unique(array_filter($secrets, static fn (string $secret): bool => $secret !== '')));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function secretValues(array $values, bool $secret = false): array
    {
        $found = [];

        foreach ($values as $name => $value) {
            $named = $secret || (is_string($name) && preg_match(self::SECRET_NAME, $name) === 1);

            if (is_array($value)) {
                $found = [...$found, ...self::secretValues($value, $named)];
            } elseif ($named && is_string($value) && $value !== '') {
                $found[] = $value;
                $password = parse_url($value, PHP_URL_PASS);

                if (is_string($password) && $password !== '') {
                    $found[] = $password;
                    $found[] = rawurldecode($password);
                }
            }
        }

        return $found;
    }
}
