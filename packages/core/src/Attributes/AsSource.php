<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

/**
 * Registers the class under a source name. Discovery of the attribute is separate from this declaration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class AsSource
{
    public function __construct(public string $name) {}
}
