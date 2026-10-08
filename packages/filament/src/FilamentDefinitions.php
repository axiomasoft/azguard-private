<?php

declare(strict_types=1);

namespace AzGuard\Filament;

/**
 * Where the permissions of the resources, pages and widgets of a Filament panel are described.
 *
 * It names the source of the definitions only; who decides a permission is the authority.
 *
 * @api
 */
enum FilamentDefinitions: string
{
    /** Permission enums of the guard panel folder. */
    case Enums = 'enums';

    /** Definitions read from the Filament classes by `FilamentSource`. */
    case Resources = 'resources';
}
