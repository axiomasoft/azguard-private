<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use AzGuard\Registry\Contracts\PermissionCatalog;

/**
 * Catalog ownership: exact membership or a registered dynamic definition.
 * Prefix matching is not ownership.
 *
 * @internal
 */
final class CatalogKeyMatcher
{
    private function __construct() {}

    public static function owns(PermissionCatalog $catalog, string $panelId, string $ability): bool
    {
        if ($catalog->has($panelId, $ability)) {
            return true;
        }

        foreach ($catalog->all($panelId) as $definition) {
            if ($definition->isDynamic() && self::matchesDynamicPattern($ability, $definition->key())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a concrete key (e.g. 'app.team.42.admin') matches a dynamic
     * definition (e.g. 'app.team.{id}.admin'). Each `{seg}` placeholder
     * matches exactly one dotted segment.
     */
    public static function matchesDynamicPattern(string $key, string $pattern): bool
    {
        $keySegments = explode(PermissionKey::SEPARATOR, $key);
        $patternSegments = explode(PermissionKey::SEPARATOR, $pattern);

        if (count($keySegments) !== count($patternSegments)) {
            return false;
        }

        foreach ($patternSegments as $index => $patternSegment) {
            $isPlaceholder = str_starts_with($patternSegment, '{') && str_ends_with($patternSegment, '}');

            if (! $isPlaceholder && $patternSegment !== $keySegments[$index]) {
                return false;
            }
        }

        return true;
    }
}
