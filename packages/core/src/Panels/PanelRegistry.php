<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Catalog\CatalogCache;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePanelException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Plugins\PluginContext;
use AzGuard\Sources\Folder\PanelDiscovery;
use AzGuard\Sources\PanelSources;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Sources\SourceManager;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Collects panel providers and adjustments while the application boots, then compiles every panel and its catalog
 * once, freezes and boots the plugins of the panels.
 *
 * @phpstan-import-type PanelEntry from CatalogCache
 */
final class PanelRegistry implements PanelRegistryContract
{
    /** @var array<string, class-string<PanelProvider>> */
    private array $providers = [];

    /** @var array<string, class-string<PanelProvider>> */
    private array $replacements = [];

    /** @var array<string, list<Closure(PanelBuilder): mixed>> */
    private array $configure = [];

    /** @var list<Closure(PanelBuilder): mixed> */
    private array $configureAll = [];

    /** @var array<string, Panel>|null */
    private ?array $panels = null;

    /** @var array<string, PanelRecipe> */
    private array $recipes = [];

    /** @var array<string, string> */
    private array $fingerprints = [];

    /** @var array<string, string> fingerprints of the recipes, without the catalogs */
    private array $recipeFingerprints = [];

    /** @var array<string, PanelCatalog> */
    private array $catalogs = [];

    private ?string $buildId = null;

    /** @var array<string, string> prefix => panel id */
    private array $prefixes = [];

    /** @var array<class-string, list<string>> permission enum => ids of the panels it is attached to */
    private array $enums = [];

    /** @var array<string, array<mixed>> discovery of each panel, as the catalog cache stores it */
    private array $discoveries = [];

    private bool $frozen = false;

    /**
     * @param  (Closure(): CatalogCache)|null  $cache  the catalog cache, resolved when the panels are compiled
     */
    public function __construct(
        private readonly Application $app,
        private readonly PanelCompiler $compiler = new PanelCompiler,
        private readonly ?Closure $cache = null,
    ) {}

    public function get(string $id): Panel
    {
        return $this->compiled()[$id] ?? throw $this->unknown($id);
    }

    public function find(string $id): ?Panel
    {
        return $this->compiled()[$id] ?? null;
    }

    public function all(): array
    {
        return $this->compiled();
    }

    public function forModel(string $modelClass): array
    {
        return array_values(array_filter(
            $this->compiled(),
            static fn (Panel $panel): bool => array_filter(
                $panel->subjectModels(),
                static fn (string $subject): bool => is_a($modelClass, $subject, true),
            ) !== [],
        ));
    }

    public function defaultFor(string $modelClass): ?Panel
    {
        $panels = $this->forModel($modelClass);

        foreach ($panels as $panel) {
            if ($panel->isDefault()) {
                return $panel;
            }
        }

        return count($panels) === 1 ? $panels[0] : null;
    }

    public function register(string $providerClass): void
    {
        $this->assertOpen('register a panel');
        $id = $this->idOf($providerClass);

        if (isset($this->providers[$id])) {
            throw new DuplicatePanelException(
                'Panel "'.$id.'" is already registered by '.$this->providers[$id].'; '.$providerClass
                .' cannot register it again. Use replace() to swap the provider.',
            );
        }

        $this->providers[$id] = $providerClass;
    }

    public function replace(string $providerClass): void
    {
        $this->assertOpen('replace a panel');

        $this->replacements[$this->idOf($providerClass)] = $providerClass;
    }

    public function configure(string $id, Closure $callback): void
    {
        $this->assertOpen('configure a panel');
        $this->configure[$id][] = $callback;
    }

    public function configureAll(Closure $callback): void
    {
        $this->assertOpen('configure panels');
        $this->configureAll[] = $callback;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Compiles every registered panel and freezes the registry; runs once, when the application has booted.
     *
     * A panel takes its catalog from the catalog cache when the cache was written by this build for the same recipe,
     * otherwise from its sources. A failed compilation leaves the registry unfrozen and without panels. Plugins boot
     * once the registry is frozen, in the order they were attached.
     *
     * @throws DefinitionException
     */
    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        foreach ([...array_keys($this->replacements), ...array_keys($this->configure)] as $id) {
            if (! isset($this->providers[$id])) {
                throw $this->unknown($id);
            }
        }

        $providers = [];

        foreach ($this->providers as $id => $providerClass) {
            $providers[$id] = $this->replacements[$id] ?? $providerClass;
        }

        $buildId = $this->app->make(AzGuardConfig::class)->buildId(array_values($providers));
        $cached = $this->cache === null ? [] : ($this->cache)()->read();
        $panels = $recipes = $plugins = $fingerprints = $recipeFingerprints = $catalogs = $enums = [];

        foreach ($providers as $id => $providerClass) {
            [$recipes[$id], $plugins[$id]] = $this->write($id, $providerClass, $buildId);
            $stored = $this->storedDiscovery($cached, $buildId, $id);
            $discovery = PanelDiscovery::resolve($recipes[$id], $this->app, $stored);
            $this->registerDiscoveredSources($discovery->sources);
            $resolved = PanelSources::of($recipes[$id], $this->app, $discovery);
            $panels[$id] = $this->compiler->compile($recipes[$id], $resolved, $this->app);
            $recipeFingerprints[$id] = PanelFingerprint::of($panels[$id], $recipes[$id], $resolved->identity(), $discovery->fingerprint());
            $snapshot = CatalogCache::entry($cached, $buildId, $id, $recipeFingerprints[$id]);
            $catalogs[$id] = ($snapshot === null ? null : PanelCatalog::fromSnapshot($snapshot))
                ?? PanelCatalog::build($panels[$id], $resolved, $this->app);
            foreach ($resolved->all() as ['source' => $source]) {
                if ($source instanceof RelationSource) {
                    $source->validateCatalog($catalogs[$id]);
                }
            }
            $fingerprints[$id] = PanelFingerprint::withCatalog($recipeFingerprints[$id], $catalogs[$id]);
            $this->discoveries[$id] = $discovery->cache();
            $enums = $this->attachEnums($enums, $id, $catalogs[$id]);
        }

        foreach ($catalogs as $catalog) {
            foreach ($catalog->policyBindings() as $binding) {
                if ($binding->kind !== 'gate') {
                    continue;
                }
                foreach ($catalogs as $owner) {
                    $owner->assertExternalAbility($binding);
                }
            }
        }

        $this->compiler->assertDefaults($panels);
        $prefixes = $this->compiler->prefixes($panels, $catalogs);

        $this->panels = $panels;
        $this->recipes = $recipes;
        $this->fingerprints = $fingerprints;
        $this->recipeFingerprints = $recipeFingerprints;
        $this->catalogs = $catalogs;
        $this->buildId = $buildId;
        $this->prefixes = $prefixes;
        $this->enums = $enums;
        $this->frozen = true;

        foreach ($panels as $id => $panel) {
            $this->compiler->boot($panel, $plugins[$id]);
        }
    }

    /**
     * The panel whose permission names start with the segment.
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function forPrefix(string $segment): ?Panel
    {
        $panels = $this->compiled();

        return isset($this->prefixes[$segment]) ? $panels[$this->prefixes[$segment]] : null;
    }

    /**
     * @return list<Panel> panels the permission enum is attached to, in registration order
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function forEnum(string $enumClass): array
    {
        $panels = $this->compiled();

        return array_map(static fn (string $id): Panel => $panels[$id], $this->enums[$enumClass] ?? []);
    }

    /**
     * The sealed recipe a panel was compiled from.
     *
     * @throws UnknownPanelException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function recipe(string $id): PanelRecipe
    {
        $this->compiled();

        return $this->recipes[$id] ?? throw $this->unknown($id);
    }

    /**
     * @throws UnknownPanelException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function fingerprint(string $id): string
    {
        $this->compiled();

        return $this->fingerprints[$id] ?? throw $this->unknown($id);
    }

    /**
     * The static catalog of a panel.
     *
     * @throws UnknownPanelException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function catalog(string $id): PanelCatalog
    {
        $this->compiled();

        return $this->catalogs[$id] ?? throw $this->unknown($id);
    }

    /**
     * Id of the build the panels were compiled for.
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function buildId(): string
    {
        $this->compiled();

        return (string) $this->buildId;
    }

    /**
     * Catalogs of all panels built again from their sources, as the catalog cache stores them.
     *
     * @return array<string, PanelEntry>
     *
     * @throws DefinitionException when the panels are not compiled yet or a catalog no longer builds
     */
    public function snapshot(): array
    {
        $panels = $this->compiled();
        $catalogs = $entries = [];

        foreach ($panels as $id => $panel) {
            $catalogs[$id] = $this->compiler->catalog($panel, $this->recipes[$id], $this->app);
            $entries[$id] = [
                'fingerprint' => $this->recipeFingerprints[$id],
                'catalog' => $catalogs[$id]->snapshot(),
                'discovery' => $this->discoveries[$id] ?? [],
            ];
        }

        $this->compiler->prefixes($panels, $catalogs);

        return $entries;
    }

    /**
     * Writes the recipe: the provider and `configure(id)` callbacks as the provider, then `configureAll` callbacks,
     * then the plugins the panel got. Every callback runs once per panel.
     *
     * @param  class-string<PanelProvider>  $providerClass
     * @return array{0: PanelRecipe, 1: list<array{plugin: Plugin, context: PluginContext}>}
     */
    private function write(string $id, string $providerClass, string $buildId): array
    {
        $recipe = new PanelRecipe($id);
        $recipe->setProvider($providerClass);
        $builder = new PanelBuilder($recipe);

        $recipe->during(PanelRecipe::provider(), function () use ($id, $providerClass, $builder): void {
            $this->provider($providerClass)->panel($builder);

            foreach ($this->configure[$id] ?? [] as $callback) {
                $callback($builder);
            }
        });

        $recipe->during(PanelRecipe::configure(), function () use ($builder): void {
            foreach ($this->configureAll as $callback) {
                $callback($builder);
            }
        });

        return [$recipe, $this->compiler->register($recipe, $builder, $this->app, $buildId)];
    }

    /**
     * Indexes the cases of the final static catalog, whether it was built live or restored from the cache.
     *
     * @param  array<class-string, list<string>>  $index
     * @return array<class-string, list<string>>
     */
    private function attachEnums(array $index, string $id, PanelCatalog $catalog): array
    {
        foreach ($catalog->all() as $definition) {
            if ($definition->case === null) {
                continue;
            }

            $enum = $definition->case::class;

            if (! in_array($id, $index[$enum] ?? [], true)) {
                $index[$enum][] = $id;
            }
        }

        return $index;
    }

    /**
     * @param  class-string<PanelProvider>  $providerClass
     */
    private function provider(string $providerClass): PanelProvider
    {
        foreach ($this->app->getProviders($providerClass) as $provider) {
            if ($provider instanceof PanelProvider && $provider::class === $providerClass) {
                return $provider;
            }
        }

        return new $providerClass($this->app);
    }

    /**
     * @phpstan-assert class-string<PanelProvider> $providerClass
     *
     * @throws DefinitionException
     * @throws InvalidPanelIdException
     */
    private function idOf(string $providerClass): string
    {
        if (! is_subclass_of($providerClass, PanelProvider::class)) {
            throw new DefinitionException($providerClass.' is not a panel provider: it must extend '.PanelProvider::class.'.');
        }

        $id = $providerClass::getId();
        PermissionGrammar::assertPanelId($id);

        return $id;
    }

    /**
     * @return array<string, Panel>
     *
     * @throws DefinitionException
     */
    private function compiled(): array
    {
        return $this->panels ?? throw new DefinitionException(
            'Panels are not compiled yet: they can be read once the application has booted.',
        );
    }

    /**
     * @throws RegistryFrozenException
     */
    private function assertOpen(string $action): void
    {
        if ($this->frozen) {
            throw new RegistryFrozenException(
                'Cannot '.$action.': the panel registry is frozen once the application has booted.',
            );
        }
    }

    /**
     * @param  array<mixed>  $file
     * @return array<mixed>|null
     */
    private function storedDiscovery(array $file, string $buildId, string $panel): ?array
    {
        if (($file['version'] ?? null) !== CatalogCache::VERSION || ($file['build_id'] ?? null) !== $buildId) {
            return null;
        }

        $discovery = $file['panels'][$panel]['discovery'] ?? null;

        return is_array($discovery) ? $discovery : null;
    }

    /**
     * @param  list<class-string>  $classes
     */
    private function registerDiscoveredSources(array $classes): void
    {
        $manager = $this->app->make(SourceManager::class);

        foreach ($classes as $class) {
            if (is_subclass_of($class, Source::class)) {
                $manager->register($class);
            }
        }
    }

    private function unknown(string $id): UnknownPanelException
    {
        $known = array_keys($this->providers);

        return new UnknownPanelException(
            'No panel is registered under the id "'.$id.'". Registered panels: '.($known === [] ? 'none' : implode(', ', $known)).'.',
        );
    }
}
