<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePanelException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Plugins\PluginContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Collects panel providers and adjustments while the application boots, then compiles every panel once, freezes
 * and boots the plugins of the panels.
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

    /** @var array<string, string> prefix => panel id */
    private array $prefixes = [];

    /** @var array<class-string, list<string>> permission enum => ids of the panels it is attached to */
    private array $enums = [];

    private bool $frozen = false;

    public function __construct(
        private readonly Application $app,
        private readonly PanelCompiler $compiler = new PanelCompiler,
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
     * A failed compilation leaves the registry unfrozen and without panels. Plugins boot once the registry is
     * frozen, in the order they were attached.
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
        $panels = $recipes = $plugins = $fingerprints = $enums = [];

        foreach ($providers as $id => $providerClass) {
            [$recipes[$id], $plugins[$id]] = $this->write($id, $providerClass, $buildId);
            $panels[$id] = $this->compiler->compile($recipes[$id]);
            $fingerprints[$id] = PanelFingerprint::of($panels[$id], $recipes[$id]);
            $enums = $this->attachEnums($enums, $id, $recipes[$id]->enums());
        }

        $this->compiler->assertDefaults($panels);
        $prefixes = $this->compiler->prefixes($panels);

        $this->panels = $panels;
        $this->recipes = $recipes;
        $this->fingerprints = $fingerprints;
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
     * Writes the recipe: the provider and `configure(id)` callbacks as the provider, then `configureAll` callbacks,
     * then the plugins the panel got. Every callback runs once per panel.
     *
     * @param  class-string<PanelProvider>  $providerClass
     * @return array{0: PanelRecipe, 1: list<array{plugin: Plugin, context: PluginContext}>}
     */
    private function write(string $id, string $providerClass, string $buildId): array
    {
        $recipe = new PanelRecipe($id);
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
     * Adds permission enums of a panel to the enum index; every way a panel gets its enums goes through here.
     *
     * @param  array<class-string, list<string>>  $index
     * @param  list<class-string>  $enums
     * @return array<class-string, list<string>>
     */
    private function attachEnums(array $index, string $id, array $enums): array
    {
        foreach ($enums as $enum) {
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

    private function unknown(string $id): UnknownPanelException
    {
        $known = array_keys($this->providers);

        return new UnknownPanelException(
            'No panel is registered under the id "'.$id.'". Registered panels: '.($known === [] ? 'none' : implode(', ', $known)).'.',
        );
    }
}
