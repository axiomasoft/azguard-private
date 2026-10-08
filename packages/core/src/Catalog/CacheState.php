<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

/**
 * How the catalog cache file `azguard:catalog:cache` writes relates to the code that is running.
 *
 * @internal read by the doctor and `php artisan about`
 */
enum CacheState: string
{
    /** There is no readable file: every boot builds the catalogs from the sources. */
    case Missing = 'missing';

    /** The file lacks the current catalog of at least one panel: the code changed after the file was written. */
    case Stale = 'stale';

    /** The file holds the current catalog of every panel. */
    case Current = 'current';
}
