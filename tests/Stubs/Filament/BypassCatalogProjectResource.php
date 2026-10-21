<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

final class BypassCatalogProjectResource extends CatalogProjectResource
{
    public static function canViewAny(): bool
    {
        return true;
    }
}
