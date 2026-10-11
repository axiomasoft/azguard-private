<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

use Attribute;

/**
 * Publishes a type as an extension contract from `$since` on. `SpiKind::Implement`: applications and extensions
 * implement or extend it, and core calls its methods positionally (I20). `SpiKind::Call`: only extension code uses it,
 * core supplies the implementation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since) {}
}
