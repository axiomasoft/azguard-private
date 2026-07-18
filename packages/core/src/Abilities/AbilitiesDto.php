<?php

declare(strict_types=1);

namespace AzGuard\Abilities;

/**
 * @phpstan-consistent-constructor
 */
abstract readonly class AbilitiesDto
{
    use ResolvesGateAbilities;

    /**
     * @return array<string, string>
     */
    abstract protected static function abilityMap(): array;

    /**
     * @param  array<int, mixed>  $arguments
     * @return array<string, bool>
     */
    protected static function resolveFlags(array $arguments = []): array
    {
        return static::resolveGateFlags(
            abilityMap: static::abilityMap(),
            arguments: $arguments,
        );
    }

    public static function make(mixed ...$arguments): static
    {
        $flags = static::resolveFlags(arguments: $arguments);

        return new static(...$flags);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return array_filter(get_object_vars(object: $this), is_bool(...));
    }
}
