<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

final class BypassRestoreAnyCanResource extends CatalogProjectResource
{
    public static function canRestoreAny(): bool
    {
        return true;
    }
}
