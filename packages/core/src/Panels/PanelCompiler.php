<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Exceptions\PluginDependencyMissingException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Plugins\PluginContext;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Sources\PanelSources;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use ReflectionMethod;

/**
 * Registers the plugins of a panel, turns its sealed recipe into a panel and checks what only the whole set of
 * panels can tell.
 *
 * A scalar setting is taken from the highest layer that sets it: the panel provider (with its `configure(id)`
 * additions), then plugins, then `configure` for all panels, then the `defaults` of the configuration. Inside a
 * layer the last record wins. Two plugins that set one setting to different values are a conflict unless the
 * provider decides. A list keeps the items of every layer; no layer replaces another one.
 *
 * @phpstan-import-type Record from PanelRecipe
 *
 * @phpstan-type Resolved array{value: mixed, origin: string}
 * @phpstan-type Attached array{plugin: Plugin, context: PluginContext}
 */
final class PanelCompiler
{
    private const string PLUGIN_ID = '/\A[a-z0-9][a-z0-9_.-]*(\/[a-z0-9][a-z0-9_.-]*)?\z/';

    private const int PLUGIN_ID_BYTES = 128;

    /**
     * @param  (Closure(): array<string, bool|int|string|null>)|null  $defaults  values of the `defaults` section
     *                                                                           of the configuration by setting
     *                                                                           name, read when a panel is compiled
     */
    public function __construct(private readonly ?Closure $defaults = null) {}

    /**
     * Runs `register()` of every plugin of the panel and seals the recipe.
     *
     * Plugins of the panel provider register first, then the ones `configure` for all panels attaches unless the
     * panel keeps them off, then the ones a plugin attached while it registered, until no new plugin appears. A
     * plugin registers on its own copy, so what it keeps while registering on one panel is not seen on another.
     *
     * @return list<Attached> plugins of the panel in the order they were attached
     *
     * @throws DefinitionException when a plugin cannot be created or its id is malformed
     * @throws PluginConflictException when two plugins of the panel share an id
     * @throws PluginDependencyMissingException
     */
    public function register(PanelRecipe $recipe, PanelBuilder $builder, Container $container, string $buildId): array
    {
        $panel = $recipe->panelId();
        $detached = $recipe->withoutPlugins();
        $queue = [
            ...array_map(static fn (Plugin|string $plugin): array => [$plugin, false], $recipe->plugins(PanelRecipe::PROVIDER)),
            ...array_map(static fn (Plugin|string $plugin): array => [$plugin, true], $recipe->plugins(PanelRecipe::CONFIGURE)),
        ];
        $attached = [];
        $nested = 0;

        while ($queue !== []) {
            foreach ($queue as [$declared, $detachable]) {
                $plugin = $this->instance($panel, $declared, $container);
                $id = $this->pluginId($panel, $plugin);

                if ($detachable && in_array($id, $detached, true)) {
                    continue;
                }

                if (isset($attached[$id])) {
                    throw new PluginConflictException(
                        'Panel "'.$panel.'" has two plugins with the id "'.$id.'" ('.$attached[$id]['plugin']::class.' and '
                        .$plugin::class.'): attach the plugin once.',
                    );
                }

                $context = new PluginContext($panel, $id, $buildId, $this->dependencies($panel, $id, $plugin));
                $attached[$id] = ['plugin' => $plugin, 'context' => $context];

                $recipe->during($recipe->attach($id), static fn () => $plugin->register($builder, $context));
            }

            $byPlugins = $recipe->plugins(PanelRecipe::PLUGIN);
            $queue = array_map(static fn (Plugin|string $plugin): array => [$plugin, false], array_slice($byPlugins, $nested));
            $nested = count($byPlugins);
        }

        $recipe->seal();

        foreach ($attached as $id => ['context' => $context]) {
            foreach ($context->dependencies() as $dependency) {
                if (! isset($attached[$dependency])) {
                    throw new PluginDependencyMissingException(
                        'Plugin "'.$id.'" of panel "'.$panel.'" requires the plugin "'.$dependency
                        .'", which is not attached to the panel: add it to plugins([...]).',
                    );
                }
            }
        }

        return array_values($attached);
    }

    /**
     * Runs `boot()` of the plugins of a compiled panel in the order they were attached.
     *
     * @param  list<Attached>  $plugins
     */
    public function boot(Panel $panel, array $plugins): void
    {
        foreach ($plugins as ['plugin' => $plugin, 'context' => $context]) {
            $plugin->boot($panel, $context);
        }
    }

    /**
     * @throws PluginConflictException
     * @throws InvalidConfigurationException
     */
    public function compile(PanelRecipe $recipe, ?PanelSources $sources = null, ?Container $container = null): Panel
    {
        $id = $recipe->panelId();
        $label = $this->resolved($recipe, PanelRecipe::LABEL)['value'] ?? null;

        return new Panel(
            id: $id,
            label: is_string($label) ? $label : $id,
            default: ($this->resolved($recipe, PanelRecipe::DEFAULT)['value'] ?? false) === true,
            settings: $this->settings($recipe),
            subjectModels: array_values(array_unique(array_column($recipe->subjects(), 'model'))),
            pluginIds: $recipe->pluginIds(),
            resolved: $sources,
            writable: $sources !== null && $sources->writable(),
            writer: $sources !== null && $container !== null ? $sources->writer($container) : null,
            grantFields: $this->fields($recipe),
            beforeHooks: $this->callbacks($recipe, PanelRecipe::BEFORE),
            afterHooks: $this->callbacks($recipe, PanelRecipe::AFTER),
            accessRestrictions: $this->components($recipe, PanelRecipe::RESTRICTIONS, Restriction::class),
            conditions: $this->components($recipe, PanelRecipe::GRANT_CONDITIONS, GrantCondition::class),
        );
    }

    /** @return list<Closure|class-string> */
    private function callbacks(PanelRecipe $recipe, string $setting): array
    {
        $callbacks = [];
        foreach ($recipe->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                if (! $item instanceof Closure && (! is_string($item) || ! class_exists($item) || ! method_exists($item, '__invoke')
                    || ! (new ReflectionMethod($item, '__invoke'))->isPublic())) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' expects Closure or invokable class in '.$setting.' from '.json_encode($record['origin']).'.');
                }
                $callbacks[] = $item;
            }
        }

        return $callbacks;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return list<T|class-string<T>>
     */
    private function components(PanelRecipe $recipe, string $setting, string $contract): array
    {
        $components = [];
        foreach ($recipe->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                if (! $item instanceof $contract && (! is_string($item) || ! is_subclass_of($item, $contract))) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' expects '.$contract.' in '.$setting.' from '.json_encode($record['origin']).'.');
                }
                $components[] = $item;
            }
        }

        return $components;
    }

    /** @return array<string, list<Field>> */
    private function fields(PanelRecipe $recipe): array
    {
        $fields = [];
        foreach (FieldTarget::cases() as $target) {
            $byName = [];
            foreach ($recipe->layered(PanelRecipe::FIELDS.'.'.$target->value) as $record) {
                $origin = $record['origin']['kind'];

                if ($record['origin']['plugin'] !== null) {
                    $origin .= ':'.$record['origin']['plugin'];
                }
                foreach (is_array($record['value']) ? $record['value'] : [] as $field) {
                    if (! $field instanceof Field) {
                        throw new DefinitionException('Panel '.$recipe->panelId().' expects Field objects.');
                    }
                    $name = $field->name();

                    if (isset($byName[$name])) {
                        throw new DefinitionException('Panel '.$recipe->panelId().' has duplicate '.$target->value.' field '.$name.' from '
                            .$byName[$name]->contributedBy().' and '.$origin.'.');
                    }
                    $byName[$name] = $field->withContribution($origin);
                }
            }
            $fields[$target->value] = array_values($byName);
        }

        return $fields;
    }

    /**
     * Builds the static catalog of a compiled panel from the sources of its recipe.
     *
     * @throws DefinitionException
     */
    public function catalog(Panel $panel, PanelRecipe $recipe, Container $container): PanelCatalog
    {
        return PanelCatalog::build($panel, PanelSources::of($recipe, $container), $container);
    }

    /**
     * @throws PluginConflictException
     * @throws InvalidConfigurationException when a value is outside its enum or range, in every environment
     */
    public function settings(PanelRecipe $recipe): PanelSettings
    {
        $defaults = [...PanelSettings::DEFAULTS, ...($this->defaults === null ? [] : ($this->defaults)())];
        $values = $origins = [];

        foreach (array_keys(PanelSettings::DEFAULTS) as $setting) {
            $resolved = $this->resolved($recipe, $setting);
            $values[$setting] = $resolved === null ? $defaults[$setting] : $resolved['value'];
            $origins[$setting] = $resolved === null ? 'default' : $resolved['origin'];
        }

        $panel = $recipe->panelId();
        $prefix = $values[PanelSettings::RESOURCE_PREFIX];
        $store = $values[PanelSettings::CACHE_STORE];
        $ttl = $values[PanelSettings::CACHE_TTL];
        $generation = $values[PanelSettings::CACHE_GENERATION];

        if ((! is_int($ttl) && $ttl !== null) || (is_int($ttl) && $ttl < 1)) {
            throw self::invalid('enum', $panel, PanelSettings::CACHE_TTL, $ttl, 'a positive number of seconds or null');
        }

        if (! is_int($generation) || $generation < 1) {
            throw self::invalid('enum', $panel, PanelSettings::CACHE_GENERATION, $generation, 'a positive integer');
        }

        if ($store !== null && $ttl === null) {
            throw InvalidConfigurationException::failing(
                'cache_ttl',
                'Panel "'.$panel.'" caches permission sets in the store '.self::describe($store).' without a ttl: set cache.ttl.',
            );
        }

        return new PanelSettings(
            resourcePrefix: match (true) {
                is_string($prefix) => $prefix,
                $prefix === false => null,
                default => $panel,
            },
            gateMode: self::enum(GateMode::class, $panel, PanelSettings::GATE_MODE, $values[PanelSettings::GATE_MODE]),
            cacheStore: is_string($store) ? $store : null,
            cacheTtl: $ttl,
            cacheGeneration: $generation,
            reads: self::enum(Reads::class, $panel, PanelSettings::READS, $values[PanelSettings::READS]),
            stateRefresh: self::enum(StateRefresh::class, $panel, PanelSettings::STATE_REFRESH, $values[PanelSettings::STATE_REFRESH]),
            traceDecisions: $values[PanelSettings::TRACE_DECISIONS] === true,
            origins: $origins,
        );
    }

    /**
     * Presentation options merged by top-level key with the precedence of scalar settings.
     *
     * @return array<string, mixed>
     *
     * @throws PluginConflictException
     */
    public function presentation(PanelRecipe $recipe): array
    {
        $byKey = [];

        foreach ($recipe->layered(PanelRecipe::PRESENTATION) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $key => $value) {
                $byKey[(string) $key][] = ['setting' => $record['setting'], 'value' => $value, 'origin' => $record['origin']];
            }
        }

        $options = [];

        foreach ($byKey as $key => $records) {
            $options[$key] = $this->pick($recipe->panelId(), 'presentation.'.$key, $records)['value'];
        }

        return $options;
    }

    /**
     * Items of a list setting from every layer; a class named twice is kept once, at its first position.
     *
     * @return list<mixed>
     */
    public static function items(PanelRecipe $recipe, string $setting): array
    {
        $items = $seen = [];

        foreach ($recipe->items($setting) as $item) {
            if (is_string($item)) {
                if (isset($seen[$item])) {
                    continue;
                }

                $seen[$item] = true;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, Panel>  $panels
     *
     * @throws DefaultPanelConflictException when two default panels share a subject model
     */
    public function assertDefaults(array $panels): void
    {
        $defaults = array_values(array_filter($panels, static fn (Panel $panel): bool => $panel->isDefault()));

        foreach ($defaults as $position => $first) {
            foreach (array_slice($defaults, $position + 1) as $second) {
                $shared = $this->sharedModel($first, $second);

                if ($shared !== null) {
                    throw new DefaultPanelConflictException(
                        'Panels "'.$first->id().'" and "'.$second->id().'" are both the default panel of '.$shared
                        .': keep default() on one of them.',
                    );
                }
            }
        }
    }

    /**
     * The prefix dictionary of the application: a prefix is one segment that names exactly one panel.
     *
     * A prefix never equals the first segment of a static permission name of any panel, plugin contributions included:
     * such a name would read as a name with the prefix.
     *
     * @param  array<string, Panel>  $panels
     * @param  array<string, PanelCatalog>  $catalogs
     * @return array<string, string> prefix => panel id
     *
     * @throws PrefixConflictException when two panels resolve to the same prefix, the default one included, or a
     *                                 prefix is the first segment of a permission name
     */
    public function prefixes(array $panels, array $catalogs = []): array
    {
        $prefixes = [];

        foreach ($panels as $panel) {
            $prefix = $panel->prefix();

            if ($prefix === null) {
                continue;
            }

            if (isset($prefixes[$prefix])) {
                throw new PrefixConflictException(
                    'Panels "'.$prefixes[$prefix].'" and "'.$panel->id().'" both use the permission prefix "'.$prefix
                    .'": give one of them another resourcePrefix() or turn it off with resourcePrefix(false).',
                );
            }

            $prefixes[$prefix] = $panel->id();
        }

        foreach ($catalogs as $catalog) {
            foreach ($prefixes as $prefix => $owner) {
                $name = $catalog->nameWithFirstSegment($prefix);

                if ($name !== null) {
                    throw new PrefixConflictException(
                        'The permission prefix "'.$prefix.'" of panel "'.$owner.'" is the first segment of the permission "'.$name
                        .'" of panel "'.$catalog->panel().'": give the panel another resourcePrefix() or rename the permission.',
                    );
                }
            }
        }

        return $prefixes;
    }

    /**
     * The copy of a plugin one panel works with.
     *
     * @param  Plugin|class-string<Plugin>  $declared
     *
     * @throws DefinitionException when the container gives something that is not a plugin
     */
    private function instance(string $panel, Plugin|string $declared, Container $container): Plugin
    {
        if ($declared instanceof Plugin) {
            return clone $declared;
        }

        $plugin = $container->make($declared);

        if (! $plugin instanceof Plugin) {
            throw new DefinitionException(
                'Panel "'.$panel.'": the container resolved '.$declared.' to '.get_debug_type($plugin).', which is not a plugin.',
            );
        }

        return clone $plugin;
    }

    /**
     * @throws DefinitionException
     */
    private function pluginId(string $panel, Plugin $plugin): string
    {
        $id = $plugin->id();

        if (strlen($id) > self::PLUGIN_ID_BYTES || preg_match(self::PLUGIN_ID, $id) !== 1) {
            throw new DefinitionException(
                'Panel "'.$panel.'": '.$plugin::class.' has the plugin id '.self::describe($id).'; an id is "name" or "vendor/name" of '
                .'lowercase letters, digits, ".", "_" and "-", at most '.self::PLUGIN_ID_BYTES.' bytes.',
            );
        }

        return $id;
    }

    /**
     * @return list<string>
     *
     * @throws DefinitionException
     */
    private function dependencies(string $panel, string $id, Plugin $plugin): array
    {
        $dependencies = [];

        foreach ($plugin instanceof DependsOnPlugins ? self::declared($plugin->requires()) : [] as $dependency) {
            if (! is_string($dependency) || $dependency === '') {
                throw new DefinitionException(
                    'Plugin "'.$id.'" of panel "'.$panel.'": requires() must list plugin ids, got '.get_debug_type($dependency).'.',
                );
            }

            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * What a plugin returns is checked as it arrives: the documented type is a promise of the plugin, not a fact.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function declared(array $values): array
    {
        return $values;
    }

    /**
     * The effective value of a scalar setting and its origin, or null when no layer of the recipe sets it.
     *
     * @return Resolved|null
     *
     * @throws PluginConflictException
     */
    private function resolved(PanelRecipe $recipe, string $setting): ?array
    {
        $records = $recipe->layered($setting);

        return $records === [] ? null : $this->pick($recipe->panelId(), $setting, $records);
    }

    /**
     * @param  non-empty-list<Record>  $records  records of one setting in layer order
     * @return Resolved
     *
     * @throws PluginConflictException
     */
    private function pick(string $panel, string $setting, array $records): array
    {
        $layers = [PanelRecipe::PROVIDER => [], PanelRecipe::PLUGIN => [], PanelRecipe::CONFIGURE => []];

        foreach ($records as $record) {
            $layers[$record['origin']['kind']][] = $record;
        }

        if ($layers[PanelRecipe::PROVIDER] !== []) {
            return ['value' => end($layers[PanelRecipe::PROVIDER])['value'], 'origin' => PanelRecipe::PROVIDER];
        }

        if ($layers[PanelRecipe::PLUGIN] === []) {
            return ['value' => end($layers[PanelRecipe::CONFIGURE])['value'] ?? null, 'origin' => PanelRecipe::CONFIGURE];
        }

        $byPlugin = [];

        foreach ($layers[PanelRecipe::PLUGIN] as $record) {
            $byPlugin[(string) $record['origin']['plugin']] = $record['value'];
        }

        $first = array_key_first($byPlugin);

        foreach ($byPlugin as $plugin => $value) {
            if ($value !== $byPlugin[$first]) {
                throw new PluginConflictException(
                    'Plugins "'.$first.'" and "'.$plugin.'" of panel "'.$panel.'" set "'.$setting.'" to different values ('
                    .self::describe($byPlugin[$first]).' and '.self::describe($value).'): set it in the panel provider.',
                );
            }
        }

        return ['value' => $byPlugin[$first], 'origin' => PanelRecipe::PLUGIN.':'.$first];
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     *
     * @throws InvalidConfigurationException
     */
    private static function enum(string $enum, string $panel, string $setting, mixed $value): BackedEnum
    {
        $case = match (true) {
            $value instanceof $enum => $value,
            is_string($value) => $enum::tryFrom($value),
            default => null,
        };

        return $case ?? throw self::invalid(
            'enum',
            $panel,
            $setting,
            $value,
            'one of '.implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
        );
    }

    private static function invalid(string $check, string $panel, string $setting, mixed $value, string $expected): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing(
            $check,
            'Panel "'.$panel.'": setting "'.$setting.'" is '.self::describe($value).', expected '.$expected.'.',
        );
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) || $value === null => (string) json_encode($value),
            default => get_debug_type($value),
        };
    }

    private function sharedModel(Panel $first, Panel $second): ?string
    {
        foreach ($first->subjectModels() as $model) {
            foreach ($second->subjectModels() as $other) {
                if (is_a($model, $other, true) || is_a($other, $model, true)) {
                    return is_a($model, $other, true) ? $model : $other;
                }
            }
        }

        return null;
    }
}
