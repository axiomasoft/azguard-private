<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Attributes\Api;

/**
 * What the writer did to one stored grant or dynamic permission.
 *
 * @closed
 */
#[Api(since: '0.7')]
enum EffectKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
