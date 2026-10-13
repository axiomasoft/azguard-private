<?php

declare(strict_types=1);

namespace AzGuard\Sources\Internal;

use AzGuard\Catalog\Internal\PanelCatalog;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\DuplicateRoleException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Exceptions\WriterConflictException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Internal\PanelRecipe;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\Folder\Internal\DiscoverySnapshot;
use AzGuard\Sources\Folder\Internal\PanelDiscovery;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Sources\SourceManager;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Sources of a panel in the order of the recipe layers and of registration, each with the layer that attached it.
 *
 * Enum classes of `permissions([...])` are not sources: the folder source takes them. A string name is built by the
 * source factory at the place of its record. An object stays the object the recipe holds.
 *
 * @phpstan-type Attached array{source: Source, origin: string, plugin: ?string, name: ?string, role_origins?: array<class-string, string>}
 */
final readonly class PanelSources
{
    /** @var list<class-string<Source>> */
    public const array CAPABILITIES = [
        ProvidesPermissions::class,
        ProvidesRoles::class,
        ProvidesGrants::class,
        ProvidesRoleGrants::class,
        ProvidesPolicies::class,
        DescribesSchema::class,
        FencesReads::class,
        FiltersQueries::class,
    ];

    /**
     * @param  list<Attached>  $sources
     */
    private function __construct(
        private string $panel,
        private array $sources,
    ) {}

    /**
     * The sources of the recipe. With the compiled catalog the folder evaluates the automatic roles among all roles of
     * the panel, also the ones other sources declare; prepare the sources of a decision or a list this way.
     *
     * @throws UnknownSourceException when a source is named and no source is registered under the name
     * @throws InvalidIdentityException when the id of a source is malformed
     * @throws DuplicatePermissionException when two sources of the panel share an id
     * @throws DefinitionException when a creator fails
     * @throws WriterConflictException when more than one source stores grants
     */
    public static function of(PanelRecipe $recipe, Container $container, ?DiscoverySnapshot $discovery = null, ?PanelCatalog $catalog = null): self
    {
        $panel = $recipe->panelId();
        $manager = $container->make(SourceManager::class);
        $discovery ??= PanelDiscovery::resolve($recipe, $container, null);
        $configured = FolderSource::configured($recipe);
        $folder = clone ($configured['source'] ?? FolderSource::make());
        $folder->bind($discovery, $recipe, $container);

        if ($catalog !== null) {
            $folder->bindRoleClasses(array_column($catalog->roles(), 'class'));
        }
        /** @var list<Attached> $sources */
        $sources = [[
            'source' => $folder,
            'origin' => $configured['origin'] ?? PanelRecipe::PROVIDER,
            'plugin' => $configured['plugin'] ?? null,
            'name' => null,
            'role_origins' => self::roleOrigins($recipe, $discovery),
        ]];
        /** @var array<string, Source> $byId */
        $byId = [$folder->id() => $folder];

        foreach ($recipe->layered(PanelRecipe::PERMISSIONS) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $definition) {
                if ((is_string($definition) && is_subclass_of($definition, BackedEnum::class)) || $definition instanceof FolderSource) {
                    continue;
                }

                $name = null;
                $source = $definition;

                if (is_string($definition)) {
                    $name = $definition;

                    try {
                        $source = $manager->make($name, $panel);
                    } catch (UnknownSourceException) {
                        throw new UnknownSourceException(
                            'Panel "'.$panel.'" names the source '.json_encode($name).', which is not registered: attach a source object '
                            .'or register the name.',
                        );
                    }
                }

                if (! $source instanceof Source) {
                    throw new UnknownSourceException(
                        'Panel "'.$panel.'" names the source '.json_encode($definition).', which is not registered: attach a source object '
                        .'or register the name.',
                    );
                }

                if ($source instanceof DatabaseSource) {
                    $source->bindPanel($panel);
                }

                if ($source instanceof RelationSource) {
                    $source->bind($panel, self::scopeDefinitions($recipe));
                }

                $id = $source->id();
                IdentityCodec::assertSourceLabel($id);

                if (isset($byId[$id])) {
                    if ($source instanceof DatabaseSource && $byId[$id] instanceof DatabaseSource) {
                        throw new WriterConflictException(
                            'Panel "'.$panel.'" has more than one writer (the database source twice): a panel stores grants through one source.',
                        );
                    }

                    throw new DuplicatePermissionException(
                        'Panel "'.$panel.'" has two sources with the id "'.$id.'" ('.$byId[$id]::class.' and '.$source::class
                        .'): every source of a panel needs its own id.',
                    );
                }

                $byId[$id] = $source;
                $origin = $record['origin'];
                $sources[] = [
                    'source' => $source,
                    'origin' => $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'],
                    'plugin' => $origin['plugin'],
                    'name' => $name,
                ];

                if (is_string($name)) {
                    self::bindRuntime($container, $panel, $name);
                }
            }
        }

        return new self($panel, $sources);
    }

    public function panel(): string
    {
        return $this->panel;
    }

    /**
     * @return list<Attached>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Sources that implement the capability.
     *
     * @template TCapability of Source
     *
     * @param  class-string<TCapability>  $capability
     * @return list<array{source: TCapability, origin: string, plugin: ?string, name: ?string, role_origins?: array<class-string, string>}>
     */
    public function with(string $capability): array
    {
        $sources = [];

        foreach ($this->sources as $attached) {
            if ($attached['source'] instanceof $capability) {
                $sources[] = $attached;
            }
        }

        return $sources;
    }

    /**
     * Names and classes in assembly order. An object source has no name.
     *
     * @return list<array{class: class-string<Source>, id: string, origin: string, name?: string}>
     */
    public function identity(): array
    {
        $rows = [];

        foreach ($this->sources as $attached) {
            $row = ['class' => $attached['source']::class, 'id' => $attached['source']->id(), 'origin' => $attached['origin']];

            if ($attached['name'] !== null) {
                $row['name'] = $attached['name'];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<SourceDescription>
     *
     * @throws DefinitionException when `describe()` does not return this source's description
     */
    public function descriptions(Panel $panel): array
    {
        $descriptions = [];

        foreach ($this->sources as $attached) {
            $source = $attached['source'];
            $descriptions[] = $source instanceof DescribesSchema
                ? self::described($panel, $source)
                : new SourceDescription(
                    id: $source->id(),
                    class: $source::class,
                    capabilities: self::capabilities($source),
                    dynamic: $source instanceof ProvidesPermissions && $source->isDynamic(),
                );
        }

        return $descriptions;
    }

    public function writable(): bool
    {
        return $this->with(DatabaseSource::class) !== [];
    }

    /**
     * The writer object of the recipe, or a factory of the named writer for the current scope.
     *
     * @return Closure(): ?DatabaseSource
     */
    public function writer(Container $container): Closure
    {
        $writers = $this->with(DatabaseSource::class);

        if ($writers === []) {
            return static fn (): null => null;
        }

        $attached = $writers[0];

        if ($attached['name'] === null) {
            $source = $attached['source'];

            return static fn () => $source;
        }

        $abstract = self::abstract($this->panel, $attached['name']);

        return static function () use ($container, $abstract): DatabaseSource {
            $source = $container->make($abstract);

            return $source instanceof DatabaseSource ? $source : throw new DefinitionException(
                'The writer binding resolved '.get_debug_type($source).', which is not the database source.',
            );
        };
    }

    /**
     * @return list<class-string<Source>>
     */
    public static function capabilities(Source $source): array
    {
        $capabilities = [];

        foreach (self::CAPABILITIES as $capability) {
            if ($source instanceof $capability) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    private static function described(Panel $panel, DescribesSchema $source): SourceDescription
    {
        $description = $source->describe($panel);

        if ($description->id !== $source->id() || $description->class !== $source::class) {
            throw new DefinitionException(
                'Source "'.$source->id().'" of panel "'.$panel->id().'" described itself as "'.$description->id.'" ('.$description->class
                .'): describe() returns the id and class of the source, without its parameters.',
            );
        }

        return $description;
    }

    private static function bindRuntime(Container $container, string $panel, string $name): void
    {
        $abstract = self::abstract($panel, $name);

        if ($container->bound($abstract)) {
            return;
        }

        $container->scoped($abstract, static fn (Container $container): Source => $container->make(SourceManager::class)->make($name, $panel));
    }

    private static function abstract(string $panel, string $name): string
    {
        return 'azguard.sources.'.$panel.'.'.$name;
    }

    /** @return array<class-string, string> */
    private static function roleOrigins(PanelRecipe $recipe, DiscoverySnapshot $discovery): array
    {
        $origins = $discovery->roleOrigins;

        foreach ($recipe->layered(PanelRecipe::ROLES) as $record) {
            $origin = $record['origin'];
            $label = $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $class) {
                if (is_string($class) && is_subclass_of($class, BaseRole::class)) {
                    $origins[$class][] = $label;
                }
            }
        }

        $owners = [];
        foreach ($origins as $class => $contributions) {
            $distinct = array_values(array_unique($contributions));

            if (count($distinct) > 1) {
                throw new DuplicateRoleException(
                    'Role '.$class.' of panel "'.$recipe->panelId().'" is contributed by '.implode(' and ', $distinct)
                    .': independent origins cannot share a role.',
                );
            }

            $owners[$class] = $distinct[0];
        }

        return $owners;
    }

    /**
     * The queryable scope definitions the declarations of the panel name: the only types a relation source can bind.
     * Folder-discovered scope classes and role bindings do not declare a type.
     *
     * @return list<QueryableAssignmentScopeDefinition>
     */
    private static function scopeDefinitions(PanelRecipe $recipe): array
    {
        $definitions = [];

        foreach ($recipe->layered(PanelRecipe::SCOPES) as $record) {
            $declaration = $record['value'];

            foreach ($declaration instanceof AssignmentScopeDeclaration ? $declaration->definitions : [] as $definition) {
                if ($definition instanceof QueryableAssignmentScopeDefinition) {
                    $definitions[] = $definition;
                }
            }
        }

        return $definitions;
    }
}
