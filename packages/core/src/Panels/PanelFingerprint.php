<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Contracts\Sources\Source;
use Closure;

/**
 * Fingerprint of what a panel is built from: sha256 of canonical JSON of its normalized metadata.
 *
 * Keys are sorted and lists keep their order, so a reordered list is a different panel. A closure is recorded as
 * `closure` and an object by its class: neither closures nor model instances are serialized.
 */
final class PanelFingerprint
{
    public static function of(Panel $panel, PanelRecipe $recipe): string
    {
        return hash('sha256', json_encode(
            self::canonical(self::metadata($panel, $recipe)),
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
            'sources' => array_map(
                static fn (Source|string $source): array => $source instanceof Source
                    ? ['class' => $source::class, 'id' => $source->id()]
                    : ['name' => $source],
                $recipe->sources(),
            ),
            'hooks' => $hooks,
            'resource_scopes' => array_map(
                static fn (mixed $scope): array => is_array($scope)
                    ? ['resource' => self::name($scope['resource'] ?? null), 'resolver' => self::name($scope['resolver'] ?? null)]
                    : [],
                PanelCompiler::items($recipe, PanelRecipe::RESOURCE_SCOPES),
            ),
        ];
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
