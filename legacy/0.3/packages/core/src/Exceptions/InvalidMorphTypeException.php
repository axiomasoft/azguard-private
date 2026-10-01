<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when az-guard.column_names.morph_type (env AZ_GUARD_MORPH_TYPE) holds a
 * value outside the supported set. Failing loud here prevents silently building
 * integer morph columns for a ULID/UUID host, which only surfaces as a cryptic
 * type error on the first polymorphic query.
 */
final class InvalidMorphTypeException extends AzGuardException
{
    /** @param list<string> $allowed */
    public static function forValue(string $value, array $allowed): self
    {
        return new self(sprintf(
            'Invalid az-guard.column_names.morph_type [%s]. Expected one of: %s. '
            .'Set AZ_GUARD_MORPH_TYPE (or config az-guard.column_names.morph_type) accordingly.',
            $value,
            implode(', ', $allowed),
        ));
    }
}
