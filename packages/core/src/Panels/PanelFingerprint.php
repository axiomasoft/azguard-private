<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Policies\PolicyBinding;
use Closure;
use UnitEnum;

/**
 * Fingerprint of what a panel is built from: sha256 of canonical JSON of its normalized metadata.
 *
 * Keys are sorted and lists keep their order, so a reordered list is a different panel: plugins attached in another
 * order give another fingerprint. A closure is recorded as `closure` and an object by its class: neither closures
 * nor model instances are serialized. The fingerprint of a compiled panel adds its catalog to the fingerprint of the
 * recipe; the recipe part alone decides whether a cached catalog belongs to the panel.
 */
final class PanelFingerprint
{
    /**
     * @param  list<array<string, string>>|null  $sources  resolved names and classes; the recipe is used when null
     * @param  array<string, mixed>|null  $discovery  folder names, roots and file hashes
     */
    public static function of(Panel $panel, PanelRecipe $recipe, ?array $sources = null, ?array $discovery = null): string
    {
        $metadata = self::metadata($panel, $recipe);

        if ($sources !== null) {
            $metadata['sources'] = $sources;
        }

        if ($discovery !== null) {
            $metadata['discovery'] = $discovery;
        }

        return hash('sha256', json_encode(
            self::canonical($metadata),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * The fingerprint of a panel with its catalog: equal for a catalog built from the sources and one read from the cache.
     */
    public static function withCatalog(string $recipe, PanelCatalog $catalog): string
    {
        return hash('sha256', $recipe.json_encode(
            self::canonical($catalog->snapshot()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(Panel $panel, PanelRecipe $recipe): array
    {
        $hooks = [];

        foreach (PanelRecipe::HOOK_LISTS as $list) {
            $hooks[$list] = array_map(self::name(...), PanelCompiler::items($recipe, $list));
        }

        return [
            'id' => $panel->id(),
            'prefix' => $panel->prefix(),
            'default' => $panel->isDefault(),
            'settings' => array_map(
                static fn (array $setting): bool|int|string|null => $setting['value'],
                $panel->settings()->toArray(),
            ),
            'subjects' => $recipe->subjects(),
            'enums' => $recipe->enums(),
            'roles' => $recipe->roles(),
            'discover' => self::discover($recipe),
            'policies' => array_map(self::policy(...), $recipe->items(PanelRecipe::POLICIES)),
            'plugins' => $panel->pluginIds(),
            'sources' => array_map(
                static fn (Source|string $source): array => $source instanceof Source
                    ? ['class' => $source::class, 'id' => $source->id()]
                    : ['name' => $source],
                $recipe->sources(),
            ),
            'hooks' => $hooks,
            'tenants' => [
                'mode' => $panel->tenants()->mode(),
                'definition' => $panel->tenants()->definition() === null ? null : [
                    'type' => $panel->tenants()->definition()->type(),
                    'model' => $panel->tenants()->definition()->model(),
                ],
                'membership' => self::name($panel->tenants()->membership()),
                'global_roles' => $panel->tenants()->globalRoles(),
            ],
            'scopes' => [
                'mode' => $panel->scopes()->mode(),
                'definitions' => array_map(static fn (AssignmentScopeDefinition $definition): array => [
                    'type' => $definition->type(),
                    'class' => $definition::class,
                    'model' => $definition->model(),
                ], $panel->scopeDefinitions()),
                'membership' => self::name($panel->scopes()->membership()),
            ],
            'resource_scopes' => array_map(
                static fn (mixed $scope): array => is_array($scope)
                    ? ['resource' => self::name($scope['resource'] ?? null), 'resolver' => self::name($scope['resolver'] ?? null)]
                    : [],
                PanelCompiler::items($recipe, PanelRecipe::RESOURCE_SCOPES),
            ),
        ];
    }

    /**
     * @return list<array{path: string, namespace: ?string, origin: string}>
     */
    private static function discover(PanelRecipe $recipe): array
    {
        $roots = [];

        foreach ($recipe->layered(PanelRecipe::DISCOVER) as $record) {
            $origin = $record['origin'];
            $label = $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $root) {
                if (! is_array($root) || ! is_string($root['path'] ?? null)) {
                    continue;
                }

                $namespace = $root['namespace'] ?? null;
                $roots[] = [
                    'path' => $root['path'],
                    'namespace' => is_string($namespace) ? $namespace : null,
                    'origin' => $label,
                ];
            }
        }

        return $roots;
    }

    private static function policy(mixed $item): string
    {
        if ($item instanceof PolicyBinding) {
            $permission = $item->permission instanceof UnitEnum
                ? $item->permission::class.'::'.$item->permission->name
                : (string) $item->permission;

            return $permission.'@'.$item->policy.($item->method === null ? '' : '::'.$item->method);
        }

        return self::name($item);
    }

    private static function name(mixed $item): string
    {
        return match (true) {
            $item instanceof Closure => 'closure',
            is_object($item) => $item::class,
            is_string($item) => $item,
            default => get_debug_type($item),
        };
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
