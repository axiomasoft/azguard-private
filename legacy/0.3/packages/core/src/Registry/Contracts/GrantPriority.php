<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

/**
 * Resolution-order values for the built-in grant sources.
 *
 * Higher runs first (so a higher source can short-circuit on a wildcard).
 * This is ordering only, not deny-precedence — see {@see GrantSource::priority()}.
 * Custom sources return a raw int from priority() and may use the gaps
 * (e.g. 85) to slot between these.
 *
 * @api
 */
enum GrantPriority: int
{
    case ClassRole = 100;
    case ContextualRole = 95;
    case DatabaseRole = 90;
    case DirectGrant = 80;
}
