<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

/**
 * Canonical mapping between Filament policy abilities (the camelCase method
 * names Filament calls) and AzGuard ability slugs (snake_case permission
 * segments). The bulk *Any variants share the singular permission.
 *
 * Shared by {@see ResourceGate} (runtime enforcement) and
 * {@see PolicyGenerator} (generated policies) so both speak the same dialect.
 */
final class FilamentActions
{
    /** @var array<string, string> */
    public const array MAP = [
        'viewAny' => 'view_any',
        'view' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
        'deleteAny' => 'delete',
        'restore' => 'restore',
        'restoreAny' => 'restore',
        'forceDelete' => 'force_delete',
        'forceDeleteAny' => 'force_delete',
        'replicate' => 'replicate',
        'reorder' => 'reorder',
    ];

    /**
     * Filament policy methods whose ability slug appears in the given set,
     * keyed by method name → slug. Used to generate only the relevant
     * policy methods for a resource's configured abilities.
     *
     * @param  list<string>  $abilities
     * @return array<string, string>
     */
    public static function methodsFor(array $abilities): array
    {
        return array_filter(
            self::MAP,
            static fn (string $slug): bool => in_array($slug, $abilities, true),
        );
    }
}
