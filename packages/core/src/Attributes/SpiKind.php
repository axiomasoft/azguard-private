<?php

declare(strict_types=1);

namespace AzGuard\Attributes;

/**
 * How extension code uses a `Spi` type.
 *
 * @closed
 */
#[Api(since: '1.0')]
enum SpiKind: string
{
    /** Implemented or extended by applications and extensions. */
    case Implement = 'implement';

    /** Used only by extension code; core supplies the implementation. */
    case Call = 'call';
}
