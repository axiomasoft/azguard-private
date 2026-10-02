<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Exceptions\PrefixConflictException;
use BackedEnum;
use Closure;

/**
 * Turns a sealed recipe into a panel and checks what only the whole set of panels can tell.
 *
 * A scalar setting is taken from the highest layer that sets it: the panel provider (with its `configure(id)`
 * additions), then plugins, then `configure` for all panels, then the `defaults` of the configuration. Inside a
 * layer the last record wins. Two plugins that set one setting to different values are a conflict unless the
 * provider decides. A list keeps the items of every layer; no layer replaces another one.
 *
 * @phpstan-import-type Record from PanelRecipe
 *
 * @phpstan-type Resolved array{value: mixed, origin: string}
 */
final class PanelCompiler
{
    /**
     * @param  (Closure(): array<string, bool|int|string|null>)|null  $defaults  values of the `defaults` section
     *                                                                           of the configuration by setting
     *                                                                           name, read when a panel is compiled
     */
    public function __construct(private readonly ?Closure $defaults = null) {}

    /**
     * @throws PluginConflictException
     * @throws InvalidConfigurationException
     */
    public function compile(PanelRecipe $recipe): Panel
    {
        $id = $recipe->panelId();
        $label = $this->resolved($recipe, PanelRecipe::LABEL)['value'] ?? null;

        return new Panel(
            id: $id,
            label: is_string($label) ? $label : $id,
            default: ($this->resolved($recipe, PanelRecipe::DEFAULT)['value'] ?? false) === true,
            settings: $this->settings($recipe),
            subjectModels: array_values(array_unique(array_column($recipe->subjects(), 'model'))),
        );
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
     * @param  array<string, Panel>  $panels
     * @return array<string, string> prefix => panel id
     *
     * @throws PrefixConflictException when two panels resolve to the same prefix, the default one included
     */
    public function prefixes(array $panels): array
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

        return $prefixes;
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
