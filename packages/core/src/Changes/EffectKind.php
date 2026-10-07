<?php

declare(strict_types=1);

namespace AzGuard\Changes;

/**
 * What the writer did to one stored grant or dynamic permission.
 *
 * @api
 */
enum EffectKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
