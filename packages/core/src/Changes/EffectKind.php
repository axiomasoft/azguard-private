<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Attributes\Api;

/**
 * What the writer did to one stored grant or dynamic permission.
 *
 * @closed
 */
#[Api(since: '1.0')]
enum EffectKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
