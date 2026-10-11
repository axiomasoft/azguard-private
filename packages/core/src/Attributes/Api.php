<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

/**
 * Publishes a type (or a method) as consumer API: applications call it, and its members, parameter names and documented
 * generics are covered by the compatibility promise from `$since` on. A member marked `@internal` is excluded. A type
 * without `Api` or `Spi` is internal wherever it lives; a type under `\Internal\` never carries either.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
#[Api(since: '1.0')]
final readonly class Api
{
    public function __construct(public string $since) {}
}
