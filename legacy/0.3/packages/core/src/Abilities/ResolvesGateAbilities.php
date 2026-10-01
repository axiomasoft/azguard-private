<?php

declare(strict_types=1);

namespace AzGuard\Abilities;

use Illuminate\Support\Facades\Gate;

trait ResolvesGateAbilities
{
    /**
     * @param  array<string, string>  $abilityMap
     * @param  array<int, mixed>  $arguments
     * @return array<string, bool>
     */
    protected static function resolveGateFlags(array $abilityMap, array $arguments): array
    {
        $resolved = [];

        foreach ($abilityMap as $key => $ability) {
            $resolved[$key] = Gate::allows(
                ability: $ability,
                arguments: $arguments,
            );
        }

        return $resolved;
    }
}
