<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

final class BypassDeleteAnyCanResource extends CatalogProjectResource
{
    public static function canDeleteAny(): bool
    {
        return true;
    }
}
